<?php

declare(strict_types=1);

/** Reconciles Stripe Checkout sessions without releasing inventory before the provider settles. */
final class CheckoutReconciliationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly StripeGateway $stripe,
        private readonly PaymentOrderLock $orderLock,
        private readonly Closure $applyCheckoutEvent,
        private readonly Closure $cancelUnpaidOrder,
        private readonly ?Closure $retrieveCheckoutSession = null,
        private readonly ?Closure $retrievePaymentIntent = null
    ) {
    }

    public function reconcilePendingSessions(int $limit): int
    {
        $query = $this->pdo->prepare(
            "SELECT id FROM orders
             WHERE payment_provider = 'stripe' AND payment_session_id IS NOT NULL
               AND payment_status = 'pending'
               AND (payment_reconciled_at IS NULL OR payment_reconciled_at < CURRENT_TIMESTAMP - INTERVAL 5 MINUTE)
             ORDER BY COALESCE(payment_reconciled_at, '1970-01-01'), created_at
             LIMIT :limit"
        );
        $query->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $query->execute();
        $ids = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));

        $processed = 0;
        foreach ($ids as $orderId) {
            try {
                $this->orderLock->acquire($orderId);
                try {
                    $mark = $this->pdo->prepare(
                        "UPDATE orders SET payment_reconciled_at = CURRENT_TIMESTAMP
                         WHERE id = :id AND payment_provider = 'stripe' AND payment_status = 'pending'
                           AND (payment_reconciled_at IS NULL OR payment_reconciled_at < CURRENT_TIMESTAMP - INTERVAL 5 MINUTE)"
                    );
                    $mark->execute([':id' => $orderId]);
                    if ($mark->rowCount() !== 1) continue;

                    $find = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
                    $find->execute([':id' => $orderId]);
                    $order = $find->fetch();
                    if (!$order || !is_string($order['payment_session_id'] ?? null)) continue;

                    $sessionId = (string) $order['payment_session_id'];
                    $session = $this->retrieveCheckoutSession !== null
                        ? ($this->retrieveCheckoutSession)($sessionId)
                        : $this->stripe->retrieveCheckoutSession($sessionId);
                    if (!PaymentPolicy::stripeObjectMatchesOrder($session, $order)) {
                        throw new RuntimeException('Stripe session mode does not match the local order.');
                    }
                    if (strtolower((string) ($session['currency'] ?? '')) !== strtolower((string) $order['currency'])
                        || (int) ($session['amount_total'] ?? -1) !== $this->cents((string) $order['total'])) {
                        throw new RuntimeException('Stripe session amount or currency does not match the local order.');
                    }

                    if (($session['payment_status'] ?? '') === 'paid') {
                        $this->applyPaidSession($session);
                    } elseif (($session['status'] ?? '') === 'expired') {
                        ($this->cancelUnpaidOrder)($orderId, 'failed');
                    } elseif (($session['status'] ?? '') === 'complete') {
                        $this->reconcileCompletedCheckout($orderId, $order, $session, $sessionId);
                    }
                    // Open or asynchronously processing sessions remain pending until a later poll or provider event.
                    $processed++;
                } finally {
                    $this->orderLock->release($orderId);
                }
            } catch (Throwable $exception) {
                error_log('Payment-session reconciliation failed for order ' . $orderId . ': ' . $exception->getMessage());
            }
        }
        return $processed;
    }

    private function reconcileCompletedCheckout(int $orderId, array $order, array $session, string $sessionId): void
    {
        $paymentIntent = $session['payment_intent'] ?? null;
        $paymentIntentId = is_array($paymentIntent)
            ? (string) ($paymentIntent['id'] ?? '')
            : (is_string($paymentIntent) ? $paymentIntent : '');
        if ($paymentIntentId === '') {
            $this->preservePendingStripeReservation($orderId, $sessionId);
            return;
        }

        $intent = $this->retrievePaymentIntent !== null
            ? ($this->retrievePaymentIntent)($paymentIntentId)
            : $this->stripe->retrievePaymentIntent($paymentIntentId);
        if (!PaymentPolicy::stripeObjectMatchesOrder($intent, $order)) {
            throw new RuntimeException('Stripe PaymentIntent mode does not match the local order.');
        }
        $metadata = $intent['metadata'] ?? null;
        if (($intent['id'] ?? null) !== $paymentIntentId
            || strtolower((string) ($intent['currency'] ?? '')) !== strtolower((string) $order['currency'])
            || (int) ($intent['amount'] ?? -1) !== $this->cents((string) $order['total'])
            || !is_array($metadata)
            || !is_string($metadata['order_number'] ?? null)
            || !hash_equals((string) $order['order_number'], $metadata['order_number'])) {
            throw new RuntimeException('Stripe PaymentIntent does not match the order amount, currency or reference.');
        }

        $intentStatus = (string) ($intent['status'] ?? '');
        if ($intentStatus === 'succeeded') {
            $this->applyPaidSession([...$session, 'payment_status' => 'paid', 'payment_intent' => $paymentIntentId]);
            return;
        }
        if (in_array($intentStatus, ['canceled', 'requires_payment_method'], true)) {
            ($this->cancelUnpaidOrder)($orderId, 'failed');
            return;
        }

        // Delayed methods (notably SEPA) can complete Checkout before the debit settles.
        // Do not auto-expire the hold while Stripe can still collect the funds.
        $this->preservePendingStripeReservation($orderId, $sessionId);
    }

    private function applyPaidSession(array $session): void
    {
        $this->pdo->beginTransaction();
        try {
            ($this->applyCheckoutEvent)($session, true);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function preservePendingStripeReservation(int $orderId, string $sessionId): void
    {
        $preserve = $this->pdo->prepare(
            "UPDATE orders SET reservation_expires_at = NULL
             WHERE id = :id AND payment_provider = 'stripe' AND payment_session_id = :sessionId
               AND status = 'awaiting_payment' AND payment_status = 'pending'"
        );
        $preserve->execute([':id' => $orderId, ':sessionId' => $sessionId]);
    }

    private function cents(string $amount): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new RuntimeException('An order has an invalid stored amount.');
        }
        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}

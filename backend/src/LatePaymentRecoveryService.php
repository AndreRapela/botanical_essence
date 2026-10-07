<?php

declare(strict_types=1);

/** Records late payments and queues compensation atomically, without provider I/O. */
final class LatePaymentRecoveryService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function orderForCheckoutSession(array $session, string $sessionId): ?array
    {
        $bySession = $this->pdo->prepare('SELECT * FROM orders WHERE payment_session_id = :sessionId LIMIT 1 FOR UPDATE');
        $bySession->execute([':sessionId' => $sessionId]);
        $order = $bySession->fetch();
        if ($order) {
            return $order;
        }

        // Recover sessions created by an older server instance that lost the
        // attach-vs-cancel race. Stripe signs these identifiers in its event.
        $orderNumber = (string) ($session['client_reference_id'] ?? $session['metadata']['order_number'] ?? '');
        if ($orderNumber === '') {
            return null;
        }
        $byNumber = $this->pdo->prepare('SELECT * FROM orders WHERE order_number = :number LIMIT 1 FOR UPDATE');
        $byNumber->execute([':number' => $orderNumber]);
        $candidate = $byNumber->fetch();
        if (!$candidate || ($candidate['payment_session_id'] ?? null) !== null
            || !in_array($candidate['payment_provider'], [null, 'stripe'], true)) {
            return null;
        }

        $attach = $this->pdo->prepare(
            "UPDATE orders SET payment_provider = 'stripe', payment_session_id = :sessionId,
                    test_payment = :testPayment
             WHERE id = :id AND payment_session_id IS NULL AND (payment_provider IS NULL OR payment_provider = 'stripe')"
        );
        $attach->execute([
            ':sessionId' => $sessionId,
            ':testPayment' => empty($session['livemode']) ? 1 : 0,
            ':id' => $candidate['id'],
        ]);

        $bySession->execute([':sessionId' => $sessionId]);
        $order = $bySession->fetch();
        return $order ?: null;
    }


    /** Queue only: the ledger, job and webhook marker must commit before any PSP I/O. */
    public function refundCancelledOrderPayment(array $order, array $session, string $sessionId): void
    {
        $intent = $session['payment_intent'] ?? null;
        $intentId = is_array($intent) ? ($intent['id'] ?? null) : $intent;
        if (!is_string($intentId) || !preg_match('/^pi_[A-Za-z0-9_]{1,124}$/D', $intentId)) {
            throw new HttpException('O pagamento tardio não tem uma referência Stripe válida.', 409, [], 'late_payment_missing_intent');
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $find = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
            $find->execute([':id' => $order['id']]);
            $current = $find->fetch();
            if (!$current || $current['status'] !== 'cancelled' || $current['payment_session_id'] !== $sessionId) {
                if ($ownsTransaction) $this->pdo->commit();
                return;
            }
            if (!PaymentPolicy::stripeObjectMatchesOrder($session, $current)
                || strtolower((string) ($session['currency'] ?? '')) !== strtolower($current['currency'])
                || ($session['amount_total'] ?? null) !== $this->cents((string) $current['total'])
                || ($current['payment_intent_id'] !== null && $current['payment_intent_id'] !== $intentId)) {
                throw new HttpException('O pagamento tardio não corresponde ao pedido cancelado.', 409, [], 'late_payment_mismatch');
            }
            // Duplicate events, manual resolutions and definitive failures never create a new attempt.
            // Pre-ledger attempts stay available to the existing reconciliation/admin flow.
            if ((int) $current['payment_refund_attempt'] !== 0
                || !in_array($current['payment_status'], ['pending', 'failed', 'paid'], true)) {
                if ($ownsTransaction) $this->pdo->commit();
                return;
            }
            $amount = $this->cents((string) $current['total']) - (int) $current['payment_refunded_cents'];
            if ($amount <= 0) throw new HttpException('O pagamento tardio exige revisão contábil.', 409, [], 'late_payment_mismatch');
            $this->pdo->prepare("UPDATE orders SET payment_status = 'refund_pending', payment_intent_id = :intent,
                payment_refund_attempt = 1, payment_refund_id = NULL, payment_refund_outcome = 'pending',
                payment_refund_error_code = NULL, payment_refund_error_message = NULL,
                payment_refund_error_http_status = NULL, reservation_expires_at = NULL WHERE id = :id")
                ->execute([':intent' => $intentId, ':id' => $current['id']]);
            $this->pdo->prepare("INSERT INTO payment_refund_attempts
                (order_id, attempt, attempt_kind, amount_cents, currency, previous_payment_status,
                 previous_order_status, status, reason)
                VALUES (:id, 1, 'full', :amount, :currency, :previous, 'cancelled', 'pending',
                        'Automatic compensation of payment received after cancellation')")
                ->execute([':id' => $current['id'], ':amount' => $amount, ':currency' => $current['currency'], ':previous' => $current['payment_status']]);
            $this->pdo->prepare('INSERT INTO payment_refund_dispatches (order_id, attempt, request_hash) VALUES (:id, 1, :hash)')
                ->execute([':id' => $current['id'], ':hash' => self::requestHash([
                    ...$current, 'payment_intent_id' => $intentId, 'attempt' => 1, 'amount_cents' => $amount,
                ])]);
            $this->pdo->prepare("INSERT INTO admin_audit_log (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                VALUES (NULL, 'order.late_payment_refund_queued', 'order', :id, :previous, :new)")
                ->execute([':id' => $current['id'], ':previous' => 'cancelled:' . $current['payment_status'],
                    ':new' => 'cancelled:refund_pending; attempt=1; amount=' . $amount]);
            if ($ownsTransaction) $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** Fingerprint the fixed request and financial identity before it is ever sent. */
    public static function requestHash(array $row): string
    {
        return hash('sha256', json_encode([
            (string) $row['order_number'], (string) $row['payment_intent_id'], (int) $row['attempt'],
            (int) $row['amount_cents'], strtoupper($row['currency']), (bool) $row['test_payment'],
        ], JSON_THROW_ON_ERROR));
    }

    public function markRefunded(int $orderId, int $attempt, string $paymentIntentId, string $refundId, ?int $adminId): void
    {
        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $select = $this->pdo->prepare(
                'SELECT status, payment_status, payment_intent_id, payment_refund_attempt
                 FROM orders WHERE id = :id FOR UPDATE'
            );
            $select->execute([':id' => $orderId]);
            $order = $select->fetch();
            if ($order && $order['status'] === 'cancelled' && $order['payment_status'] === 'refund_pending'
                && (int) $order['payment_refund_attempt'] === $attempt
                && hash_equals((string) $order['payment_intent_id'], $paymentIntentId)) {
                $update = $this->pdo->prepare(
                    "UPDATE orders SET payment_status = 'refunded', payment_refunded_cents = ROUND(total * 100), payment_refund_id = :refundId,
                            payment_refund_outcome = 'succeeded', payment_refund_error_code = NULL,
                            payment_refund_error_message = NULL, payment_refund_error_http_status = NULL
                     WHERE id = :id AND status = 'cancelled' AND payment_status = 'refund_pending'
                           AND payment_refund_attempt = :attempt"
                );
                $update->execute([':refundId' => $refundId, ':id' => $orderId, ':attempt' => $attempt]);
                if ($update->rowCount() === 1) {
                    $queue = $this->pdo->prepare("INSERT IGNORE INTO payment_notification_outbox (order_id, notification_type, reference_id) VALUES (:orderId, 'full_refund_confirmation', :reference)");
                    $queue->execute([':orderId' => $orderId, ':reference' => $refundId !== '' ? $refundId : ('refund-' . $attempt)]);
                }
                $audit = $this->pdo->prepare(
                    "INSERT INTO admin_audit_log
                        (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                     VALUES (:adminId, 'order.late_payment_refunded', 'order', :orderId,
                             'cancelled:refund_pending', 'cancelled:refunded')"
                );
                $audit->execute([':adminId' => $adminId, ':orderId' => $orderId]);
            }
            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function cents(string $amount): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new RuntimeException('The stored order total has an invalid format.');
        }
        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}

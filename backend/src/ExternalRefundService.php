<?php

declare(strict_types=1);

require_once __DIR__ . '/ExternalRefundAdminActions.php';

/** Provider-only refunds are financial evidence, never permission to send another refund. */
final class ExternalRefundService
{
    use ExternalRefundAdminActions;
    private readonly Closure $applyRefund;

    public function __construct(
        private readonly PDO $pdo,
        private readonly StripeGateway $stripe,
        private readonly RateLimiter $limiter,
        private readonly PaymentOrderLock $orderLock,
        callable $applyRefund
    ) {
        $this->applyRefund = Closure::fromCallable($applyRefund);
    }

    public function capture(array $refund, bool $authoritative = false): void
    {
        $identity = $this->identity($refund);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $order = $this->findOrder($identity['paymentIntent']);
            if ($order !== null && (!PaymentPolicy::stripeObjectMatchesOrder($refund, $order)
                || strtolower((string) $order['currency']) !== $identity['currency']
                || $identity['amount'] > (int) round((float) $order['total'] * 100))) {
                throw new HttpException('O reembolso externo não corresponde ao pagamento deste pedido.', 409, [], 'stripe_refund_mismatch');
            }
            $query = $this->pdo->prepare('SELECT * FROM payment_external_refunds WHERE stripe_refund_id = :id FOR UPDATE');
            $query->execute([':id' => $identity['id']]);
            $existing = $query->fetch();
            if ($existing && ((int) $existing['amount_cents'] !== $identity['amount']
                || strtolower((string) $existing['currency']) !== $identity['currency']
                || (bool) $existing['livemode'] !== $identity['livemode']
                || $existing['payment_intent_id'] !== $identity['paymentIntent'])) {
                throw new HttpException('A identidade do reembolso externo mudou no provedor.', 409, [], 'stripe_refund_mismatch');
            }
            $status = $identity['status'];
            if (!$authoritative && $existing && (($existing['provider_status'] === 'succeeded' && $status === 'pending')
                || (in_array($existing['provider_status'], ['failed', 'canceled'], true)
                    && in_array($status, ['pending', 'requires_action'], true)))) {
                $status = $existing['provider_status'];
            }
            $reopen = $existing && $existing['resolution'] === 'irrelevant' && $order !== null
                && (!in_array($status, ['failed', 'canceled'], true) || $existing['order_id'] === null);
            $resolution = $reopen ? 'unmatched' : ($existing['resolution'] ?? 'unmatched');
            $bound = $this->pdo->prepare('SELECT attempt, status, amount_cents, currency, provider_error_code
                FROM payment_refund_attempts WHERE stripe_refund_id = :id LIMIT 1');
            $bound->execute([':id' => $identity['id']]);
            $attempt = $bound->fetch();
            $counted = $attempt && ($attempt['status'] === 'succeeded'
                || ($attempt['status'] === 'manual' && $attempt['provider_error_code'] === 'manual_refunded'));
            if ($counted && in_array($status, ['failed', 'canceled', 'requires_action'], true)) {
                $reopen = $existing && $existing['resolution'] !== 'unmatched';
                $resolution = 'unmatched';
            } elseif ($authoritative && $attempt && $attempt['status'] === 'succeeded' && $status === 'succeeded'
                && (int) $attempt['amount_cents'] === $identity['amount'] && strtolower($attempt['currency']) === $identity['currency']) {
                $resolution = $resolution === 'external' ? 'external' : 'linked';
            }
            if (!$existing) {
                $insert = $this->pdo->prepare("INSERT INTO payment_external_refunds
                    (stripe_refund_id, order_id, payment_intent_id, amount_cents, currency, livemode, provider_status)
                    VALUES (:id, :orderId, :intent, :amount, :currency, :live, :status)");
                $insert->execute([':id' => $identity['id'], ':orderId' => $order['id'] ?? null,
                    ':intent' => $identity['paymentIntent'], ':amount' => $identity['amount'],
                    ':currency' => $identity['currency'], ':live' => (int) $identity['livemode'], ':status' => $status]);
            } else {
                $this->pdo->prepare("UPDATE payment_external_refunds SET order_id = COALESCE(order_id, :orderId),
                    provider_status = :status, resolution = :resolution, local_attempt = COALESCE(local_attempt, :attempt), last_synced_at = CURRENT_TIMESTAMP
                    WHERE stripe_refund_id = :id")
                    ->execute([':orderId' => $order['id'] ?? null, ':status' => $status, ':resolution' => $resolution, ':attempt' => $attempt['attempt'] ?? null, ':id' => $identity['id']]);
            }
            if (!$existing || $reopen) {
                $this->audit(null, $order['id'] ?? null, 'refund.external_detected',
                    $identity['id'] . '; status=' . $status . '; amount=' . $identity['amount'] . ' ' . $identity['currency']);
            }
            if ($existing && $existing['resolution'] === 'unmatched' && $resolution === 'linked') {
                $this->audit(null, $order['id'] ?? null, 'refund.external_provider_confirmed',
                    $identity['id'] . '; immutable attempt=' . $attempt['attempt'] . '; status=' . $status);
            }
            if ($ownsTransaction) $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public static function assertReconciled(PDO $pdo, int $orderId): void
    {
        $query = $pdo->prepare("SELECT 1 FROM payment_external_refunds WHERE order_id = :id AND resolution = 'unmatched' LIMIT 1");
        $query->execute([':id' => $orderId]);
        if ($query->fetchColumn()) {
            throw new HttpException('Concilie os reembolsos externos deste pedido antes de enviar outra devolução.', 409, [], 'external_refund_unmatched');
        }
    }

    /** A reviewed bank return cannot be undone by an older success webhook. */
    public static function shouldDeferReviewedSuccess(PDO $pdo, array $refund): bool
    {
        if (($refund['status'] ?? '') !== 'succeeded') return false;
        $query = $pdo->prepare("SELECT 1 FROM payment_external_refunds WHERE stripe_refund_id = :id
            AND resolution = 'reversed' AND provider_status IN ('failed','canceled','requires_action') LIMIT 1 FOR UPDATE");
        $query->execute([':id' => $refund['id'] ?? '']);
        return (bool) $query->fetchColumn();
    }

    /** Full intent history is replayed after failure; no time watermark can skip an unseen refund. */
    public function scanOrder(array $order): int
    {
        if (!$this->stripe->configured() || !PaymentPolicy::orderPaymentModeMatchesEnvironment($order)) {
            throw new HttpException('A configuração Stripe não permite conciliar este pagamento.', 503, [], 'stripe_refund_scan_unavailable');
        }
        $count = 0;
        foreach ($this->stripe->refundsForPaymentIntent((string) $order['payment_intent_id']) as $refund) {
            // Current API evidence may unlock a previously reviewed return; an
            // unordered webhook alone must not silently count it again.
            $known = $this->pdo->prepare('SELECT 1 FROM payment_external_refunds WHERE stripe_refund_id = :id');
            $known->execute([':id' => $refund['id']]);
            $observed = (bool) $known->fetchColumn();
            if ($observed) $this->capture($refund, true);
            try {
                ($this->applyRefund)($refund);
            } catch (HttpException $exception) {
                if (!in_array($exception->errorCode, ['stripe_refund_attempt_mismatch', 'stripe_refund_amount_mismatch'], true)) throw $exception;
                $this->capture($refund);
            }
            $known->execute([':id' => $refund['id']]);
            if ($known->fetchColumn()) $this->capture($refund, true);
            $count++;
        }
        return $count;
    }

    public function recover(int $limit = 10): int
    {
        if (!$this->stripe->configured()) return 0;
        $cutoff = gmdate('Y-m-d H:i:s', time() - 300);
        $query = $this->pdo->prepare("SELECT id FROM orders WHERE payment_provider = 'stripe' AND payment_intent_id IS NOT NULL
            AND (payment_refunds_scanned_at IS NULL OR payment_refunds_scanned_at < :cutoff)
            ORDER BY COALESCE(payment_refunds_scanned_at, '1970-01-01'), id LIMIT :limit");
        $query->bindValue(':cutoff', $cutoff);
        $query->bindValue(':limit', max(1, min($limit, 10)), PDO::PARAM_INT);
        $query->execute();
        $count = 0;
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try {
                $this->orderLock->acquire((int) $id);
                try {
                    $claim = $this->pdo->prepare("UPDATE orders SET payment_refunds_scanned_at = CURRENT_TIMESTAMP,
                        payment_refunds_scan_error = NULL WHERE id = :id
                        AND (payment_refunds_scanned_at IS NULL OR payment_refunds_scanned_at < :cutoff)");
                    $claim->execute([':id' => $id, ':cutoff' => $cutoff]);
                    if ($claim->rowCount() !== 1) continue;
                    $find = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id');
                    $find->execute([':id' => $id]);
                    $order = $find->fetch();
                    if ($order) $count += $this->scanOrder($order);
                } finally {
                    $this->orderLock->release((int) $id);
                }
            } catch (Throwable) {
                $this->pdo->prepare("UPDATE orders SET payment_refunds_scan_error = 'Stripe refund history could not be reconciled. No new refund was sent.' WHERE id = :id")
                    ->execute([':id' => $id]);
                error_log('External Stripe refund recovery failed for order ' . (int) $id . '.');
            }
        }
        return $count;
    }

    private function findOrder(?string $intent): ?array
    {
        if ($intent === null) return null;
        $query = $this->pdo->prepare("SELECT * FROM orders WHERE payment_provider = 'stripe' AND payment_intent_id = :intent LIMIT 1 FOR UPDATE");
        $query->execute([':intent' => $intent]);
        return $query->fetch() ?: null;
    }

    private function identity(array $refund): array
    {
        $id = $refund['id'] ?? null;
        $intent = $refund['payment_intent'] ?? null;
        if (is_array($intent)) $intent = $intent['id'] ?? null;
        $amount = $refund['amount'] ?? null;
        $currency = $refund['currency'] ?? null;
        $status = $refund['status'] ?? null;
        if (!is_string($id) || strlen($id) > 128 || !preg_match('/^re_[A-Za-z0-9]+$/D', $id)
            || ($intent !== null && (!is_string($intent) || strlen($intent) > 128 || !preg_match('/^pi_[A-Za-z0-9]+$/D', $intent)))
            || !is_int($amount) || $amount < 1 || $amount > 99_999_999
            || !is_string($currency) || !preg_match('/^[a-zA-Z]{3}$/D', $currency)
            || !is_string($status) || !preg_match('/^[a-z_]{1,32}$/D', $status)
            || !PaymentPolicy::stripeObjectMatchesEnvironment($refund)) {
            throw new HttpException('Dados inválidos no reembolso externo Stripe.', 409, [], 'stripe_refund_mismatch');
        }
        return ['id' => $id, 'paymentIntent' => $intent, 'amount' => $amount, 'currency' => strtolower($currency),
            'status' => $status, 'livemode' => $refund['livemode']];
    }

    private function audit(?int $adminId, ?int $orderId, string $action, string $detail): void
    {
        $this->pdo->prepare("INSERT INTO admin_audit_log
            (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
            VALUES (:admin, :action, 'external_refund', :orderId, NULL, :detail)")
            ->execute([':admin' => $adminId, ':action' => $action, ':orderId' => $orderId ?? 0, ':detail' => $detail]);
    }
}

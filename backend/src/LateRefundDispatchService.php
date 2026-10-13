<?php

declare(strict_types=1);

/** Durable automatic compensation. Financial transactions never span Stripe I/O. */
final class LateRefundDispatchService
{
    // Stripe may prune idempotency keys after 24h. Leave a safety margin, never renew this clock.
    private const REPLAY_WINDOW_SECONDS = 23 * 3600;

    public function __construct(
        private readonly PDO $pdo,
        private readonly StripeGateway $stripe,
        private readonly PaymentOrderLock $orderLock,
        private readonly Closure $applyRefund,
        private readonly Closure $scanRefundHistory,
        private readonly ?Closure $clock = null
    ) {}

    public function runBatch(int $limit = 25): int
    {
        if ($this->pdo->inTransaction()) throw new LogicException('Refund dispatch requires committed financial state.');
        if (!$this->stripe->configured()) return 0;
        $query = $this->pdo->prepare("SELECT order_id, attempt FROM payment_refund_dispatches
            WHERE state IN ('queued','retry') AND available_at <= :now ORDER BY available_at, order_id LIMIT :limit");
        $query->bindValue(':now', gmdate('Y-m-d H:i:s', $this->now()));
        $query->bindValue(':limit', max(1, min($limit, 25)), PDO::PARAM_INT);
        $query->execute();
        $count = 0;
        foreach ($query->fetchAll() as $job) {
            $id = (int) $job['order_id'];
            $locked = false;
            try {
                $this->orderLock->acquire($id);
                $locked = true;
                $this->dispatch($id, (int) $job['attempt']);
                $count++;
            } catch (Throwable $exception) {
                // Persisted jobs survive process/DB failures, without exposing provider payloads.
                error_log('Late refund dispatch deferred for order ' . $id . '.');
            } finally {
                if ($locked) $this->orderLock->release($id);
            }
        }
        return $count;
    }

    private function dispatch(int $id, int $attempt): void
    {
        $row = $this->transaction(function () use ($id, $attempt): ?array {
            $row = $this->load($id, $attempt);
            if (!$row || $this->finishIfSettled($row) || strtotime($row['dispatch_available_at'] . ' UTC') > $this->now()) return null;
            return $row;
        });
        if ($row === null) return;
        if (!$this->matchesRequest($row)) {
            $this->recordOutcome($id, $attempt, 'manual_required', 'late_refund_identity_mismatch', 'The queued refund identity changed; support must reconcile it.');
            return;
        }
        try {
            // A complete read-only scan recovers a refund accepted before a crash or timeout.
            // Failure/incomplete pagination cannot establish absence and never authorizes a POST.
            ($this->scanRefundHistory)($row);
        } catch (Throwable $exception) {
            $this->recordOutcome($id, $attempt, 'retry', 'late_refund_preflight_failed', 'Refund history could not be verified; no new request was sent.');
            return;
        }

        $ready = $this->transaction(function () use ($id, $attempt): ?array {
            $row = $this->load($id, $attempt);
            if (!$row || $this->finishIfSettled($row)) return null;
            if (!$this->matchesRequest($row)) {
                $this->saveOutcome($row, 'manual_required', 'late_refund_identity_mismatch', 'The queued refund identity changed; support must reconcile it.');
                return null;
            }
            try {
                ExternalRefundService::assertReconciled($this->pdo, $id);
                (new RefundLedgerSafety($this->pdo))->assertNoUnresolved($id, $attempt);
            } catch (HttpException $exception) {
                $this->saveOutcome($row, 'manual_required', $exception->errorCode ?? 'refund_history_unresolved', 'Reconcile all unresolved or external refunds before authorizing a financial operation.');
                return null;
            }
            $now = $this->now();
            $started = $row['request_started_at'] === null ? null : strtotime($row['request_started_at'] . ' UTC');
            if ($started !== null && ($started === false || $started > $now)) {
                $this->saveOutcome($row, 'manual_required', 'late_refund_clock_invalid', 'The replay clock is inconsistent; support must reconcile before any further request.');
                return null;
            }
            if ($started !== null && $now >= $started + self::REPLAY_WINDOW_SECONDS) {
                $this->saveOutcome($row, 'manual_required', 'late_refund_replay_window_expired', 'The safe idempotency replay window expired; only provider lookup or audited admin resolution is allowed.');
                return null;
            }
            $this->pdo->prepare("UPDATE payment_refund_dispatches SET request_started_at = COALESCE(request_started_at, :started),
                state = 'retry', attempts = attempts + 1, available_at = :available,
                last_error_code = 'late_refund_outcome_unknown', last_error = 'Provider request is in flight or its result is not yet known.'
                WHERE order_id = :id AND attempt = :attempt")
                ->execute([':started' => gmdate('Y-m-d H:i:s', $this->now()), ':available' => $this->nextRetry(), ':id' => $id, ':attempt' => $attempt]);
            $this->setFinancialOutcome($row, false, 'late_refund_outcome_unknown', 'Provider request is in flight or its result is not yet known.', null);
            $this->audit($row, 'order.late_payment_refund_dispatch_started', 'same immutable attempt; amount=' . $row['amount_cents']);
            return $row;
        });
        if ($ready === null) return;

        try {
            // The attempt, exact amount, idempotency deadline and in-flight marker are COMMITTED.
            $refund = $this->stripe->refundPaymentIntent((string) $ready['payment_intent_id'],
                (string) $ready['order_number'], $attempt, (int) $ready['amount_cents']);
        } catch (StripeApiException $exception) {
            $this->recordOutcome($id, $attempt, $exception->definitive ? 'failed' : 'retry',
                $exception->providerCode ?? 'stripe_outcome_unknown', $exception->providerMessage ?? 'No definitive provider response was received.', $exception->httpStatus);
            return;
        } catch (Throwable) {
            $this->recordOutcome($id, $attempt, 'retry', 'late_refund_transport_unknown', 'No definitive provider response was received.');
            return;
        }
        try {
            PaymentPolicy::assertStripeRefundMatchesAttempt($refund, $ready, $attempt, (int) $ready['amount_cents']);
        } catch (Throwable) {
            $this->recordOutcome($id, $attempt, 'manual_required', 'stripe_refund_response_mismatch', 'Provider response did not match the immutable refund identity.');
            return;
        }
        try {
            ($this->applyRefund)($refund);
        } catch (Throwable) {
            // A known, validated provider ID must survive a local outbox/application failure.
            // Persist identity only; the ordinary GET reconciliation will retry the full transaction.
            $this->rememberKnownRefund($id, $attempt, $refund);
            return;
        }
        $this->transaction(function () use ($id, $attempt): void {
            $row = $this->load($id, $attempt);
            if ($row) $this->finishIfSettled($row);
        });
    }

    private function rememberKnownRefund(int $id, int $attempt, array $refund): void
    {
        $this->transaction(function () use ($id, $attempt, $refund): void {
            $row = $this->load($id, $attempt);
            if (!$row || $this->finishIfSettled($row)) return;
            PaymentPolicy::assertStripeRefundMatchesAttempt($refund, $row, $attempt, (int) $row['amount_cents']);
            $this->pdo->prepare("UPDATE payment_refund_attempts SET stripe_refund_id = :refund, status = 'unknown',
                provider_error_code = 'late_refund_local_apply_failed', provider_error_message = 'Provider identity saved; financial confirmation requires reconciliation.'
                WHERE order_id = :id AND attempt = :attempt AND stripe_refund_id IS NULL")
                ->execute([':refund' => $refund['id'], ':id' => $id, ':attempt' => $attempt]);
            $this->pdo->prepare("UPDATE orders SET payment_refund_id = :refund,
                payment_refund_error_code = 'late_refund_local_apply_failed',
                payment_refund_error_message = 'Provider identity saved; financial confirmation requires reconciliation.'
                WHERE id = :id AND payment_refund_attempt = :attempt AND payment_status = 'refund_pending'")
                ->execute([':refund' => $refund['id'], ':id' => $id, ':attempt' => $attempt]);
            $this->closeJob($row, 'submitted');
            $this->audit($row, 'order.late_payment_refund_identity_saved', 'Validated provider identity saved for GET recovery; refund=' . $refund['id']);
        });
    }

    private function matchesRequest(array $row): bool
    {
        return $row['payment_provider'] === 'stripe' && PaymentPolicy::orderPaymentModeMatchesEnvironment($row)
            && $row['attempt_kind'] === 'full' && $row['currency'] === $row['attempt_currency']
            && is_string($row['payment_intent_id']) && preg_match('/^pi_[A-Za-z0-9_]{1,124}$/D', $row['payment_intent_id'])
            && (int) $row['amount_cents'] > 0
            && (int) $row['amount_cents'] === (int) round((float) $row['total'] * 100) - (int) $row['payment_refunded_cents']
            && hash_equals($row['request_hash'], LatePaymentRecoveryService::requestHash($row));
    }

    /** Must run under a row lock. Bound/terminal/superseded attempts never send another POST. */
    private function finishIfSettled(array $row): bool
    {
        if (!in_array($row['dispatch_state'], ['queued', 'retry'], true)) return true;
        if ($row['stripe_refund_id'] !== null || $row['attempt_status'] === 'succeeded') {
            $this->closeJob($row, 'submitted');
            return true;
        }
        if (!in_array($row['attempt_status'], ['pending', 'unknown'], true)
            || (int) $row['payment_refund_attempt'] !== (int) $row['attempt']
            || $row['status'] !== 'cancelled' || $row['payment_status'] !== 'refund_pending') {
            $this->closeJob($row, 'closed');
            return true;
        }
        return false;
    }

    private function load(int $id, int $attempt): ?array
    {
        $select = $this->pdo->prepare("SELECT o.*, r.attempt, r.attempt_kind, r.amount_cents, r.currency AS attempt_currency,
            r.stripe_refund_id, r.status AS attempt_status, d.request_hash, d.request_started_at, d.state AS dispatch_state,
            d.last_error_code, d.available_at AS dispatch_available_at FROM orders o INNER JOIN payment_refund_attempts r ON r.order_id = o.id
            INNER JOIN payment_refund_dispatches d ON d.order_id = r.order_id AND d.attempt = r.attempt
            WHERE o.id = :id AND r.attempt = :attempt FOR UPDATE");
        $select->execute([':id' => $id, ':attempt' => $attempt]);
        return $select->fetch() ?: null;
    }

    private function recordOutcome(int $id, int $attempt, string $state, string $code, string $message, ?int $httpStatus = null): void
    {
        $this->transaction(function () use ($id, $attempt, $state, $code, $message, $httpStatus): void {
            $row = $this->load($id, $attempt);
            if ($row && !$this->finishIfSettled($row)) $this->saveOutcome($row, $state, $code, $message, $httpStatus);
        });
    }

    private function saveOutcome(array $row, string $state, string $code, string $message, ?int $httpStatus = null): void
    {
        $code = substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $code) ?: 'provider_error', 0, 100);
        $message = PaymentNotificationPolicy::safeDiagnostic($message);
        $this->pdo->prepare('UPDATE payment_refund_dispatches SET state = :state, available_at = :available,
            last_error_code = :code, last_error = :message WHERE order_id = :id AND attempt = :attempt')
            ->execute([':state' => $state, ':available' => $this->nextRetry(), ':code' => $code, ':message' => $message,
                ':id' => $row['id'], ':attempt' => $row['attempt']]);
        $this->setFinancialOutcome($row, $state === 'failed', $code, $message, $httpStatus);
        if ($row['last_error_code'] !== $code || $row['dispatch_state'] !== $state) {
            $this->audit($row, 'order.late_payment_refund_dispatch_' . $state, $code . '; ' . $message);
        }
    }

    private function setFinancialOutcome(array $row, bool $failed, string $code, string $message, ?int $httpStatus): void
    {
        $this->pdo->prepare('UPDATE payment_refund_attempts SET status = :status, provider_error_code = :code,
            provider_error_message = :message WHERE order_id = :id AND attempt = :attempt')
            ->execute([':status' => $failed ? 'failed' : 'unknown', ':code' => $code, ':message' => $message,
                ':id' => $row['id'], ':attempt' => $row['attempt']]);
        $this->pdo->prepare("UPDATE orders SET payment_refund_outcome = :outcome,
            payment_status = IF(:failed = 1, IF(payment_refunded_cents >= ROUND(total * 100), 'refunded',
                IF(payment_refunded_cents > 0, 'partially_refunded', 'paid')), payment_status),
            payment_refund_error_code = :code, payment_refund_error_message = :message, payment_refund_error_http_status = :http
            WHERE id = :id AND payment_refund_attempt = :attempt AND payment_status = 'refund_pending'")
            ->execute([':outcome' => $failed ? 'definitive_failure' : 'unknown', ':failed' => $failed ? 1 : 0,
                ':code' => $code, ':message' => $message, ':http' => $httpStatus, ':id' => $row['id'], ':attempt' => $row['attempt']]);
    }

    private function closeJob(array $row, string $state): void
    {
        $this->pdo->prepare('UPDATE payment_refund_dispatches SET state = :state WHERE order_id = :id AND attempt = :attempt')
            ->execute([':state' => $state, ':id' => $row['id'], ':attempt' => $row['attempt']]);
    }

    private function audit(array $row, string $action, string $message): void
    {
        $this->pdo->prepare("INSERT INTO admin_audit_log (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
            VALUES (NULL, :action, 'order', :id, :previous, :new)")
            ->execute([':action' => $action, ':id' => $row['id'], ':previous' => $row['dispatch_state'],
                ':new' => 'attempt=' . $row['attempt'] . '; ' . $message]);
    }

    private function transaction(Closure $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** A shared DB clock avoids replay-window drift between application replicas. */
    private function now(): int
    {
        return $this->clock === null ? (int) $this->pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn() : ($this->clock)();
    }
    private function nextRetry(): string { return gmdate('Y-m-d H:i:s', $this->now() + 300); }
}

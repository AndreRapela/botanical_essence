<?php

declare(strict_types=1);

/** Audited, cumulative Stripe refunds; only one unresolved attempt is allowed per order. */
trait PartialRefundActions
{
    use PartialRefundFailureActions;
    private function hasTrackedPartialRefundAttempt(array $order): bool
    {
        $query = $this->pdo->prepare("SELECT 1 FROM payment_refund_attempts WHERE order_id = :orderId AND attempt = :attempt AND attempt_kind = 'partial' LIMIT 1");
        $query->execute([':orderId' => $order['id'], ':attempt' => $order['payment_refund_attempt']]);
        return (bool) $query->fetchColumn();
    }

    private function reconcilePartialRefundAttempt(array $order, int $adminId): array
    {
        if (($order['payment_provider'] ?? null) !== 'stripe'
            || !in_array($order['payment_status'], ['refund_pending', 'paid', 'partially_refunded'], true)
            || (string) ($order['payment_intent_id'] ?? '') === ''
            || !$this->hasTrackedPartialRefundAttempt($order)) {
            throw new HttpException('Não há uma tentativa parcial Stripe registrada para consultar.', 409);
        }
        $attempt = (int) $order['payment_refund_attempt'];
        $row = $this->refundAttemptRow((int) $order['id'], $attempt);
        if (!$row || ($row['attempt_kind'] ?? 'partial') !== 'partial') throw new HttpException('A tentativa parcial não foi encontrada no ledger.', 404);
        if ($row['status'] === 'succeeded') return ['message' => 'A Stripe já confirmou esta tentativa de reembolso parcial.'];
        $refund = null;
        if (is_string($row['stripe_refund_id']) && $row['stripe_refund_id'] !== '') {
            try {
                $refund = $this->stripe->retrieveRefund((string) $row['stripe_refund_id']);
            } catch (StripeApiException $exception) {
                if ($exception->httpStatus !== 404) {
                    $this->recordUnknownRefundOutcome((int) $order['id'], $attempt, null, $exception->providerCode ?? 'partial_refund_lookup_failed', $exception->providerMessage, $exception->httpStatus, $adminId, false);
                    return ['message' => 'A Stripe não concluiu a consulta. A tentativa permanece registrada sem reenvio.'];
                }
            }
        }
        if (!$refund) {
            try {
                foreach ($this->stripe->refundsForPaymentIntent((string) $order['payment_intent_id']) as $candidate) {
                    if (is_array($candidate)
                        && (string) ($candidate['metadata']['order_number'] ?? '') === (string) $order['order_number']
                        && (int) ($candidate['metadata']['refund_attempt'] ?? 0) === $attempt) {
                        $refund = $candidate;
                        break;
                    }
                }
            } catch (Throwable $exception) {
                $this->recordUnknownRefundOutcome((int) $order['id'], $attempt, null, 'partial_refund_lookup_failed', 'Stripe could not be reached while checking this partial refund.', null, $adminId, false);
                error_log('Partial Stripe refund lookup failed for order ' . $order['order_number'] . ': ' . $exception->getMessage());
                return ['message' => 'A Stripe não concluiu a consulta. A tentativa permanece registrada sem reenvio.'];
            }
        }
        if (!$refund) return ['message' => 'Nenhum reembolso desta tentativa está visível na Stripe agora. Nenhuma nova tentativa foi enviada; consulte novamente mais tarde.'];
        return $this->applyPartialRefundResult($order, $refund, $adminId);
    }

    private function resolvePartialRefundAttempt(array $order, int $adminId, string $resolution, string $reason): array
    {
        if (!$this->hasTrackedPartialRefundAttempt($order)) {
            throw new HttpException('A tentativa atual não é um reembolso parcial rastreado.', 409);
        }
        $this->pdo->beginTransaction();
        try {
            $attempt = (int) $order['payment_refund_attempt'];
            $lock = $this->pdo->prepare(
                "SELECT * FROM payment_refund_attempts WHERE order_id = :orderId AND attempt = :attempt AND attempt_kind = 'partial' LIMIT 1 FOR UPDATE"
            );
            $lock->execute([':orderId' => $order['id'], ':attempt' => $attempt]);
            $refundAttempt = $lock->fetch();
            $orderLock = $this->pdo->prepare('SELECT status, payment_status, payment_refunded_cents, total FROM orders WHERE id = :id LIMIT 1 FOR UPDATE');
            $orderLock->execute([':id' => $order['id']]);
            $current = $orderLock->fetch();
            if (!$refundAttempt || !$current || !in_array($refundAttempt['status'], ['pending', 'unknown'], true)
                || $current['payment_status'] !== 'refund_pending') {
                throw new HttpException('A tentativa parcial não está em um estado incerto que possa ser resolvido manualmente.', 409);
            }
            $newPaymentStatus = (string) $refundAttempt['previous_payment_status'];
            $newRefunded = (int) $current['payment_refunded_cents'];
            if ($resolution === 'refunded') {
                $newRefunded += (int) $refundAttempt['amount_cents'];
                if ($newRefunded > $this->cents((string) $current['total'])) {
                    throw new HttpException('O valor acumulado de reembolso ultrapassaria o total pago.', 409);
                }
                $newPaymentStatus = $newRefunded >= $this->cents((string) $current['total']) ? 'refunded' : 'partially_refunded';
            }
            $ledger = $this->pdo->prepare(
                'UPDATE payment_refund_attempts SET status = \'manual\', provider_error_code = :code,
                        provider_error_message = :reason WHERE order_id = :orderId AND attempt = :attempt'
            );
            $ledger->execute([
                ':code' => $resolution === 'refunded' ? 'manual_refunded' : 'manual_not_refunded',
                ':reason' => $reason,
                ':orderId' => $order['id'],
                ':attempt' => $attempt,
            ]);
            if ($resolution === 'refunded') {
                $queue = $this->pdo->prepare("INSERT IGNORE INTO payment_notification_outbox (order_id, notification_type, reference_id) VALUES (:orderId, 'refund_confirmation', :attempt)");
                $queue->execute([':orderId' => $order['id'], ':attempt' => (string) $attempt]);
            }
            $update = $this->pdo->prepare(
                'UPDATE orders SET payment_status = :paymentStatus, payment_refunded_cents = :refundedCents,
                        payment_refund_outcome = :outcome, payment_refund_error_code = :errorCode,
                        payment_refund_error_message = :errorMessage, payment_refund_error_http_status = NULL
                 WHERE id = :id AND payment_status = \'refund_pending\' AND payment_refund_attempt = :attempt'
            );
            $update->execute([
                ':paymentStatus' => $newPaymentStatus,
                ':refundedCents' => $newRefunded,
                ':outcome' => $resolution === 'refunded' ? 'manual_refunded' : 'manual_not_refunded',
                ':errorCode' => $resolution === 'refunded' ? null : 'manual_not_refunded',
                ':errorMessage' => $resolution === 'refunded' ? null : $reason,
                ':id' => $order['id'],
                ':attempt' => $attempt,
            ]);
            if ($update->rowCount() !== 1) throw new HttpException('O pedido mudou durante a resolução manual.', 409);
            if ($refundAttempt['withdrawal_request_id'] !== null) {
                $requestStatus = $resolution === 'refunded' ? 'refunded' : 'refund_failed';
                $previousRequestStatus = $resolution === 'refunded' ? 'refund_pending' : 'refund_pending';
                $updateReturn = $this->pdo->prepare('UPDATE withdrawal_requests SET status = :status WHERE id = :id AND status = \'refund_pending\'');
                $updateReturn->execute([':status' => $requestStatus, ':id' => $refundAttempt['withdrawal_request_id']]);
                if ($updateReturn->rowCount() !== 1) throw new HttpException('O estado da solicitação mudou durante a resolução manual.', 409);
                $event = $this->pdo->prepare(
                    'INSERT INTO withdrawal_request_events (request_id, actor_user_id, actor_role, event_name, previous_status, new_status, note)
                     VALUES (:id, :adminId, \'admin\', :eventName, :previous, :new, :note)'
                );
                $event->execute([
                    ':id' => $refundAttempt['withdrawal_request_id'], ':adminId' => $adminId,
                    ':eventName' => $resolution === 'refunded' ? 'refund.manually_confirmed' : 'refund.manually_not_refunded',
                    ':previous' => $previousRequestStatus, ':new' => $requestStatus, ':note' => $reason,
                ]);
            }
            $audit = $this->pdo->prepare(
                "INSERT INTO admin_audit_log (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, 'order.partial_refund_resolved_manually', 'order', :orderId, :previous, :new)"
            );
            $audit->execute([
                ':adminId' => $adminId,
                ':orderId' => $order['id'],
                ':previous' => $current['status'] . ':refund_pending',
                ':new' => $newPaymentStatus . ':' . $resolution . '; attempt=' . $attempt . '; ' . $reason,
            ]);
            $this->pdo->commit();
            return ['message' => $resolution === 'refunded'
                ? 'Resolução manual auditada. O total reembolsado foi atualizado; confirme previamente a transação na Stripe.'
                : 'Resolução manual auditada como não reembolsado; o valor continua devido e pode receber nova tentativa.'];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function partialRefundForAdmin(array $input, array $admin): array
    {
        Validation::allowedFields($input, ['orderNumber', 'amountCents', 'reason']);
        $number = Validation::requiredString($input, 'orderNumber', 8, 32);
        $amountCents = Validation::integer($input, 'amountCents', 1, 2_000_000_000);
        $reason = Validation::requiredString($input, 'reason', 10, 500);
        $this->limiter->assertAllowed('admin-payment', (string) $admin['id'], 100, 3600);
        $order = $this->orderForAdmin($number);
        if (!$order) throw new HttpException('Pedido não encontrado.', 404);
        if (($order['payment_provider'] ?? null) !== 'stripe'
            || !PaymentPolicy::orderPaymentModeMatchesEnvironment($order)) {
            throw new HttpException('O reembolso parcial só pode ser iniciado para um pagamento Stripe no ambiente correto.', 409, [], 'partial_refund_payment_unavailable');
        }
        $this->orderLock->acquire((int) $order['id']);
        try {
            return $this->startPartialRefundAttempt($this->orderForAdmin($number) ?? $order, $amountCents, $reason, (int) $admin['id']);
        } finally {
            $this->orderLock->release((int) $order['id']);
        }
    }

    private function startPartialRefundAttempt(array $order, int $amountCents, string $reason, int $adminId, ?int $withdrawalRequestId = null): array
    {
        $paymentIntentId = (string) ($order['payment_intent_id'] ?? '');
        if ($paymentIntentId === '') {
            throw new HttpException('O pagamento ainda não tem uma referência Stripe para reembolso.', 409);
        }
        if (!in_array($order['status'], ['confirmed', 'preparing', 'shipped', 'delivered'], true)) {
            throw new HttpException('O pedido não está em um estado que permita reembolso parcial.', 409);
        }

        $this->verifyProviderRefundLedger($order);

        $this->pdo->beginTransaction();
        try {
            $returnRequest = null;
            if ($withdrawalRequestId !== null) {
                $return = $this->pdo->prepare('SELECT order_id, status FROM withdrawal_requests WHERE id = :id LIMIT 1 FOR UPDATE');
                $return->execute([':id' => $withdrawalRequestId]);
                $returnRequest = $return->fetch();
                if (!$returnRequest || (int) $returnRequest['order_id'] !== (int) $order['id']
                    || !in_array($returnRequest['status'], ['return_received', 'refund_failed'], true)) {
                    throw new HttpException('A solicitação de devolução precisa estar recebida ou ter uma tentativa definitivamente recusada.', 409, [], 'return_not_received');
                }
            }
            $select = $this->pdo->prepare(
                'SELECT id, order_number, status, payment_status, payment_provider, payment_intent_id,
                        payment_refund_attempt, payment_refund_outcome, payment_refunded_cents, total, currency
                 FROM orders WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $select->execute([':id' => $order['id']]);
            $current = $select->fetch();
            ExternalRefundService::assertReconciled($this->pdo, (int) $order['id']);
            if ($withdrawalRequestId === null) {
                $activeReturn = $this->pdo->prepare(
                    "SELECT id FROM withdrawal_requests
                     WHERE order_id = :orderId AND status IN ('submitted','reviewing','instructions_sent','return_received','refund_pending','refund_failed')
                     ORDER BY id DESC LIMIT 1"
                );
                $activeReturn->execute([':orderId' => $order['id']]);
                if ($activeReturn->fetchColumn()) {
                    throw new HttpException('Este pedido tem um caso de devolução aberto. Faça o reembolso pela fila de devoluções para manter os estados sincronizados.', 409, [], 'withdrawal_request_active');
                }
            }
            if (!$current || !in_array($current['payment_status'], ['paid', 'partially_refunded'], true)
                || !in_array($current['payment_refund_outcome'], ['none', 'succeeded', 'definitive_failure', 'manual_refunded', 'manual_not_refunded'], true)
                || !hash_equals($paymentIntentId, (string) $current['payment_intent_id'])) {
                throw new HttpException('O pagamento tem uma operação pendente ou não permite novo reembolso. Consulte primeiro o estado na Stripe.', 409, [], 'refund_attempt_unresolved');
            }
            $totalCents = $this->cents((string) $current['total']);
            $alreadyRefunded = (int) $current['payment_refunded_cents'];
            $remaining = max(0, $totalCents - $alreadyRefunded);
            if ($amountCents > $remaining) {
                throw new HttpException('O valor solicitado excede o saldo ainda não reembolsado.', 422, ['remainingCents' => $remaining], 'refund_amount_exceeds_remaining');
            }
            $attempt = (int) $current['payment_refund_attempt'] + 1;
            $claim = $this->pdo->prepare(
                "UPDATE orders SET payment_status = 'refund_pending', payment_refund_attempt = :attempt,
                        payment_refund_id = NULL, payment_refund_outcome = 'pending',
                        payment_refund_error_code = NULL, payment_refund_error_message = NULL,
                        payment_refund_error_http_status = NULL
                 WHERE id = :id AND payment_status = :previousStatus
                       AND payment_refund_attempt = :previousAttempt"
            );
            $claim->execute([
                ':attempt' => $attempt,
                ':id' => $current['id'],
                ':previousStatus' => $current['payment_status'],
                ':previousAttempt' => $current['payment_refund_attempt'],
            ]);
            if ($claim->rowCount() !== 1) throw new HttpException('O pagamento mudou ao reservar o reembolso.', 409);

            $ledger = $this->pdo->prepare(
                "INSERT INTO payment_refund_attempts
                    (order_id, attempt, attempt_kind, amount_cents, currency, previous_payment_status,
                     previous_order_status, withdrawal_request_id, status, reason, requested_by_admin_id)
                 VALUES (:orderId, :attempt, 'partial', :amount, :currency, :previousPayment,
                         :previousOrder, :withdrawalRequestId, 'pending', :reason, :adminId)"
            );
            $ledger->execute([
                ':orderId' => $current['id'],
                ':attempt' => $attempt,
                ':amount' => $amountCents,
                ':currency' => $current['currency'],
                ':previousPayment' => $current['payment_status'],
                ':previousOrder' => $current['status'],
                ':withdrawalRequestId' => $withdrawalRequestId,
                ':reason' => $reason,
                ':adminId' => $adminId,
            ]);
            $audit = $this->pdo->prepare(
                "INSERT INTO admin_audit_log
                    (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, 'order.partial_refund_requested', 'order', :orderId, :previous, :new)"
            );
            $audit->execute([
                ':adminId' => $adminId,
                ':orderId' => $current['id'],
                ':previous' => $current['status'] . ':' . $current['payment_status'],
                ':new' => 'refund_pending:' . $amountCents . ':' . $current['currency'] . '; attempt=' . $attempt . '; reason=' . $reason,
            ]);
            if ($withdrawalRequestId !== null) {
                $updateReturn = $this->pdo->prepare("UPDATE withdrawal_requests SET status = 'refund_pending', handled_by_admin_id = :adminId WHERE id = :id");
                $updateReturn->execute([':adminId' => $adminId, ':id' => $withdrawalRequestId]);
                $event = $this->pdo->prepare(
                    "INSERT INTO withdrawal_request_events (request_id, actor_user_id, actor_role, event_name, previous_status, new_status, note)
                     VALUES (:requestId, :adminId, 'admin', 'refund.started', :previous, 'refund_pending', :note)"
                );
                $event->execute([
                    ':requestId' => $withdrawalRequestId,
                    ':adminId' => $adminId,
                    ':previous' => $returnRequest['status'],
                    ':note' => 'Refund attempt #' . $attempt . '; amount ' . $amountCents . ' minor units.',
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }

        $claimed = $this->orderForAdmin((string) $order['order_number']);
        if (!$claimed || (int) $claimed['payment_refund_attempt'] !== $attempt
            || $claimed['payment_status'] !== 'refund_pending') {
            throw new HttpException('A tentativa de reembolso foi reservada, mas precisa ser consultada antes de qualquer repetição.', 409, [], 'refund_attempt_unresolved');
        }
        try {
            $refund = $this->stripe->refundPaymentIntent($paymentIntentId, (string) $order['order_number'], $attempt, $amountCents);
            return $this->applyPartialRefundResult($claimed, $refund, $adminId);
        } catch (StripeApiException $exception) {
            if ($exception->definitive) {
                $this->finishPartialRefundFailure(
                    $claimed, $attempt,
                    $exception->providerCode ?? ('stripe_http_' . ($exception->httpStatus ?? 'error')),
                    $exception->providerMessage,
                    $adminId,
                    false
                );
                return ['message' => 'A Stripe recusou definitivamente o reembolso parcial. O pagamento permanece conciliado; verifique o motivo antes de tentar novamente.'];
            }
            $this->finishPartialRefundFailure($claimed, $attempt, $exception->providerCode ?? 'stripe_outcome_unknown', $exception->providerMessage, $adminId, true);
            return ['message' => 'O resultado do reembolso ainda é desconhecido. A tentativa foi preservada; consulte o estado na Stripe antes de qualquer repetição.'];
        } catch (Throwable $exception) {
            $this->finishPartialRefundFailure($claimed, $attempt, 'transport_or_response_error', 'No definitive response was received from the payment provider.', $adminId, true);
            error_log('Partial refund outcome is unknown for order ' . $order['order_number'] . ': ' . $exception->getMessage());
            return ['message' => 'O resultado do reembolso ainda é desconhecido. A tentativa foi preservada; consulte o estado na Stripe antes de qualquer repetição.'];
        }
    }

    private function applyPartialRefundEvent(array $refund): bool
    {
        $paymentIntent = $refund['payment_intent'] ?? null;
        $paymentIntentId = is_array($paymentIntent) ? (string) ($paymentIntent['id'] ?? '') : (string) $paymentIntent;
        $refundId = (string) ($refund['id'] ?? '');
        if ($paymentIntentId === '' || $refundId === '') return false;
        $boundKind = $this->pdo->prepare('SELECT attempt_kind FROM payment_refund_attempts WHERE stripe_refund_id = :id LIMIT 1');
        $boundKind->execute([':id' => $refundId]);
        if ($boundKind->fetchColumn() === 'full') return false;
        $attempt = isset($refund['metadata']['refund_attempt']) ? (int) $refund['metadata']['refund_attempt'] : 0;
        $find = $this->pdo->prepare(
            "SELECT r.*, o.order_number, o.payment_intent_id, o.payment_status, o.payment_provider,
                    o.test_payment, o.status AS current_order_status, o.payment_refunded_cents, o.total
             FROM payment_refund_attempts r INNER JOIN orders o ON o.id = r.order_id
             WHERE r.attempt_kind = 'partial' AND o.payment_intent_id = :paymentIntent AND (
                    (:attemptFilter > 0 AND r.attempt = :attemptMatch) OR r.stripe_refund_id = :refundId
             ) ORDER BY (r.stripe_refund_id = :refundPriority) DESC LIMIT 1 FOR UPDATE"
        );
        $find->execute([':paymentIntent' => $paymentIntentId, ':attemptFilter' => $attempt, ':attemptMatch' => $attempt, ':refundId' => $refundId, ':refundPriority' => $refundId]);
        $row = $find->fetch();
        if (!$row) return false;
        $order = [
            'order_number' => $row['order_number'],
            'payment_provider' => $row['payment_provider'],
            'payment_intent_id' => $row['payment_intent_id'],
            'test_payment' => $row['test_payment'],
            'currency' => $row['currency'],
        ];
        PaymentPolicy::assertStripeRefundMatchesAttempt($refund, $order, (int) $row['attempt'], (int) $row['amount_cents'], $row['stripe_refund_id']);
        $this->savePartialRefundProviderState($row, $refund, null);
        return true;
    }

    private function applyPartialRefundResult(array $order, array $refund, int $adminId): array
    {
        if (!PaymentPolicy::stripeObjectMatchesOrder($refund, $order)) {
            throw new HttpException('O reembolso Stripe pertence a um modo incompatível com este pedido.', 409, [], 'stripe_refund_mode_mismatch');
        }
        $paymentIntent = $refund['payment_intent'] ?? null;
        $paymentIntentId = is_array($paymentIntent) ? (string) ($paymentIntent['id'] ?? '') : (string) $paymentIntent;
        if ($paymentIntentId === '' || !hash_equals((string) $order['payment_intent_id'], $paymentIntentId)) {
            throw new HttpException('A referência do reembolso não corresponde ao pagamento deste pedido.', 409, [], 'stripe_refund_mismatch');
        }
        $row = $this->refundAttemptRow((int) $order['id'], (int) $order['payment_refund_attempt']);
        if (!$row) {
            throw new HttpException('O valor do reembolso Stripe não corresponde ao valor autorizado no ledger.', 409, [], 'stripe_refund_amount_mismatch');
        }
        PaymentPolicy::assertStripeRefundMatchesAttempt(
            $refund,
            $order,
            (int) $row['attempt'],
            (int) $row['amount_cents'],
            $row['stripe_refund_id']
        );
        $this->savePartialRefundProviderState($row, $refund, $adminId);
        return match ((string) ($refund['status'] ?? '')) {
            'succeeded' => ['message' => 'A Stripe confirmou o reembolso parcial e o total reembolsado foi atualizado.'],
            'pending', 'requires_action' => ['message' => 'A Stripe aceitou o reembolso parcial, que permanece em processamento até confirmação final.'],
            'failed', 'canceled' => ['message' => 'A Stripe recusou o reembolso parcial. O pagamento permanece conciliado e uma nova tentativa pode ser avaliada.'],
            default => ['message' => 'O provedor retornou um estado não final. A tentativa permanece registrada para reconciliação.'],
        };
    }

    private function savePartialRefundProviderState(array $attemptRow, array $refund, ?int $adminId): void
    {
        $status = (string) ($refund['status'] ?? 'unknown');
        $refundId = (string) ($refund['id'] ?? '');
        $orderId = (int) $attemptRow['order_id'];
        $attempt = (int) $attemptRow['attempt'];
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare("SELECT * FROM payment_refund_attempts WHERE order_id = :orderId AND attempt = :attempt AND attempt_kind = 'partial' LIMIT 1 FOR UPDATE");
            $lock->execute([':orderId' => $orderId, ':attempt' => $attempt]);
            $currentAttempt = $lock->fetch();
            $orderQuery = $this->pdo->prepare(
                'SELECT id, status, payment_status, payment_refund_attempt, payment_refunded_cents,
                        total, order_number
                 FROM orders WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $orderQuery->execute([':id' => $orderId]);
            $currentOrder = $orderQuery->fetch();
            if (!$currentAttempt || !$currentOrder) throw new HttpException('O reembolso não pôde ser conciliado com o pedido.', 409);
            // Metadata is mutable in Stripe. Once an attempt has a provider ID,
            // no other refund may claim it, including after a terminal/manual outcome.
            if ($currentAttempt['stripe_refund_id'] !== null
                && !hash_equals((string) $currentAttempt['stripe_refund_id'], $refundId)) {
                throw new HttpException('A Stripe vinculou mais de um reembolso à mesma tentativa parcial.', 409, [], 'stripe_refund_attempt_mismatch');
            }
            if (ExternalRefundService::shouldDeferReviewedSuccess($this->pdo, $refund)) {
                if ($ownsTransaction) $this->pdo->commit();
                return;
            }
            if ($currentAttempt['status'] === 'succeeded') {
                if (in_array($status, ['failed', 'canceled', 'requires_action'], true)) $this->captureUnmatchedRefund($refund);
                if ($ownsTransaction) $this->pdo->commit();
                return;
            }
            if ($currentAttempt['status'] === 'manual' && $status !== 'succeeded') {
                if ($currentAttempt['provider_error_code'] === 'manual_refunded' && in_array($status, ['failed', 'canceled', 'requires_action'], true)) $this->captureUnmatchedRefund($refund);
                // Manual decisions are terminal for stale pending/failure events. In particular,
                // do not let an out-of-order event undo the operator's decision or retry forever.
                $save = $this->pdo->prepare(
                    'UPDATE payment_refund_attempts
                     SET stripe_refund_id = COALESCE(stripe_refund_id, :refundId)
                     WHERE order_id = :orderId AND attempt = :attempt AND status = \'manual\''
                );
                $save->execute([
                    ':refundId' => $refundId !== '' ? $refundId : null,
                    ':orderId' => $orderId,
                    ':attempt' => $attempt,
                ]);
                error_log('Ignoring stale Stripe refund status ' . $status . ' after manual resolution for order ' . $currentOrder['order_number'] . ', attempt ' . $attempt . '.');
                if ($ownsTransaction) $this->pdo->commit();
                return;
            }
            if (RefundStatePolicy::isRepeatedFailure($currentAttempt, $refund, 'refund_failed')) {
                if ($ownsTransaction) $this->pdo->commit();
                return;
            }
            if ($currentAttempt['status'] === 'failed' && in_array($status, ['pending', 'requires_action'], true)) {
                // Stripe does not guarantee webhook ordering; an old pending event
                // must not reopen an attempt already rejected definitively.
                $save = $this->pdo->prepare(
                    "UPDATE payment_refund_attempts SET stripe_refund_id = COALESCE(stripe_refund_id, :refundId)
                     WHERE order_id = :orderId AND attempt = :attempt AND attempt_kind = 'partial' AND status = 'failed'"
                );
                $save->execute([
                    ':refundId' => $refundId !== '' ? $refundId : null,
                    ':orderId' => $orderId,
                    ':attempt' => $attempt,
                ]);
                if ($ownsTransaction) $this->pdo->commit();
                return;
            }
            if (in_array($status, ['pending', 'requires_action'], true)) {
                $save = $this->pdo->prepare("UPDATE payment_refund_attempts SET stripe_refund_id = :refundId, status = 'pending', provider_error_code = NULL, provider_error_message = NULL WHERE order_id = :orderId AND attempt = :attempt");
                $save->execute([':refundId' => $refundId !== '' ? $refundId : null, ':orderId' => $orderId, ':attempt' => $attempt]);
                $this->pdo->prepare("UPDATE orders SET payment_refund_id = :refundId, payment_refund_outcome = 'pending'
                    WHERE id = :id AND payment_refund_attempt = :attempt AND payment_status = 'refund_pending'")
                    ->execute([':refundId' => $refundId, ':id' => $orderId, ':attempt' => $attempt]);
                if ($ownsTransaction) $this->pdo->commit();
                return;
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                $this->finishPartialRefundFailure($currentOrder, $attempt, (string) ($refund['failure_reason'] ?? 'refund_failed'), 'Stripe refund status: ' . $status, $adminId, false, $refundId !== '' ? $refundId : null);
                if ($ownsTransaction) $this->pdo->commit();
                return;
            }
            if ($status !== 'succeeded') {
                $save = $this->pdo->prepare("UPDATE payment_refund_attempts SET stripe_refund_id = :refundId, status = 'unknown', provider_error_code = 'unrecognized_status', provider_error_message = :message WHERE order_id = :orderId AND attempt = :attempt");
                $save->execute([':refundId' => $refundId !== '' ? $refundId : null, ':message' => 'Stripe returned an unrecognized refund status.', ':orderId' => $orderId, ':attempt' => $attempt]);
                if ($ownsTransaction) $this->pdo->commit();
                return;
            }
            if ((int) $currentAttempt['amount_cents'] !== (int) ($refund['amount'] ?? -1)
                || strtolower((string) $currentAttempt['currency']) !== strtolower((string) ($refund['currency'] ?? ''))) {
                throw new HttpException('O valor confirmado na Stripe diverge do ledger do reembolso.', 409, [], 'stripe_refund_amount_mismatch');
            }
            $amountAlreadyCounted = RefundStatePolicy::partialRefundAmountWasAlreadyCounted(
                (string) $currentAttempt['status'],
                $currentAttempt['provider_error_code'] === null ? null : (string) $currentAttempt['provider_error_code']
            );
            $newRefunded = (int) $currentOrder['payment_refunded_cents']
                + ($amountAlreadyCounted ? 0 : (int) $currentAttempt['amount_cents']);
            $totalCents = $this->cents((string) $currentOrder['total']);
            if ($newRefunded > $totalCents) throw new HttpException('O reembolso acumulado ultrapassaria o total pago.', 409);
            $paymentStatus = $newRefunded >= $totalCents ? 'refunded' : 'partially_refunded';
            $saveAttempt = $this->pdo->prepare("UPDATE payment_refund_attempts SET stripe_refund_id = :refundId, status = 'succeeded', provider_error_code = NULL, provider_error_message = NULL WHERE order_id = :orderId AND attempt = :attempt AND status <> 'succeeded'");
            $saveAttempt->execute([':refundId' => $refundId !== '' ? $refundId : null, ':orderId' => $orderId, ':attempt' => $attempt]);
            if ($saveAttempt->rowCount() !== 1) {
                if ($ownsTransaction) $this->pdo->commit();
                return;
            }
            $isCurrentAttempt = (int) $currentOrder['payment_refund_attempt'] === $attempt;
            $effectivePaymentStatus = !$isCurrentAttempt && $currentOrder['payment_status'] === 'refund_pending'
                ? 'refund_pending'
                : $paymentStatus;
            if ($isCurrentAttempt) {
                // A current manually-resolved attempt has left refund_pending, but its provider
                // confirmation is still authoritative. Reconcile it against the locked row.
                $saveOrder = $this->pdo->prepare(
                    'UPDATE orders SET payment_status = :paymentStatus, payment_refunded_cents = :refundedCents,
                            payment_refund_id = :refundId, payment_refund_outcome = \'succeeded\',
                            payment_refund_error_code = NULL, payment_refund_error_message = NULL,
                            payment_refund_error_http_status = NULL
                     WHERE id = :id AND payment_refund_attempt = :attempt'
                );
                $saveOrder->execute([
                    ':paymentStatus' => $paymentStatus,
                    ':refundedCents' => $newRefunded,
                    ':refundId' => $refundId !== '' ? $refundId : null,
                    ':id' => $orderId,
                    ':attempt' => $attempt,
                ]);
            } else {
                // A late event for an older attempt must contribute to the cumulative ledger,
                // without overwriting the state or Stripe reference of a newer attempt.
                $saveOrder = $this->pdo->prepare(
                    'UPDATE orders SET payment_status = IF(payment_status = \'refund_pending\', payment_status, :paymentStatus),
                            payment_refunded_cents = :refundedCents
                     WHERE id = :id'
                );
                $saveOrder->execute([
                    ':paymentStatus' => $paymentStatus,
                    ':refundedCents' => $newRefunded,
                    ':id' => $orderId,
                ]);
            }
            $queue = $this->pdo->prepare("INSERT IGNORE INTO payment_notification_outbox (order_id, notification_type, reference_id) VALUES (:orderId, 'refund_confirmation', :attempt)");
            $queue->execute([':orderId' => $orderId, ':attempt' => (string) $attempt]);
            $withdrawalRequestId = $currentAttempt['withdrawal_request_id'] === null ? null : (int) $currentAttempt['withdrawal_request_id'];
            if ($withdrawalRequestId !== null) {
                $returnLock = $this->pdo->prepare('SELECT status FROM withdrawal_requests WHERE id = :id LIMIT 1 FOR UPDATE');
                $returnLock->execute([':id' => $withdrawalRequestId]);
                $previousReturnStatus = $returnLock->fetchColumn();
                if (in_array($previousReturnStatus, ['refund_pending', 'refund_failed'], true)) {
                    $updateReturn = $this->pdo->prepare(
                        'UPDATE withdrawal_requests SET status = \'refunded\' WHERE id = :id AND status = :previousStatus'
                    );
                    $updateReturn->execute([':id' => $withdrawalRequestId, ':previousStatus' => $previousReturnStatus]);
                    if ($updateReturn->rowCount() === 1) {
                        $eventName = $previousReturnStatus === 'refund_failed'
                            ? 'refund.confirmed_after_manual_resolution'
                            : 'refund.confirmed';
                        $event = $this->pdo->prepare(
                            'INSERT INTO withdrawal_request_events
                                (request_id, actor_role, event_name, previous_status, new_status, note)
                             VALUES (:id, \'system\', :eventName, :previousStatus, \'refunded\', :note)'
                        );
                        $event->execute([
                            ':id' => $withdrawalRequestId,
                            ':eventName' => $eventName,
                            ':previousStatus' => $previousReturnStatus,
                            ':note' => 'Refund attempt #' . $attempt . ' confirmed by Stripe.',
                        ]);
                    }
                }
            }
            $audit = $this->pdo->prepare(
                "INSERT INTO admin_audit_log (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, 'order.partial_refund_completed', 'order', :orderId, :previous, :new)"
            );
            $audit->execute([
                ':adminId' => $adminId,
                ':orderId' => $orderId,
                ':previous' => (string) $currentOrder['payment_status'],
                ':new' => $effectivePaymentStatus . ':' . $newRefunded . 'cents; attempt=' . $attempt . '; refund=' . $refundId,
            ]);
            if ($ownsTransaction) $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }


}

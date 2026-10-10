<?php

declare(strict_types=1);

trait RefundAdminActions
{
    use RefundAuditActions;
    private function retryAdminRefund(array $order, int $adminId): array
    {
        if ($order['payment_provider'] !== 'stripe'
            || !RefundStatePolicy::canStartNewAttempt((string) $order['payment_status'], (string) $order['payment_refund_outcome'])) {
            throw new HttpException('Só é seguro criar uma nova tentativa após uma recusa definitiva ou resolução manual de não reembolso. Consulte o Stripe antes de qualquer ação quando o resultado for desconhecido.', 409);
        }
        if (!in_array($order['status'], ['confirmed', 'preparing', 'cancelled'], true)) {
            throw new HttpException('O pedido não está em um estado que permita uma nova tentativa de reembolso.', 409);
        }
        return $this->startAdminRefundAttempt($order, $adminId);
    }

    private function startAdminRefundAttempt(array $order, int $adminId, ?int $withdrawalRequestId = null): array
    {
        $paymentIntentId = (string) ($order['payment_intent_id'] ?? '');
        if ($paymentIntentId === '') {
            throw new HttpException('O pagamento ainda não tem uma referência Stripe válida para reembolso.', 409);
        }
        $this->verifyProviderRefundLedger($order);
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
            $lock->execute([':id' => $order['id']]);
            $current = $lock->fetch();
            if (!$current || $current['payment_provider'] !== 'stripe'
                || !in_array($current['payment_status'], ['paid', 'partially_refunded'], true)
                || !in_array($current['payment_refund_outcome'], ['none','succeeded','definitive_failure','manual_refunded','manual_not_refunded'], true)
                || (string) $current['payment_intent_id'] !== $paymentIntentId) {
                throw new HttpException('O pagamento mudou ou possui uma tentativa pendente. Consulte o provedor antes de reenviar.', 409, [], 'refund_attempt_unresolved');
            }
            $order = $current;
            $previousPayment = RefundStatePolicy::settledPaymentStatus((int) $current['payment_refunded_cents'], $this->cents((string) $current['total']));
            $refundAmountCents = $this->cents((string) $current['total']) - (int) $current['payment_refunded_cents'];
            if ($refundAmountCents < 1) throw new HttpException('Não há saldo pago disponível para um reembolso integral.', 409);
            ExternalRefundService::assertReconciled($this->pdo, (int) $order['id']);
            if ($withdrawalRequestId === null) {
                $activeReturn = $this->pdo->prepare("SELECT id FROM withdrawal_requests WHERE order_id = :id
                    AND status IN ('submitted','reviewing','instructions_sent','return_received','refund_pending','refund_failed') LIMIT 1");
                $activeReturn->execute([':id' => $order['id']]);
                if ($activeReturn->fetchColumn()) throw new HttpException('Este pedido tem uma devolução aberta. Processe o reembolso pela fila de devoluções.', 409, [], 'withdrawal_request_active');
            }
            if ($withdrawalRequestId !== null) {
                $returnQuery = $this->pdo->prepare(
                    'SELECT order_id, status FROM withdrawal_requests WHERE id = :id LIMIT 1 FOR UPDATE'
                );
                $returnQuery->execute([':id' => $withdrawalRequestId]);
                $returnRequest = $returnQuery->fetch();
                if (!$returnRequest || (int) $returnRequest['order_id'] !== (int) $order['id']
                    || !in_array($returnRequest['status'], ['return_received', 'refund_failed'], true)) {
                    throw new HttpException('A solicitação de devolução não está pronta para reembolso.', 409, [], 'return_not_received');
                }
            }
            $orderStatuses = $withdrawalRequestId === null
                ? "('confirmed','preparing','cancelled')"
                : "('confirmed','preparing','cancelled','shipped','delivered')";
            $claim = $this->pdo->prepare(
                "UPDATE orders SET payment_status = 'refund_pending',
                        payment_refund_attempt = payment_refund_attempt + 1, payment_refund_id = NULL,
                        payment_refund_outcome = 'pending', payment_refund_error_code = NULL,
                        payment_refund_error_message = NULL, payment_refund_error_http_status = NULL
                 WHERE id = :id AND payment_status IN ('paid','partially_refunded')
                       AND payment_refund_outcome IN ('none','succeeded','definitive_failure','manual_refunded','manual_not_refunded')
                       AND status IN {$orderStatuses}"
            );
            $claim->execute([':id' => $order['id']]);
            if ($claim->rowCount() !== 1) {
                throw new HttpException('O estado do pagamento mudou; atualize os pedidos antes de tentar novamente.', 409);
            }
            $attemptQuery = $this->pdo->prepare('SELECT payment_refund_attempt FROM orders WHERE id = :id');
            $attemptQuery->execute([':id' => $order['id']]);
            $attempt = (int) $attemptQuery->fetchColumn();
            $ledger = $this->pdo->prepare(
                "INSERT INTO payment_refund_attempts
                    (order_id, attempt, attempt_kind, amount_cents, currency, previous_payment_status,
                     previous_order_status, withdrawal_request_id, status, reason, requested_by_admin_id)
                 VALUES (:orderId, :attempt, 'full', :amount, :currency, :previousPayment,
                         :previousOrder, :withdrawalRequestId, 'pending', :reason, :adminId)"
            );
            $ledger->execute([
                ':orderId' => $order['id'],
                ':attempt' => $attempt,
                ':amount' => $refundAmountCents,
                ':currency' => $order['currency'],
                ':previousPayment' => $previousPayment,
                ':previousOrder' => $order['status'],
                ':withdrawalRequestId' => $withdrawalRequestId,
                ':reason' => $withdrawalRequestId === null ? 'Admin full refund' : 'Customer withdrawal refund',
                ':adminId' => $adminId,
            ]);
            $audit = $this->pdo->prepare(
                "INSERT INTO admin_audit_log
                    (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, 'order.refund_requested', 'order', :orderId, :previous, :new)"
            );
            $audit->execute([
                ':adminId' => $adminId,
                ':orderId' => $order['id'],
                ':previous' => $order['status'] . ':' . $order['payment_status'] . ':' . $order['payment_refund_outcome'],
                ':new' => $order['status'] . ':refund_pending:attempt=' . $attempt,
            ]);
            if ($withdrawalRequestId !== null) {
                $updateReturn = $this->pdo->prepare("UPDATE withdrawal_requests SET status = 'refund_pending', handled_by_admin_id = :adminId WHERE id = :id");
                $updateReturn->execute([':adminId' => $adminId, ':id' => $withdrawalRequestId]);
                $event = $this->pdo->prepare(
                    "INSERT INTO withdrawal_request_events
                        (request_id, actor_user_id, actor_role, event_name, previous_status, new_status)
                     VALUES (:requestId, :adminId, 'admin', 'refund.started', :previous, 'refund_pending')"
                );
                $event->execute([':requestId' => $withdrawalRequestId, ':adminId' => $adminId, ':previous' => $returnRequest['status']]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $claimedOrder = $this->orderForAdmin((string) $order['order_number']);
        if (!$claimedOrder || (int) $claimedOrder['payment_refund_attempt'] !== $attempt
            || $claimedOrder['payment_status'] !== 'refund_pending') {
            throw new HttpException('A tentativa de reembolso não pôde ser confirmada após a reserva do estado.', 409);
        }
        return $this->performAdminRefundAttempt($claimedOrder, $paymentIntentId, $attempt, $adminId, $refundAmountCents, $withdrawalRequestId);
    }

    private function performAdminRefundAttempt(array $order, string $paymentIntentId, int $attempt, int $adminId, int $amountCents, ?int $withdrawalRequestId = null): array
    {
        try {
            $refund = $this->stripe->refundPaymentIntent($paymentIntentId, (string) $order['order_number'], $attempt, $amountCents);
            return $this->applyAdminRefundResult($order, $refund, $adminId, $withdrawalRequestId);
        } catch (StripeApiException $exception) {
            if ($exception->definitive) {
                if ($withdrawalRequestId !== null) {
                    $this->withdrawals->recordProviderRefundFailure(
                        $withdrawalRequestId,
                        (int) $order['id'],
                        $adminId,
                        $paymentIntentId,
                        $attempt,
                        null,
                        $exception->providerCode ?? ('stripe_http_' . ($exception->httpStatus ?? 'error')),
                        $exception->providerMessage,
                        $exception->httpStatus
                    );
                } else {
                    $this->recordDefinitiveRefundFailure(
                        (int) $order['id'],
                        $paymentIntentId,
                        $attempt,
                        null,
                        $exception->providerCode ?? ('stripe_http_' . ($exception->httpStatus ?? 'error')),
                        $exception->providerMessage,
                        $exception->httpStatus,
                        $adminId
                    );
                }
                return ['message' => 'A Stripe recusou definitivamente esta tentativa. O pagamento continua registrado como pago; consulte o motivo e use “Tentar novamente” para iniciar uma nova tentativa segura.'];
            }
            $this->recordUnknownRefundOutcome(
                (int) $order['id'],
                $attempt,
                null,
                $exception->providerCode ?? 'stripe_outcome_unknown',
                $exception->providerMessage,
                $exception->httpStatus,
                $adminId
            );
            return ['message' => 'Não foi possível confirmar o resultado junto à Stripe. Nenhuma nova tentativa foi criada; use “Consultar reembolso” antes de resolver manualmente.'];
        } catch (Throwable $exception) {
            $this->recordUnknownRefundOutcome(
                (int) $order['id'],
                $attempt,
                null,
                'transport_or_response_error',
                'No definitive response was received from the payment provider.',
                null,
                $adminId
            );
            error_log('Stripe refund outcome is unknown for order ' . $order['order_number'] . ': ' . $exception->getMessage());
            return ['message' => 'Não foi possível confirmar o resultado junto à Stripe. Nenhuma nova tentativa foi criada; use “Consultar reembolso” antes de resolver manualmente.'];
        }
    }

    private function reconcileAdminRefund(array $order, int $adminId, ?int $withdrawalRequestId = null): array
    {
        if ($order['payment_provider'] !== 'stripe'
            || !in_array($order['payment_status'], ['refund_pending', 'paid', 'partially_refunded'], true)
            || (int) $order['payment_refund_attempt'] < 1
            || (string) ($order['payment_intent_id'] ?? '') === '') {
            throw new HttpException('Não há uma tentativa Stripe de reembolso para consultar neste pedido.', 409);
        }

        $refund = null;
        $refundId = (string) ($order['payment_refund_id'] ?? '');
        if ($refundId !== '') {
            try {
                $refund = $this->stripe->retrieveRefund($refundId);
            } catch (StripeApiException $exception) {
                if ($exception->httpStatus !== 404) {
                    $this->recordReconciliationError($order, $exception, $adminId);
                    return ['message' => 'A Stripe não conseguiu concluir a consulta. O estado do pedido foi preservado; tente consultar novamente.'];
                }
            } catch (Throwable $exception) {
                $this->recordUnknownRefundOutcome(
                    (int) $order['id'],
                    (int) $order['payment_refund_attempt'],
                    null,
                    'refund_lookup_failed',
                    'Stripe could not be reached while checking the refund.',
                    null,
                    $adminId,
                    false
                );
                error_log('Stripe refund lookup failed for order ' . $order['order_number'] . ': ' . $exception->getMessage());
                return ['message' => 'A Stripe não conseguiu concluir a consulta. O estado do pedido foi preservado; tente consultar novamente.'];
            }
        }

        if ($refund === null) {
            try {
                $refunds = $this->stripe->refundsForPaymentIntent((string) $order['payment_intent_id']);
                foreach ($refunds as $candidate) {
                    if (!is_array($candidate)
                        || (string) ($candidate['metadata']['order_number'] ?? '') !== (string) $order['order_number']
                        || (int) ($candidate['metadata']['refund_attempt'] ?? 0) !== (int) $order['payment_refund_attempt']) {
                        continue;
                    }
                    $refund = $candidate;
                    break;
                }
            } catch (StripeApiException $exception) {
                $this->recordReconciliationError($order, $exception, $adminId);
                return ['message' => 'A Stripe não conseguiu concluir a consulta. O estado do pedido foi preservado; tente consultar novamente.'];
            } catch (Throwable $exception) {
                $this->recordUnknownRefundOutcome(
                    (int) $order['id'],
                    (int) $order['payment_refund_attempt'],
                    null,
                    'refund_lookup_failed',
                    'Stripe could not be reached while checking the refund.',
                    null,
                    $adminId,
                    false
                );
                error_log('Stripe refund list failed for order ' . $order['order_number'] . ': ' . $exception->getMessage());
                return ['message' => 'A Stripe não conseguiu concluir a consulta. O estado do pedido foi preservado; tente consultar novamente.'];
            }
        }

        if ($refund === null) {
            $this->recordRefundNotFound($order, $adminId);
            return ['message' => 'Nenhum reembolso desta tentativa está visível na Stripe agora. Nenhuma nova cobrança ou tentativa foi enviada; consulte novamente mais tarde ou faça uma resolução manual auditada.'];
        }
        return $this->applyAdminRefundResult($order, $refund, $adminId, $withdrawalRequestId);
    }

    private function recordDefinitiveRefundFailure(
        int $orderId,
        string $paymentIntentId,
        int $attempt,
        ?string $refundId,
        string $errorCode,
        string $errorMessage,
        ?int $httpStatus,
        ?int $adminId
    ): void {
        $errorCode = $this->boundedProviderCode($errorCode);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT payment_status, payment_refund_attempt, payment_intent_id, payment_refunded_cents, total FROM orders WHERE id = :id FOR UPDATE');
            $lock->execute([':id' => $orderId]);
            $current = $lock->fetch();
            $ledgerLock = $this->pdo->prepare('SELECT status, stripe_refund_id FROM payment_refund_attempts WHERE order_id = :id AND attempt = :attempt FOR UPDATE');
            $ledgerLock->execute([':id' => $orderId, ':attempt' => $attempt]);
            $ledger = $ledgerLock->fetch();
            if (!$current || $current['payment_status'] !== 'refund_pending'
                || (int) $current['payment_refund_attempt'] !== $attempt || $current['payment_intent_id'] !== $paymentIntentId
                || ($ledger && !in_array($ledger['status'], ['pending', 'unknown', 'failed'], true))) {
                if ($ownsTransaction) $this->pdo->commit();
                return;
            }
            if ($ledger && $refundId !== null && $ledger['stripe_refund_id'] !== null && $ledger['stripe_refund_id'] !== $refundId) {
                throw new HttpException('A referência do reembolso diverge da tentativa vinculada.', 409, [], 'stripe_refund_attempt_mismatch');
            }
            $settledStatus = RefundStatePolicy::settledPaymentStatus((int) $current['payment_refunded_cents'], $this->cents((string) $current['total']));
            $update = $this->pdo->prepare(
                "UPDATE orders SET payment_status = :settledStatus, payment_refund_outcome = 'definitive_failure',
                        payment_refund_id = COALESCE(:refundId, payment_refund_id),
                        payment_refund_error_code = :errorCode, payment_refund_error_message = :errorMessage,
                        payment_refund_error_http_status = :httpStatus
                 WHERE id = :id AND payment_status = 'refund_pending'
                       AND payment_intent_id = :paymentIntent AND payment_refund_attempt = :attempt"
            );
            $update->execute([
                ':settledStatus' => $settledStatus,
                ':refundId' => $refundId,
                ':errorCode' => $errorCode,
                ':errorMessage' => $this->boundedDiagnostic($errorMessage),
                ':httpStatus' => $httpStatus,
                ':id' => $orderId,
                ':paymentIntent' => $paymentIntentId,
                ':attempt' => $attempt,
            ]);
            if ($update->rowCount() === 1) {
                if ($ledger) {
                    $saveLedger = $this->pdo->prepare("UPDATE payment_refund_attempts SET status = 'failed',
                        stripe_refund_id = COALESCE(:refundId, stripe_refund_id), provider_error_code = :code,
                        provider_error_message = :message WHERE order_id = :id AND attempt = :attempt");
                    $saveLedger->execute([':refundId' => $refundId, ':code' => $errorCode,
                        ':message' => $this->boundedDiagnostic($errorMessage), ':id' => $orderId, ':attempt' => $attempt]);
                }
                $this->auditRefundState($adminId, $orderId, 'order.refund_failed', 'refund_pending', $settledStatus . ':definitive_failure', $errorCode . ': ' . $errorMessage);
            }
            if ($ownsTransaction) $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function recordUnknownRefundOutcome(
        int $orderId,
        int $attempt,
        ?string $refundId,
        string $errorCode,
        string $errorMessage,
        ?int $httpStatus,
        ?int $adminId,
        bool $unknownState = true
    ): void {
        $errorCode = $this->boundedProviderCode($errorCode);
        $update = $this->pdo->prepare(
            "UPDATE orders SET payment_refund_outcome = IF(:unknownState = 1 AND payment_status = 'refund_pending', 'unknown', payment_refund_outcome),
                    payment_refund_id = COALESCE(:refundId, payment_refund_id),
                    payment_refund_error_code = :errorCode, payment_refund_error_message = :errorMessage,
                    payment_refund_error_http_status = :httpStatus
             WHERE id = :id AND payment_refund_attempt = :attempt AND payment_status IN ('refund_pending','paid')"
        );
        $update->execute([
            ':unknownState' => $unknownState ? 1 : 0,
            ':refundId' => $refundId,
            ':errorCode' => $errorCode,
            ':errorMessage' => $this->boundedDiagnostic($errorMessage),
            ':httpStatus' => $httpStatus,
            ':id' => $orderId,
            ':attempt' => $attempt,
        ]);
        if ($update->rowCount() === 1) {
            $this->auditRefundState($adminId, $orderId, 'order.refund_outcome_unknown', 'refund_pending', 'unknown', $errorCode . ': ' . $errorMessage);
        }
    }

    private function recordRefundProcessing(array $order, string $refundId, int $adminId): void
    {
        $update = $this->pdo->prepare(
            "UPDATE orders SET payment_refund_outcome = 'pending', payment_refund_id = COALESCE(:refundId, payment_refund_id),
                    payment_refund_error_code = NULL, payment_refund_error_message = NULL,
                    payment_refund_error_http_status = NULL
             WHERE id = :id AND payment_refund_attempt = :attempt AND payment_status = 'refund_pending'"
        );
        $update->execute([
            ':refundId' => $refundId !== '' ? $refundId : null,
            ':id' => $order['id'],
            ':attempt' => $order['payment_refund_attempt'],
        ]);
        if ($update->rowCount() === 1) {
            $this->auditRefundState($adminId, (int) $order['id'], 'order.refund_processing', 'refund_pending', 'refund_pending:pending', 'Stripe reports the refund as pending.');
        }
    }

    private function recordReconciliationError(array $order, StripeApiException $exception, int $adminId): void
    {
        $this->recordUnknownRefundOutcome(
            (int) $order['id'],
            (int) $order['payment_refund_attempt'],
            null,
            $exception->providerCode ?? ('stripe_reconciliation_http_' . ($exception->httpStatus ?? 'error')),
            $exception->providerMessage,
            $exception->httpStatus,
            $adminId,
            false
        );
    }

    private function recordRefundNotFound(array $order, int $adminId): void
    {
        $update = $this->pdo->prepare(
            "UPDATE orders SET payment_refund_outcome = IF(payment_status = 'refund_pending', 'unknown', payment_refund_outcome),
                    payment_refund_error_code = 'refund_not_found',
                    payment_refund_error_message = 'No matching refund is currently visible in Stripe; no new refund was sent.',
                    payment_refund_error_http_status = NULL
             WHERE id = :id AND payment_refund_attempt = :attempt AND payment_status IN ('refund_pending','paid')"
        );
        $update->execute([':id' => $order['id'], ':attempt' => $order['payment_refund_attempt']]);
        if ($update->rowCount() === 1) {
            $this->auditRefundState($adminId, (int) $order['id'], 'order.refund_reconciled_not_found', 'refund_pending', (string) $order['payment_status'], 'Stripe list contained no matching order number and attempt; no refund was resent.');
        }
    }

    private function resolveAdminRefund(array $order, int $adminId, string $resolution, string $reason): array
    {
        if ($order['payment_provider'] !== 'stripe'
            || !in_array($order['payment_status'], ['refund_pending', 'paid', 'partially_refunded'], true)
            || (int) $order['payment_refund_attempt'] < 1
            || !in_array($order['status'], ['confirmed', 'preparing', 'cancelled'], true)) {
            throw new HttpException('A resolução manual só pode ser registrada para uma tentativa Stripe de reembolso ainda não finalizada.', 409);
        }

        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare('SELECT status, payment_status, payment_refund_outcome, payment_refund_attempt, payment_refunded_cents, total, currency FROM orders WHERE id = :id FOR UPDATE');
            $select->execute([':id' => $order['id']]);
            $current = $select->fetch();
            if (!$current || !in_array($current['payment_status'], ['refund_pending', 'paid', 'partially_refunded'], true)
                || (int) $current['payment_refund_attempt'] !== (int) $order['payment_refund_attempt']
                || !in_array($current['status'], ['confirmed', 'preparing', 'cancelled'], true)) {
                throw new HttpException('O pedido mudou durante a resolução. Atualize a lista e confira novamente.', 409);
            }

            $attemptLock = $this->pdo->prepare('SELECT * FROM payment_refund_attempts WHERE order_id = :orderId AND attempt = :attempt LIMIT 1 FOR UPDATE');
            $attemptLock->execute([':orderId' => $order['id'], ':attempt' => $current['payment_refund_attempt']]);
            $refundAttempt = $attemptLock->fetch();
            if ($refundAttempt && ($refundAttempt['attempt_kind'] ?? 'partial') !== 'full') {
                throw new HttpException('Esta tentativa é parcial e precisa ser resolvida pelo fluxo de reembolsos parciais.', 409);
            }
            if ($refundAttempt && $refundAttempt['status'] === 'succeeded') {
                throw new HttpException('A Stripe já confirmou esta tentativa; não é possível sobrescrevê-la manualmente.', 409);
            }
            if (!$refundAttempt) {
                // Backfill a legacy full attempt so late Stripe webhooks can still be
                // matched to an auditable, amount-bound ledger record.
                $legacyAmount = $this->cents((string) $current['total']) - (int) $current['payment_refunded_cents'];
                if ($legacyAmount < 1) {
                    throw new HttpException('Não há saldo restante que corresponda a esta tentativa integral.', 409);
                }
                $backfill = $this->pdo->prepare(
                    "INSERT INTO payment_refund_attempts
                        (order_id, attempt, attempt_kind, amount_cents, currency, previous_payment_status,
                         previous_order_status, status, reason, requested_by_admin_id)
                     VALUES (:orderId, :attempt, 'full', :amount, :currency, 'paid', :previousOrder,
                             'unknown', 'Legacy full refund attempt', :adminId)"
                );
                $backfill->execute([
                    ':orderId' => $order['id'], ':attempt' => $current['payment_refund_attempt'],
                    ':amount' => $legacyAmount, ':currency' => $current['currency'],
                    ':previousOrder' => $current['status'], ':adminId' => $adminId,
                ]);
                $attemptLock->execute([':orderId' => $order['id'], ':attempt' => $current['payment_refund_attempt']]);
                $refundAttempt = $attemptLock->fetch();
            }
            if (!$refundAttempt || !in_array($refundAttempt['status'], ['pending', 'unknown', 'failed'], true)) {
                throw new HttpException('A tentativa não está em um estado que possa ser resolvido manualmente.', 409);
            }

            if ($resolution === 'refunded' && $current['status'] !== 'cancelled') {
                $this->restoreStock((int) $order['id']);
            }
            $attemptAmount = (int) $refundAttempt['amount_cents'];
            $refundedCents = (int) $current['payment_refunded_cents'];
            if ($resolution === 'refunded') {
                $refundedCents += $attemptAmount;
                if ($refundedCents !== $this->cents((string) $current['total'])) {
                    throw new HttpException('O valor desta tentativa não fecha exatamente o saldo total restante do pedido.', 409);
                }
            }
            $recordResolution = $this->pdo->prepare(
                "UPDATE payment_refund_attempts SET status = 'manual',
                        provider_error_code = :code, provider_error_message = :reason
                 WHERE order_id = :orderId AND attempt = :attempt AND status IN ('pending','unknown','failed')"
            );
            $recordResolution->execute([
                ':code' => $resolution === 'refunded' ? 'manual_refunded' : 'manual_not_refunded',
                ':reason' => substr($reason, 0, 500), ':orderId' => $order['id'],
                ':attempt' => $current['payment_refund_attempt'],
            ]);
            if ($recordResolution->rowCount() !== 1) {
                throw new HttpException('A tentativa mudou durante a resolução manual.', 409);
            }
            $newPaymentStatus = RefundStatePolicy::settledPaymentStatus($refundedCents, $this->cents((string) $current['total']));
            $outcome = $resolution === 'refunded' ? 'manual_refunded' : 'manual_not_refunded';
            $newStatus = $resolution === 'refunded' ? 'cancelled' : $current['status'];
            $update = $this->pdo->prepare(
                'UPDATE orders SET status = :status, payment_status = :paymentStatus,
                        payment_refunded_cents = :refundedCents,
                        payment_refund_outcome = :outcome, reservation_expires_at = NULL
                 WHERE id = :id AND payment_refund_attempt = :attempt'
            );
            $update->execute([
                ':status' => $newStatus,
                ':paymentStatus' => $newPaymentStatus,
                ':refundedCents' => $refundedCents,
                ':outcome' => $outcome,
                ':id' => $order['id'],
                ':attempt' => $current['payment_refund_attempt'],
            ]);
            if ($resolution === 'refunded' && ($order['payment_provider'] ?? null) === 'stripe') {
                $this->queueFullRefundConfirmation((int) $order['id'], 'manual-full-' . $current['payment_refund_attempt']);
            }
            $audit = $this->pdo->prepare(
                "INSERT INTO admin_audit_log
                    (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, 'order.refund_resolved_manually', 'order', :orderId, :previous, :new)"
            );
            $audit->execute([
                ':adminId' => $adminId,
                ':orderId' => $order['id'],
                ':previous' => $current['status'] . ':' . $current['payment_status'] . ':' . $current['payment_refund_outcome'],
                ':new' => $newStatus . ':' . $newPaymentStatus . ':' . $outcome . '; attempt=' . $current['payment_refund_attempt'] . '; reason=' . $reason,
            ]);
            $this->pdo->commit();
            return ['message' => $resolution === 'refunded'
                ? 'Resolução manual registrada como reembolsado; pedido cancelado e estoque liberado uma única vez.'
                : 'Resolução manual registrada como não reembolsado; o pagamento segue pago e pode receber nova tentativa.'];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }




}

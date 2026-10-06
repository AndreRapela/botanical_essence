<?php

declare(strict_types=1);

/** Focused payment-domain operations extracted from the payment facade. */
trait PaymentWebhookActions
{
    public static function isRefundWebhookType(string $type): bool
    {
        return in_array($type, ['refund.created', 'refund.updated', 'refund.failed'], true);
    }

    public function handleWebhook(string $payload, string $signature): array
    {
        $secret = trim((string) getenv('STRIPE_WEBHOOK_SECRET'));
        if (!PaymentPolicy::validStripeSignature($payload, $signature, $secret)) {
            throw new HttpException('Assinatura do webhook inválida.', 400);
        }
        try {
            $event = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException('Evento de pagamento inválido.', 400);
        }
        if (!is_array($event) || !is_string($event['id'] ?? null) || !is_string($event['type'] ?? null)) {
            throw new HttpException('Evento de pagamento inválido.', 400);
        }
        if (!PaymentPolicy::stripeObjectMatchesEnvironment($event)) {
            throw new HttpException('O evento Stripe pertence a um modo incompatível com este ambiente.', 409, [], 'stripe_event_mode_mismatch');
        }
        $session = $event['data']['object'] ?? null;
        if (!is_array($session)) {
            throw new HttpException('Conteúdo do evento de pagamento inválido.', 400);
        }
        if (isset($session['livemode']) && $session['livemode'] !== $event['livemode']) {
            throw new HttpException('O modo da sessão Stripe diverge do modo declarado pelo evento.', 409, [], 'stripe_event_mode_mismatch');
        }

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare('INSERT IGNORE INTO payment_webhook_events (event_id, event_type) VALUES (:id, :type)');
            $insert->execute([':id' => $event['id'], ':type' => $event['type']]);
            if ($insert->rowCount() === 0) {
                $this->pdo->commit();
                return ['received' => true, 'duplicate' => true];
            }
            if (in_array($event['type'], ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
                $this->applyCheckoutEvent($session, $event['type'] === 'checkout.session.async_payment_succeeded' || ($session['payment_status'] ?? '') === 'paid');
            } elseif (in_array($event['type'], ['checkout.session.expired', 'checkout.session.async_payment_failed'], true)) {
                $sessionId = (string) ($session['id'] ?? '');
                if ($sessionId !== '') {
                    $order = $this->latePaymentRecovery->orderForCheckoutSession($session, $sessionId);
                    if ($order !== null) {
                        if (!PaymentPolicy::stripeObjectMatchesOrder($session, $order)) {
                            throw new HttpException('A sessão Stripe não corresponde ao ambiente e ao modo deste pedido.', 409, [], 'stripe_session_mode_mismatch');
                        }
                        $this->cancelUnpaidOrder((int) $order['id'], 'failed');
                    }
                }
            } elseif (self::isRefundWebhookType($event['type'])) {
                $this->applyRefundEvent($session);
            } elseif (in_array($event['type'], [
                'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed',
                'charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated',
            ], true)) {
                $this->operations->recordDispute($session);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $this->operations->dispatchOutbox(20);
        return ['received' => true];
    }

    private function applyCheckoutEvent(array $session, bool $paid): void
    {
        if (!PaymentPolicy::stripeObjectMatchesEnvironment($session)) {
            throw new HttpException('A sessão Stripe pertence a um modo incompatível com este ambiente.', 409, [], 'stripe_session_mode_mismatch');
        }
        $sessionId = (string) ($session['id'] ?? '');
        if ($sessionId === '') {
            return;
        }
        $order = $this->latePaymentRecovery->orderForCheckoutSession($session, $sessionId);
        if ($order === null) {
            return;
        }
        if (!PaymentPolicy::stripeObjectMatchesOrder($session, $order)) {
            throw new HttpException('A sessão Stripe não corresponde ao ambiente e ao modo deste pedido.', 409, [], 'stripe_session_mode_mismatch');
        }
        if (strtolower((string) ($session['currency'] ?? '')) !== strtolower((string) $order['currency'])
            || (int) ($session['amount_total'] ?? -1) !== $this->cents((string) $order['total'])) {
            throw new HttpException('Os dados confirmados pelo provedor não correspondem ao pedido.', 409);
        }
        if ($order['status'] === 'cancelled') {
            if ($paid) {
                // Never silently discard a late success. This is a last-resort
                // compensation path; normal cancellation expires the session first.
                $this->latePaymentRecovery->refundCancelledOrderPayment($order, $session, $sessionId);
            }
            return;
        }
        if (!$paid) {
            return;
        }

        $paymentIntent = $session['payment_intent'] ?? null;
        $paymentIntentId = is_array($paymentIntent) ? ($paymentIntent['id'] ?? null) : $paymentIntent;
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        $cancelledOrder = null;
        try {
            $update = $this->pdo->prepare(
                "UPDATE orders SET payment_status = 'paid', status = 'confirmed',
                        payment_intent_id = :paymentIntent, reservation_expires_at = NULL
                 WHERE id = :id AND payment_status IN ('pending','paid') AND status = 'awaiting_payment'"
            );
            $update->execute([':paymentIntent' => $paymentIntentId, ':id' => $order['id']]);

            if ($update->rowCount() === 1) {
                $this->queuePaymentConfirmation((int) $order['id']);
            }

            if ($update->rowCount() === 0) {
                $latestOrder = $this->orderForAdmin((string) $order['order_number']);
                if ($latestOrder && $latestOrder['status'] === 'cancelled') {
                    $cancelledOrder = $latestOrder;
                } elseif ($latestOrder && $latestOrder['payment_status'] === 'paid'
                    && in_array($latestOrder['status'], ['confirmed', 'preparing', 'shipped', 'delivered'], true)) {
                    // Repair an outbox gap left by older non-transactional confirmations.
                    $this->queuePaymentConfirmation((int) $latestOrder['id']);
                }
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        if ($cancelledOrder !== null) {
            // Do provider-side compensation only after releasing a transaction
            // owned by this status-check path.
            $this->latePaymentRecovery->refundCancelledOrderPayment($cancelledOrder, $session, $sessionId);
        }
    }

    protected function queuePaymentConfirmation(int $orderId): void
    {
        $queue = $this->pdo->prepare(
            "INSERT IGNORE INTO payment_notification_outbox (order_id, notification_type)
             VALUES (:orderId, 'payment_confirmation')"
        );
        $queue->execute([':orderId' => $orderId]);
    }

    private function applyRefundEvent(array $refund): void
    {
        if ($this->applyPartialRefundEvent($refund)) {
            return;
        }
        if ($this->applyFullRefundAttemptEvent($refund)) {
            return;
        }
        $paymentIntent = $refund['payment_intent'] ?? null;
        $paymentIntentId = is_array($paymentIntent) ? ($paymentIntent['id'] ?? '') : (string) $paymentIntent;
        if ($paymentIntentId === '') {
            return;
        }
        $find = $this->pdo->prepare(
            "SELECT id, order_number, status, payment_status, payment_provider, payment_refund_attempt,
                    payment_refund_id, payment_refunded_cents, payment_intent_id, total, currency, test_payment
             FROM orders
             WHERE payment_intent_id = :paymentIntent LIMIT 1 FOR UPDATE"
        );
        $find->execute([':paymentIntent' => $paymentIntentId]);
        $order = $find->fetch();
        if (!$order || $order['payment_status'] !== 'refund_pending') {
            return;
        }
        if (!PaymentPolicy::stripeObjectMatchesOrder($refund, $order)) {
            throw new HttpException('O reembolso Stripe pertence a um modo incompatível com este pedido.', 409, [], 'stripe_event_mode_mismatch');
        }
        $eventAttempt = $refund['metadata']['refund_attempt'] ?? null;
        if ($eventAttempt !== null && (int) $eventAttempt !== (int) $order['payment_refund_attempt']) {
            return;
        }
        if ($order['payment_refund_id'] !== null && isset($refund['id'])
            && !hash_equals((string) $order['payment_refund_id'], (string) $refund['id'])) {
            throw new HttpException('A Stripe informou um reembolso diferente da tentativa pendente.', 409, [], 'stripe_refund_attempt_mismatch');
        }
        $expectedRefundCents = $this->cents((string) $order['total']) - (int) $order['payment_refunded_cents'];
        PaymentPolicy::assertStripeRefundMatchesAttempt(
            $refund,
            $order,
            (int) $order['payment_refund_attempt'],
            $expectedRefundCents
        );
        $returnQuery = $this->pdo->prepare(
            "SELECT id FROM withdrawal_requests WHERE order_id = :orderId AND status = 'refund_pending' ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $returnQuery->execute([':orderId' => $order['id']]);
        $withdrawalRequestId = $returnQuery->fetchColumn();
        if (in_array(($refund['status'] ?? ''), ['failed', 'canceled'], true)) {
            $failureReason = (string) ($refund['failure_reason'] ?? (($refund['status'] ?? '') === 'canceled' ? 'refund_canceled' : 'refund_failed'));
            $orderStatuses = $withdrawalRequestId
                ? "('confirmed','preparing','shipped','delivered')"
                : "('confirmed','preparing')";
            $restorePayment = $this->pdo->prepare(
                "UPDATE orders SET payment_status = 'paid', payment_refund_outcome = 'definitive_failure',
                        payment_refund_id = COALESCE(:refundId, payment_refund_id), payment_refund_error_code = :errorCode,
                        payment_refund_error_message = :errorMessage, payment_refund_error_http_status = NULL
                 WHERE id = :id AND payment_status = 'refund_pending'
                       AND (status IN {$orderStatuses} OR (status = 'cancelled' AND payment_provider = 'stripe'))"
            );
            $restorePayment->execute([
                ':refundId' => $refund['id'] ?? null,
                ':errorCode' => $failureReason,
                ':errorMessage' => $this->boundedDiagnostic('Stripe refund status: ' . ($refund['status'] ?? 'failed') . ' (' . $failureReason . ')'),
                ':id' => $order['id'],
            ]);
            if ($restorePayment->rowCount() === 1) {
                $audit = $this->pdo->prepare(
                    "INSERT INTO admin_audit_log
                        (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                     VALUES (NULL, 'order.refund_failed', 'order', :orderId, :previous, :new)"
                );
                $audit->execute([
                    ':orderId' => $order['id'],
                    ':previous' => $order['status'] . ':refund_pending',
                    ':new' => $order['status'] . ':paid:definitive_failure; ' . $failureReason,
                ]);
                if ($withdrawalRequestId) {
                    $updateReturn = $this->pdo->prepare("UPDATE withdrawal_requests SET status = 'refund_failed' WHERE id = :id AND status = 'refund_pending'");
                    $updateReturn->execute([':id' => $withdrawalRequestId]);
                    $event = $this->pdo->prepare(
                        "INSERT INTO withdrawal_request_events
                            (request_id, actor_role, event_name, previous_status, new_status, note)
                         VALUES (:id, 'system', 'refund.failed', 'refund_pending', 'refund_failed', :note)"
                    );
                    $event->execute([':id' => $withdrawalRequestId, ':note' => $failureReason]);
                }
            }
            return;
        }
        if (in_array(($refund['status'] ?? ''), ['pending', 'requires_action'], true)) {
            $savePending = $this->pdo->prepare(
                "UPDATE orders SET payment_refund_id = COALESCE(:refundId, payment_refund_id), payment_refund_outcome = 'pending',
                        payment_refund_error_code = NULL, payment_refund_error_message = NULL,
                        payment_refund_error_http_status = NULL
                 WHERE id = :id AND payment_status = 'refund_pending'
                       AND payment_refund_attempt = :attempt"
            );
            $savePending->execute([
                ':refundId' => $refund['id'] ?? null,
                ':id' => $order['id'],
                ':attempt' => $order['payment_refund_attempt'],
            ]);
            return;
        }
        if (($refund['status'] ?? '') !== 'succeeded') {
            return;
        }
        if ($withdrawalRequestId) {
            $updateOrder = $this->pdo->prepare(
                "UPDATE orders SET payment_status = 'refunded', payment_refunded_cents = ROUND(total * 100), payment_refund_id = :refundId,
                        payment_refund_outcome = 'succeeded', payment_refund_error_code = NULL,
                        payment_refund_error_message = NULL, payment_refund_error_http_status = NULL,
                        reservation_expires_at = NULL
                 WHERE id = :id AND payment_status = 'refund_pending'
                       AND payment_refund_attempt = :attempt AND status IN ('confirmed','preparing','shipped','delivered')"
            );
            $updateOrder->execute([
                ':refundId' => $refund['id'] ?? null,
                ':id' => $order['id'],
                ':attempt' => $order['payment_refund_attempt'],
            ]);
            if ($updateOrder->rowCount() === 1) {
                $this->queueFullRefundConfirmation((int) $order['id'], (string) ($refund['id'] ?? ('refund-' . $order['payment_refund_attempt'])));
                $updateReturn = $this->pdo->prepare("UPDATE withdrawal_requests SET status = 'refunded' WHERE id = :id AND status = 'refund_pending'");
                $updateReturn->execute([':id' => $withdrawalRequestId]);
                $event = $this->pdo->prepare(
                    "INSERT INTO withdrawal_request_events
                        (request_id, actor_role, event_name, previous_status, new_status)
                     VALUES (:id, 'system', 'refund.confirmed', 'refund_pending', 'refunded')"
                );
                $event->execute([':id' => $withdrawalRequestId]);
                $audit = $this->pdo->prepare(
                    "INSERT INTO admin_audit_log
                        (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                     VALUES (NULL, 'order.withdrawal_refund_completed', 'order', :orderId, :previous, :new)"
                );
                $audit->execute([':orderId' => $order['id'], ':previous' => $order['status'] . ':refund_pending', ':new' => $order['status'] . ':refunded']);
            }
            return;
        }
        if ($order['status'] !== 'cancelled') {
            if (!in_array($order['status'], ['confirmed', 'preparing'], true)) {
                return;
            }
            $this->restoreStock((int) $order['id']);
            $audit = $this->pdo->prepare(
                "INSERT INTO admin_audit_log
                    (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (NULL, 'order.refund_completed', 'order', :orderId, :previous, 'cancelled:refunded')"
            );
            $audit->execute([
                ':orderId' => $order['id'],
                ':previous' => $order['status'] . ':refund_pending',
            ]);
        } else {
            $audit = $this->pdo->prepare(
                "INSERT INTO admin_audit_log
                    (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (NULL, 'order.late_payment_refunded', 'order', :orderId,
                         'cancelled:refund_pending', 'cancelled:refunded')"
            );
            $audit->execute([':orderId' => $order['id']]);
        }
        $statement = $this->pdo->prepare(
            "UPDATE orders SET status = 'cancelled', payment_status = 'refunded', payment_refunded_cents = ROUND(total * 100),
                    payment_refund_id = :refundId, payment_refund_outcome = 'succeeded',
                    payment_refund_error_code = NULL, payment_refund_error_message = NULL,
                    payment_refund_error_http_status = NULL, reservation_expires_at = NULL
             WHERE id = :id AND payment_status = 'refund_pending'"
        );
        $statement->execute([':refundId' => $refund['id'] ?? null, ':id' => $order['id']]);
        if ($statement->rowCount() === 1) {
            $this->queueFullRefundConfirmation((int) $order['id'], (string) ($refund['id'] ?? ('refund-' . $order['payment_refund_attempt'])));
        }
    }

    /** Reconcile full refunds from their immutable amount-bound ledger entry. */
    private function applyFullRefundAttemptEvent(array $refund, ?int $adminId = null): bool
    {
        $paymentIntent = $refund['payment_intent'] ?? null;
        $paymentIntentId = is_array($paymentIntent) ? (string) ($paymentIntent['id'] ?? '') : (string) $paymentIntent;
        $refundId = (string) ($refund['id'] ?? '');
        $attempt = isset($refund['metadata']['refund_attempt']) ? (int) $refund['metadata']['refund_attempt'] : 0;
        if ($paymentIntentId === '' || $refundId === '') return false;

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $find = $this->pdo->prepare(
                "SELECT r.*, o.order_number, o.payment_intent_id, o.payment_status, o.payment_provider,
                        o.test_payment, o.status AS current_order_status, o.payment_refund_attempt,
                        o.payment_refund_id, o.payment_refund_outcome, o.payment_refunded_cents,
                        o.payment_refund_error_code, o.total, o.currency
                 FROM payment_refund_attempts r INNER JOIN orders o ON o.id = r.order_id
                 WHERE r.attempt_kind = 'full' AND o.payment_intent_id = :paymentIntent AND (
                       (:attemptFilter > 0 AND r.attempt = :attemptMatch) OR r.stripe_refund_id = :refundId
                 ) LIMIT 1 FOR UPDATE"
            );
            $find->execute([
                ':paymentIntent' => $paymentIntentId, ':attemptFilter' => $attempt,
                ':attemptMatch' => $attempt, ':refundId' => $refundId,
            ]);
            $row = $find->fetch();
            if (!$row) {
                if ($ownsTransaction) $this->pdo->commit();
                return false;
            }

            $order = [
                'order_number' => (string) $row['order_number'],
                'payment_provider' => (string) $row['payment_provider'],
                'payment_intent_id' => (string) $row['payment_intent_id'],
                'test_payment' => $row['test_payment'],
                'currency' => (string) $row['currency'],
            ];
            PaymentPolicy::assertStripeRefundMatchesAttempt($refund, $order, (int) $row['attempt'], (int) $row['amount_cents']);
            if ($row['stripe_refund_id'] !== null && !hash_equals((string) $row['stripe_refund_id'], $refundId)) {
                throw new HttpException('A Stripe vinculou mais de um reembolso à mesma tentativa integral.', 409, [], 'stripe_refund_attempt_mismatch');
            }

            $status = (string) ($refund['status'] ?? 'unknown');
            if ($row['status'] === 'succeeded') {
                if ($ownsTransaction) $this->pdo->commit();
                return true;
            }
            if ($row['status'] === 'manual' && $status !== 'succeeded') {
                $saveId = $this->pdo->prepare(
                    'UPDATE payment_refund_attempts SET stripe_refund_id = COALESCE(stripe_refund_id, :refundId)
                     WHERE order_id = :orderId AND attempt = :attempt AND status = \'manual\''
                );
                $saveId->execute([':refundId' => $refundId, ':orderId' => $row['order_id'], ':attempt' => $row['attempt']]);
                if ($ownsTransaction) $this->pdo->commit();
                return true;
            }

            $currentAttempt = (int) $row['payment_refund_attempt'] === (int) $row['attempt'];
            if ($row['status'] === 'failed' && in_array($status, ['pending', 'requires_action'], true)) {
                // Stripe webhook delivery is unordered: an old `pending` event must
                // not reopen a refund already known to have failed definitively.
                $saveId = $this->pdo->prepare(
                    'UPDATE payment_refund_attempts SET stripe_refund_id = COALESCE(stripe_refund_id, :refundId)
                     WHERE order_id = :orderId AND attempt = :attempt AND status = \'failed\''
                );
                $saveId->execute([':refundId' => $refundId, ':orderId' => $row['order_id'], ':attempt' => $row['attempt']]);
                if ($ownsTransaction) $this->pdo->commit();
                return true;
            }
            if (in_array($status, ['pending', 'requires_action'], true)) {
                $saveAttempt = $this->pdo->prepare(
                    "UPDATE payment_refund_attempts SET stripe_refund_id = :refundId, status = 'pending',
                            provider_error_code = NULL, provider_error_message = NULL
                     WHERE order_id = :orderId AND attempt = :attempt AND status <> 'succeeded'"
                );
                $saveAttempt->execute([':refundId' => $refundId, ':orderId' => $row['order_id'], ':attempt' => $row['attempt']]);
                if ($currentAttempt && $row['payment_status'] === 'refund_pending') {
                    $saveOrder = $this->pdo->prepare(
                        "UPDATE orders SET payment_refund_id = :refundId, payment_refund_outcome = 'pending',
                                payment_refund_error_code = NULL, payment_refund_error_message = NULL,
                                payment_refund_error_http_status = NULL
                         WHERE id = :id AND payment_status = 'refund_pending' AND payment_refund_attempt = :attempt"
                    );
                    $saveOrder->execute([':refundId' => $refundId, ':id' => $row['order_id'], ':attempt' => $row['attempt']]);
                }
                if ($ownsTransaction) $this->pdo->commit();
                return true;
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                $reason = (string) ($refund['failure_reason'] ?? ($status === 'canceled' ? 'refund_canceled' : 'refund_failed'));
                $saveAttempt = $this->pdo->prepare(
                    "UPDATE payment_refund_attempts SET stripe_refund_id = :refundId, status = 'failed',
                            provider_error_code = :errorCode, provider_error_message = :message
                     WHERE order_id = :orderId AND attempt = :attempt AND status <> 'succeeded'"
                );
                $saveAttempt->execute([
                    ':refundId' => $refundId, ':errorCode' => substr($reason, 0, 100),
                    ':message' => 'Stripe refund status: ' . $status, ':orderId' => $row['order_id'], ':attempt' => $row['attempt'],
                ]);
                if ($currentAttempt && $row['payment_status'] === 'refund_pending') {
                    $saveOrder = $this->pdo->prepare(
                        "UPDATE orders SET payment_status = 'paid', payment_refund_id = :refundId,
                                payment_refund_outcome = 'definitive_failure', payment_refund_error_code = :errorCode,
                                payment_refund_error_message = :message, payment_refund_error_http_status = NULL
                         WHERE id = :id AND payment_status = 'refund_pending' AND payment_refund_attempt = :attempt"
                    );
                    $saveOrder->execute([
                        ':refundId' => $refundId, ':errorCode' => substr($reason, 0, 100),
                        ':message' => 'Stripe refund status: ' . $status, ':id' => $row['order_id'], ':attempt' => $row['attempt'],
                    ]);
                }
                $audit = $this->pdo->prepare(
                    "INSERT INTO admin_audit_log (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                     VALUES (:adminId, 'order.full_refund_failed', 'order', :orderId, :previous, :new)"
                );
                $audit->execute([
                    ':adminId' => $adminId, ':orderId' => $row['order_id'],
                    ':previous' => (string) $row['payment_status'],
                    ':new' => 'definitive_failure; attempt=' . $row['attempt'] . '; ' . $reason,
                ]);
                if ($ownsTransaction) $this->pdo->commit();
                return true;
            }
            if ($status !== 'succeeded') {
                $saveAttempt = $this->pdo->prepare(
                    "UPDATE payment_refund_attempts SET stripe_refund_id = :refundId, status = 'unknown',
                            provider_error_code = 'unrecognized_status', provider_error_message = 'Stripe returned an unrecognized refund status.'
                     WHERE order_id = :orderId AND attempt = :attempt AND status <> 'succeeded'"
                );
                $saveAttempt->execute([':refundId' => $refundId, ':orderId' => $row['order_id'], ':attempt' => $row['attempt']]);
                if ($currentAttempt && $row['payment_status'] === 'refund_pending') {
                    $unknown = $this->pdo->prepare(
                        "UPDATE orders SET payment_refund_id = :refundId, payment_refund_outcome = 'unknown',
                                payment_refund_error_code = 'unrecognized_status',
                                payment_refund_error_message = 'Stripe returned an unrecognized refund status.'
                         WHERE id = :id AND payment_status = 'refund_pending' AND payment_refund_attempt = :attempt"
                    );
                    $unknown->execute([':refundId' => $refundId, ':id' => $row['order_id'], ':attempt' => $row['attempt']]);
                }
                $audit = $this->pdo->prepare(
                    "INSERT INTO admin_audit_log (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                     VALUES (:adminId, 'order.full_refund_unknown', 'order', :orderId, :previous, :new)"
                );
                $audit->execute([
                    ':adminId' => $adminId, ':orderId' => $row['order_id'],
                    ':previous' => (string) $row['payment_status'], ':new' => 'unknown; attempt=' . (int) $row['attempt'],
                ]);
                if ($ownsTransaction) $this->pdo->commit();
                return true;
            }

            $totalCents = $this->cents((string) $row['total']);
            $alreadyCounted = $row['status'] === 'manual' && $row['provider_error_code'] === 'manual_refunded';
            $refundedCents = (int) $row['payment_refunded_cents'] + ($alreadyCounted ? 0 : (int) $row['amount_cents']);
            if ($refundedCents !== $totalCents) {
                throw new HttpException('O valor confirmado na Stripe não fecha exatamente o total reembolsável do pedido.', 409, [], 'stripe_refund_amount_mismatch');
            }

            $withdrawalRequestId = $row['withdrawal_request_id'] === null ? null : (int) $row['withdrawal_request_id'];
            if ($withdrawalRequestId === null && !in_array($row['current_order_status'], ['confirmed', 'preparing', 'cancelled'], true)) {
                throw new HttpException('O pedido mudou para um estado que exige reconciliação operacional antes do reembolso.', 409);
            }
            if ($withdrawalRequestId === null && $row['current_order_status'] !== 'cancelled') {
                $this->restoreStock((int) $row['order_id']);
            }

            $saveAttempt = $this->pdo->prepare(
                "UPDATE payment_refund_attempts SET stripe_refund_id = :refundId, status = 'succeeded',
                        provider_error_code = NULL, provider_error_message = NULL
                 WHERE order_id = :orderId AND attempt = :attempt AND status <> 'succeeded'"
            );
            $saveAttempt->execute([':refundId' => $refundId, ':orderId' => $row['order_id'], ':attempt' => $row['attempt']]);
            if ($saveAttempt->rowCount() !== 1) {
                if ($ownsTransaction) $this->pdo->commit();
                return true;
            }
            $saveOrder = $this->pdo->prepare(
                "UPDATE orders SET status = IF(:cancelOrder = 1, 'cancelled', status),
                        payment_status = 'refunded', payment_refunded_cents = :refundedCents,
                        payment_refund_id = :refundId, payment_refund_outcome = 'succeeded',
                        payment_refund_error_code = NULL, payment_refund_error_message = NULL,
                        payment_refund_error_http_status = NULL, reservation_expires_at = NULL
                 WHERE id = :id AND payment_intent_id = :paymentIntent"
            );
            $saveOrder->execute([
                ':cancelOrder' => $withdrawalRequestId === null ? 1 : 0,
                ':refundedCents' => $refundedCents, ':refundId' => $refundId,
                ':id' => $row['order_id'], ':paymentIntent' => $paymentIntentId,
            ]);
            if ($saveOrder->rowCount() !== 1) throw new HttpException('O pedido mudou durante a confirmação do reembolso.', 409);

            if (!$alreadyCounted) {
                $this->queueFullRefundConfirmation((int) $row['order_id'], $refundId);
            }
            if ($withdrawalRequestId !== null) {
                $returnLock = $this->pdo->prepare('SELECT status FROM withdrawal_requests WHERE id = :id LIMIT 1 FOR UPDATE');
                $returnLock->execute([':id' => $withdrawalRequestId]);
                $previousReturnStatus = $returnLock->fetchColumn();
                if (in_array($previousReturnStatus, ['refund_pending', 'refund_failed'], true)) {
                    $updateReturn = $this->pdo->prepare("UPDATE withdrawal_requests SET status = 'refunded' WHERE id = :id AND status = :previousStatus");
                    $updateReturn->execute([':id' => $withdrawalRequestId, ':previousStatus' => $previousReturnStatus]);
                    if ($updateReturn->rowCount() === 1) {
                        $event = $this->pdo->prepare(
                            'INSERT INTO withdrawal_request_events (request_id, actor_role, event_name, previous_status, new_status, note)
                             VALUES (:id, \'system\', :eventName, :previous, \'refunded\', :note)'
                        );
                        $event->execute([
                            ':id' => $withdrawalRequestId,
                            ':eventName' => $previousReturnStatus === 'refund_failed' ? 'refund.confirmed_after_manual_resolution' : 'refund.confirmed',
                            ':previous' => $previousReturnStatus, ':note' => 'Full refund attempt #' . $row['attempt'] . ' confirmed by Stripe.',
                        ]);
                    }
                }
            }
            $audit = $this->pdo->prepare(
                "INSERT INTO admin_audit_log (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, 'order.full_refund_completed', 'order', :orderId, :previous, :new)"
            );
            $audit->execute([
                ':adminId' => $adminId, ':orderId' => $row['order_id'],
                ':previous' => (string) $row['payment_status'],
                ':new' => 'refunded:' . $refundedCents . 'cents; attempt=' . $row['attempt'] . '; refund=' . $refundId,
            ]);
            if ($ownsTransaction) $this->pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }
}

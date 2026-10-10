<?php

declare(strict_types=1);

/** Full-refund ledger transitions, independent of webhook delivery and routing. */
trait FullRefundEventActions
{
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
                 ) ORDER BY (r.stripe_refund_id = :refundPriority) DESC LIMIT 1 FOR UPDATE"
            );
            $find->execute([
                ':paymentIntent' => $paymentIntentId, ':attemptFilter' => $attempt,
                ':attemptMatch' => $attempt, ':refundId' => $refundId,
                ':refundPriority' => $refundId,
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
            PaymentPolicy::assertStripeRefundMatchesAttempt($refund, $order, (int) $row['attempt'], (int) $row['amount_cents'], $row['stripe_refund_id']);
            if ($row['stripe_refund_id'] !== null && !hash_equals((string) $row['stripe_refund_id'], $refundId)) {
                throw new HttpException('A Stripe vinculou mais de um reembolso à mesma tentativa integral.', 409, [], 'stripe_refund_attempt_mismatch');
            }

            $status = (string) ($refund['status'] ?? 'unknown');
            if (ExternalRefundService::shouldDeferReviewedSuccess($this->pdo, $refund)) {
                if ($ownsTransaction) $this->pdo->commit();
                return true;
            }
            if ($row['status'] === 'succeeded') {
                if (in_array($status, ['failed', 'canceled', 'requires_action'], true)) $this->captureUnmatchedRefund($refund);
                if ($ownsTransaction) $this->pdo->commit();
                return true;
            }
            if ($row['status'] === 'manual' && $status !== 'succeeded') {
                if ($row['provider_error_code'] === 'manual_refunded' && in_array($status, ['failed', 'canceled', 'requires_action'], true)) $this->captureUnmatchedRefund($refund);
                $saveId = $this->pdo->prepare(
                    'UPDATE payment_refund_attempts SET stripe_refund_id = COALESCE(stripe_refund_id, :refundId)
                     WHERE order_id = :orderId AND attempt = :attempt AND status = \'manual\''
                );
                $saveId->execute([':refundId' => $refundId, ':orderId' => $row['order_id'], ':attempt' => $row['attempt']]);
                if ($ownsTransaction) $this->pdo->commit();
                return true;
            }

            $currentAttempt = (int) $row['payment_refund_attempt'] === (int) $row['attempt'];
            if (RefundStatePolicy::isRepeatedFailure($row, $refund, $status === 'canceled' ? 'refund_canceled' : 'refund_failed')) {
                if ($ownsTransaction) $this->pdo->commit();
                return true;
            }
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
                if ($currentAttempt && ($row['payment_status'] === 'refund_pending' || $row['payment_refund_outcome'] === 'definitive_failure')) {
                    $saveOrder = $this->pdo->prepare(
                        "UPDATE orders SET payment_status = IF(payment_refunded_cents >= ROUND(total * 100), 'refunded',
                                IF(payment_refunded_cents > 0, 'partially_refunded', 'paid')), payment_refund_id = :refundId,
                                payment_refund_outcome = 'definitive_failure', payment_refund_error_code = :errorCode,
                                payment_refund_error_message = :message, payment_refund_error_http_status = NULL
                         WHERE id = :id AND (payment_status = 'refund_pending' OR payment_refund_outcome = 'definitive_failure')
                            AND payment_refund_attempt = :attempt"
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
            if ($refundedCents < 0 || $refundedCents > $totalCents) {
                throw new HttpException('O valor confirmado na Stripe excede o saldo contabilizável deste pedido.', 409, [], 'stripe_refund_amount_mismatch');
            }
            $fullyRefunded = $refundedCents === $totalCents;
            $preservePending = !$currentAttempt && $row['payment_status'] === 'refund_pending';
            $paymentStatus = $preservePending ? 'refund_pending' : RefundStatePolicy::settledPaymentStatus($refundedCents, $totalCents);
            $withdrawalRequestId = $row['withdrawal_request_id'] === null ? null : (int) $row['withdrawal_request_id'];
            $fulfillmentReview = $withdrawalRequestId === null && !in_array($row['current_order_status'], ['confirmed', 'preparing', 'cancelled'], true);
            $cancelOrder = $fullyRefunded && $withdrawalRequestId === null && !$fulfillmentReview;
            if ($cancelOrder && $row['current_order_status'] !== 'cancelled') {
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
            // An older success contributes money but cannot replace the identity or
            // outcome of a newer in-flight attempt. Financial and fulfillment states
            // are separate: an authorized refund can succeed after either changes.
            $currentFields = $currentAttempt ? ', payment_refund_id = :refundId, payment_refund_outcome = \'succeeded\',
                payment_refund_error_code = :errorCode, payment_refund_error_message = :errorMessage,
                payment_refund_error_http_status = NULL' : '';
            $saveOrder = $this->pdo->prepare(
                "UPDATE orders SET status = IF(:cancelOrder = 1, 'cancelled', status),
                        payment_status = :paymentStatus, payment_refunded_cents = :refundedCents,
                        reservation_expires_at = NULL {$currentFields}
                 WHERE id = :id AND payment_intent_id = :paymentIntent"
            );
            $parameters = [
                ':cancelOrder' => (int) $cancelOrder, ':paymentStatus' => $paymentStatus,
                ':refundedCents' => $refundedCents,
                ':id' => $row['order_id'], ':paymentIntent' => $paymentIntentId,
            ];
            if ($currentAttempt) {
                $parameters += [':refundId' => $refundId,
                    ':errorCode' => $fulfillmentReview ? 'refund_fulfillment_review_required' : ($fullyRefunded ? null : 'refund_balance_remaining'),
                    ':errorMessage' => $fulfillmentReview ? 'The authorized refund succeeded after fulfillment changed; review shipment or return handling. Stock was not restored.'
                        : ($fullyRefunded ? null : 'The authorized amount was refunded, but an earlier credit changed. Review the remaining balance before authorizing another refund.')];
            }
            $saveOrder->execute($parameters);
            // The row is already locked and its payment identity was checked above.
            // A manually counted older success can legitimately leave it unchanged;
            // MySQL reports zero changed rows in that case, not a lost order.

            if (!$alreadyCounted || !$fullyRefunded) {
                if ($fullyRefunded && !$preservePending) {
                    $this->queueFullRefundConfirmation((int) $row['order_id'], $refundId);
                } else {
                    $this->pdo->prepare("INSERT IGNORE INTO payment_notification_outbox (order_id, notification_type, reference_id)
                        VALUES (:id, 'refund_confirmation', :attempt)")
                        ->execute([':id' => $row['order_id'], ':attempt' => (string) $row['attempt']]);
                }
            }
            if ($withdrawalRequestId !== null) {
                $returnLock = $this->pdo->prepare('SELECT status FROM withdrawal_requests WHERE id = :id LIMIT 1 FOR UPDATE');
                $returnLock->execute([':id' => $withdrawalRequestId]);
                $previousReturnStatus = $returnLock->fetchColumn();
                if (in_array($previousReturnStatus, ['refund_pending', 'refund_failed'], true)) {
                    $returnStatus = $fullyRefunded ? 'refunded' : 'return_received';
                    $updateReturn = $this->pdo->prepare('UPDATE withdrawal_requests SET status = :status WHERE id = :id AND status = :previousStatus');
                    $updateReturn->execute([':status' => $returnStatus, ':id' => $withdrawalRequestId, ':previousStatus' => $previousReturnStatus]);
                    if ($updateReturn->rowCount() === 1) {
                        $event = $this->pdo->prepare(
                            'INSERT INTO withdrawal_request_events (request_id, actor_role, event_name, previous_status, new_status, note)
                             VALUES (:id, \'system\', :eventName, :previous, :status, :note)'
                        );
                        $event->execute([
                            ':id' => $withdrawalRequestId,
                            ':eventName' => !$fullyRefunded ? 'refund.balance_remaining' : ($previousReturnStatus === 'refund_failed' ? 'refund.confirmed_after_manual_resolution' : 'refund.confirmed'),
                            ':previous' => $previousReturnStatus, ':status' => $returnStatus,
                            ':note' => 'Authorized refund attempt #' . $row['attempt'] . ' confirmed; remaining=' . ($totalCents - $refundedCents) . ' cents.',
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
                ':new' => $paymentStatus . ':' . $refundedCents . 'cents; attempt=' . $row['attempt'] . '; refund=' . $refundId
                    . '; remaining=' . ($totalCents - $refundedCents) . '; fulfillment_review=' . (int) $fulfillmentReview,
            ]);
            if ($ownsTransaction) $this->pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }
}

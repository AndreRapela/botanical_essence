<?php

declare(strict_types=1);

/** Apply provider refund results and finish linked customer-return workflows. */
trait RefundAdminResultActions
{
    private function applyAdminRefundResult(array $order, array $refund, int $adminId, ?int $withdrawalRequestId = null): array
    {
        if ($this->applyFullRefundAttemptEvent($refund, $adminId)) {
            return match ((string) ($refund['status'] ?? '')) {
                'succeeded' => ['message' => 'A Stripe confirmou o reembolso integral; pedido, estoque e notificações foram conciliados.'],
                'pending', 'requires_action' => ['message' => 'A Stripe aceitou o reembolso integral, que permanece em processamento até confirmação final.'],
                'failed', 'canceled' => ['message' => 'A Stripe confirmou que esta tentativa integral não foi reembolsada; o pedido permanece pago.'],
                default => ['message' => 'O estado do reembolso integral foi registrado para reconciliação.'],
            };
        }
        $expectedRefundCents = $this->cents((string) $order['total']) - (int) ($order['payment_refunded_cents'] ?? 0);
        PaymentPolicy::assertStripeRefundMatchesAttempt(
            $refund,
            $order,
            (int) $order['payment_refund_attempt'],
            $expectedRefundCents
        );
        $refundId = (string) ($refund['id'] ?? '');
        $status = (string) ($refund['status'] ?? '');
        $paymentIntent = $refund['payment_intent'] ?? null;
        $paymentIntentId = is_array($paymentIntent) ? (string) ($paymentIntent['id'] ?? '') : (string) $paymentIntent;

        if (in_array($status, ['succeeded', 'pending', 'requires_action'], true)) {
            $current = $this->orderForAdmin((string) $order['order_number']);
            if (!$current) {
                throw new HttpException('Pedido não encontrado durante a reconciliação do reembolso.', 404);
            }
            if ($current['payment_status'] === 'refunded') {
                return ['message' => 'O pedido já está registrado como reembolsado; nenhuma nova alteração foi feita.'];
            }
            if ($current['payment_status'] === 'paid') {
                $promote = $this->pdo->prepare(
                    "UPDATE orders SET payment_status = 'refund_pending', payment_refund_outcome = 'pending',
                            payment_refund_error_code = NULL, payment_refund_error_message = NULL,
                            payment_refund_error_http_status = NULL
                     WHERE id = :id AND payment_provider = 'stripe' AND payment_status = 'paid'
                           AND payment_intent_id = :paymentIntent AND payment_refund_attempt = :attempt
                           AND status IN ('confirmed','preparing','cancelled')"
                );
                $promote->execute([
                    ':id' => $current['id'],
                    ':paymentIntent' => $paymentIntentId,
                    ':attempt' => $order['payment_refund_attempt'],
                ]);
                $current = $this->orderForAdmin((string) $order['order_number']) ?? $current;
            }
            if ($current['payment_status'] !== 'refund_pending') {
                throw new HttpException('O estado do pagamento mudou durante a reconciliação. Atualize o pedido antes de continuar.', 409);
            }
            $order = $current;
        }

        if ($status === 'succeeded') {
            if ($withdrawalRequestId !== null) {
                $this->finishWithdrawalRefund($order, $refundId, $adminId, $withdrawalRequestId);
                return ['message' => 'A Stripe confirmou o reembolso da devolução; o caso foi encerrado e o estoque não foi reintegrado automaticamente.'];
            }
            if ($order['status'] === 'cancelled') {
                $this->latePaymentRecovery->markRefunded(
                    (int) $order['id'],
                    (int) $order['payment_refund_attempt'],
                    $paymentIntentId,
                    $refundId,
                    $adminId
                );
            } else {
                try {
                    $this->finishCancellation(
                        (int) $order['id'], $adminId, 'paid', 'refunded', $refundId,
                        'refund_pending', $order['payment_session_id'] ?? null
                    );
                } catch (HttpException $exception) {
                    $latest = $this->orderForAdmin((string) $order['order_number']);
                    if (!$latest || $latest['payment_status'] !== 'refunded' || $latest['status'] !== 'cancelled') {
                        throw $exception;
                    }
                }
            }
            return ['message' => 'A Stripe confirmou o reembolso; o pedido está cancelado e o estoque foi liberado uma única vez.'];
        }

        if (in_array($status, ['failed', 'canceled'], true)) {
            $reason = (string) ($refund['failure_reason'] ?? ($status === 'canceled' ? 'refund_canceled' : 'refund_failed'));
            if ($withdrawalRequestId !== null) {
                $this->withdrawals->recordProviderRefundFailure(
                    $withdrawalRequestId,
                    (int) $order['id'],
                    $adminId,
                    $paymentIntentId,
                    (int) $order['payment_refund_attempt'],
                    $refundId !== '' ? $refundId : null,
                    $reason,
                    'Stripe refund status: ' . $status . ($reason !== '' ? ' (' . $reason . ')' : ''),
                    null
                );
            } else {
                $this->recordDefinitiveRefundFailure(
                    (int) $order['id'],
                    $paymentIntentId,
                    (int) $order['payment_refund_attempt'],
                    $refundId !== '' ? $refundId : null,
                    $reason,
                    'Stripe refund status: ' . $status . ($reason !== '' ? ' (' . $reason . ')' : ''),
                    null,
                    $adminId
                );
            }
            return ['message' => 'A Stripe confirmou que esta tentativa não foi reembolsada. O pagamento permanece registrado como pago e uma nova tentativa segura está disponível.'];
        }

        if (in_array($status, ['pending', 'requires_action'], true)) {
            $this->recordRefundProcessing($order, $refundId, $adminId);
            return ['message' => 'A Stripe confirma que o reembolso continua em processamento. O pedido permanece protegido até uma confirmação final.'];
        }

        $this->recordUnknownRefundOutcome(
            (int) $order['id'],
            (int) $order['payment_refund_attempt'],
            $refundId !== '' ? $refundId : null,
            'unrecognized_refund_status',
            'Stripe returned a refund status that needs manual review.',
            null,
            $adminId
        );
        return ['message' => 'A Stripe retornou um estado não reconhecido para o reembolso. O pedido continua protegido e precisa de nova consulta ou resolução manual auditada.'];
    }

    private function finishWithdrawalRefund(array $order, string $refundId, int $adminId, int $withdrawalRequestId): void
    {
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT o.status, o.payment_status, o.payment_refund_attempt, r.status AS requestStatus, r.order_id
                 FROM orders o INNER JOIN withdrawal_requests r ON r.order_id = o.id
                 WHERE o.id = :orderId AND r.id = :requestId LIMIT 1 FOR UPDATE'
            );
            $select->execute([':orderId' => $order['id'], ':requestId' => $withdrawalRequestId]);
            $current = $select->fetch();
            if (!$current || (int) $current['order_id'] !== (int) $order['id']) {
                throw new HttpException('Pedido ou solicitação de retirada não encontrados na confirmação do reembolso.', 404);
            }
            if ($current['payment_status'] === 'refunded' && $current['requestStatus'] === 'refunded') {
                $this->pdo->commit();
                return;
            }
            if ($current['payment_status'] !== 'refund_pending'
                || (int) $current['payment_refund_attempt'] !== (int) $order['payment_refund_attempt']
                || $current['requestStatus'] !== 'refund_pending') {
                throw new HttpException('O estado do pedido mudou durante a confirmação do reembolso.', 409);
            }
            $updateOrder = $this->pdo->prepare(
                "UPDATE orders SET payment_status = 'refunded', payment_refunded_cents = ROUND(total * 100), payment_refund_id = :refundId,
                        payment_refund_outcome = 'succeeded', payment_refund_error_code = NULL,
                        payment_refund_error_message = NULL, payment_refund_error_http_status = NULL,
                        reservation_expires_at = NULL
                 WHERE id = :id AND payment_status = 'refund_pending' AND payment_refund_attempt = :attempt"
            );
            $updateOrder->execute([':refundId' => $refundId !== '' ? $refundId : null, ':id' => $order['id'], ':attempt' => $order['payment_refund_attempt']]);
            if ($updateOrder->rowCount() !== 1) {
                throw new HttpException('O estado do pagamento mudou ao concluir o reembolso.', 409);
            }
            $this->queueFullRefundConfirmation((int) $order['id'], $refundId !== '' ? $refundId : ('refund-' . $order['payment_refund_attempt']));
            $updateRequest = $this->pdo->prepare("UPDATE withdrawal_requests SET status = 'refunded', handled_by_admin_id = :adminId WHERE id = :id AND status = 'refund_pending'");
            $updateRequest->execute([':adminId' => $adminId, ':id' => $withdrawalRequestId]);
            if ($updateRequest->rowCount() !== 1) {
                throw new HttpException('O estado da solicitação mudou ao concluir o reembolso.', 409);
            }
            $event = $this->pdo->prepare(
                "INSERT INTO withdrawal_request_events
                    (request_id, actor_user_id, actor_role, event_name, previous_status, new_status)
                 VALUES (:requestId, :adminId, 'admin', 'refund.confirmed', 'refund_pending', 'refunded')"
            );
            $event->execute([':requestId' => $withdrawalRequestId, ':adminId' => $adminId]);
            $audit = $this->pdo->prepare(
                "INSERT INTO admin_audit_log
                    (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, 'order.withdrawal_refund_completed', 'order', :orderId, :previous, :new)"
            );
            $audit->execute([
                ':adminId' => $adminId,
                ':orderId' => $order['id'],
                ':previous' => $current['status'] . ':refund_pending',
                ':new' => $current['status'] . ':refunded',
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}

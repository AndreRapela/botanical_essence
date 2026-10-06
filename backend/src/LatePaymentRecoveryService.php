<?php

declare(strict_types=1);

/** Recovers and refunds Stripe payments that arrive after an order was cancelled. */
final class LatePaymentRecoveryService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly StripeGateway $stripe
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

    public function refundCancelledOrderPayment(array $order, array $session, string $sessionId): void
    {
        $paymentIntent = $session['payment_intent'] ?? null;
        $paymentIntentId = is_array($paymentIntent) ? (string) ($paymentIntent['id'] ?? '') : (string) $paymentIntent;
        if ($paymentIntentId === '') {
            throw new HttpException('A Stripe confirmou um pedido cancelado sem referência de reembolso. A reconciliação foi interrompida.', 409, [], 'late_payment_missing_intent');
        }

        $claim = $this->pdo->prepare(
            "UPDATE orders SET payment_status = 'refund_pending', payment_intent_id = :paymentIntent,
                    payment_refund_attempt = payment_refund_attempt + 1, payment_refund_id = NULL,
                    payment_refund_outcome = 'pending', payment_refund_error_code = NULL,
                    payment_refund_error_message = NULL, payment_refund_error_http_status = NULL,
                    reservation_expires_at = NULL
             WHERE id = :id AND status = 'cancelled' AND payment_session_id = :sessionId
                   AND payment_status IN ('pending','failed','paid')"
        );
        $claim->execute([
            ':paymentIntent' => $paymentIntentId,
            ':id' => $order['id'],
            ':sessionId' => $sessionId,
        ]);

        $lookup = $this->pdo->prepare(
            'SELECT order_number, payment_status, payment_intent_id, payment_refund_attempt
             FROM orders WHERE id = :id'
        );
        $lookup->execute([':id' => $order['id']]);
        $current = $lookup->fetch();
        if (!$current || $current['payment_status'] !== 'refund_pending'
            || !hash_equals((string) $current['payment_intent_id'], $paymentIntentId)) {
            return;
        }

        $attempt = max(1, (int) $current['payment_refund_attempt']);
        try {
            $refund = $this->stripe->refundPaymentIntent($paymentIntentId, (string) $current['order_number'], $attempt);
        } catch (StripeApiException $exception) {
            if ($exception->definitive) {
                $this->markRefundFailed(
                    (int) $order['id'], $attempt, $paymentIntentId, '', null,
                    $exception->providerCode ?? ('stripe_http_' . ($exception->httpStatus ?? 'error')),
                    $exception->providerMessage,
                    $exception->httpStatus
                );
            } else {
                $this->markRefundOutcomeUnknown(
                    (int) $order['id'], $attempt, null,
                    $exception->providerCode ?? 'stripe_outcome_unknown',
                    $exception->providerMessage,
                    $exception->httpStatus
                );
            }
            error_log('Late payment refund outcome for order ' . $current['order_number'] . ': ' . $exception->getMessage());
            return;
        } catch (Throwable $exception) {
            $this->markRefundOutcomeUnknown(
                (int) $order['id'], $attempt, null,
                'transport_or_response_error',
                'No definitive response was received from the payment provider.',
                null
            );
            error_log('Late payment refund is pending for order ' . $current['order_number'] . ': ' . $exception->getMessage());
            return;
        }

        $refundId = (string) ($refund['id'] ?? '');
        try {
            PaymentPolicy::assertStripeRefundMatchesAttempt(
                $refund,
                [
                    ...$order,
                    'payment_provider' => 'stripe',
                    'payment_intent_id' => $paymentIntentId,
                    'order_number' => (string) $current['order_number'],
                ],
                $attempt,
                $this->cents((string) $order['total']) - (int) ($order['payment_refunded_cents'] ?? 0)
            );
        } catch (Throwable $exception) {
            $this->markRefundOutcomeUnknown(
                (int) $order['id'], $attempt, null,
                'stripe_refund_response_mismatch',
                'The Stripe refund response did not match the canceled order and authorized amount.',
                null
            );
            error_log('Stripe returned a mismatched late-payment refund for order ' . $current['order_number'] . ': ' . $exception->getMessage());
            return;
        }
        if (($refund['status'] ?? '') === 'succeeded') {
            $this->markRefunded((int) $order['id'], $attempt, $paymentIntentId, $refundId, null);
        } elseif (in_array(($refund['status'] ?? ''), ['failed', 'canceled'], true)) {
            $reason = (string) ($refund['failure_reason'] ?? (($refund['status'] ?? '') === 'canceled' ? 'refund_canceled' : 'refund_failed'));
            $this->markRefundFailed(
                (int) $order['id'], $attempt, $paymentIntentId, $refundId, null,
                $reason, 'Stripe refund failed: ' . $reason, null
            );
        } elseif ($refundId !== '') {
            $save = $this->pdo->prepare(
                "UPDATE orders SET payment_refund_id = :refundId, payment_refund_outcome = 'pending'
                 WHERE id = :id AND status = 'cancelled' AND payment_status = 'refund_pending'
                       AND payment_refund_attempt = :attempt"
            );
            $save->execute([':refundId' => $refundId, ':id' => $order['id'], ':attempt' => $attempt]);
        }
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

    public function markRefundFailed(
        int $orderId,
        int $attempt,
        string $paymentIntentId,
        string $refundId,
        ?int $adminId,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?int $httpStatus = null
    ): void
    {
        $update = $this->pdo->prepare(
            "UPDATE orders SET payment_status = 'paid', payment_refund_outcome = 'definitive_failure',
                    payment_refund_id = :refundId, payment_refund_error_code = :errorCode,
                    payment_refund_error_message = :errorMessage, payment_refund_error_http_status = :httpStatus
             WHERE id = :id AND status = 'cancelled' AND payment_status = 'refund_pending'
                   AND payment_intent_id = :paymentIntent AND payment_refund_attempt = :attempt"
        );
        $update->execute([
            ':refundId' => $refundId !== '' ? $refundId : null,
            ':id' => $orderId,
            ':paymentIntent' => $paymentIntentId,
            ':attempt' => $attempt,
            ':errorCode' => $this->boundedProviderCode($errorCode ?? 'refund_failed'),
            ':errorMessage' => $this->boundedDiagnostic($errorMessage ?? 'Stripe confirmed this refund attempt failed.'),
            ':httpStatus' => $httpStatus,
        ]);
        if ($update->rowCount() === 1) {
            $audit = $this->pdo->prepare(
                "INSERT INTO admin_audit_log
                    (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, 'order.late_payment_refund_failed', 'order', :orderId,
                         'cancelled:refund_pending', :newValue)"
            );
            $audit->execute([
                ':adminId' => $adminId,
                ':orderId' => $orderId,
                ':newValue' => 'cancelled:paid:definitive_failure; ' . $this->boundedProviderCode($errorCode ?? 'refund_failed') . ': ' . $this->boundedDiagnostic($errorMessage ?? ''),
            ]);
        }
    }

    private function markRefundOutcomeUnknown(int $orderId, int $attempt, ?string $refundId, string $code, string $message, ?int $httpStatus): void
    {
        $update = $this->pdo->prepare(
            "UPDATE orders SET payment_refund_outcome = 'unknown',
                    payment_refund_id = COALESCE(:refundId, payment_refund_id),
                    payment_refund_error_code = :errorCode, payment_refund_error_message = :errorMessage,
                    payment_refund_error_http_status = :httpStatus
             WHERE id = :id AND status = 'cancelled' AND payment_status = 'refund_pending'
                   AND payment_refund_attempt = :attempt"
        );
        $update->execute([
            ':refundId' => $refundId,
            ':errorCode' => $this->boundedProviderCode($code),
            ':errorMessage' => $this->boundedDiagnostic($message),
            ':httpStatus' => $httpStatus,
            ':id' => $orderId,
            ':attempt' => $attempt,
        ]);
    }

    private function boundedDiagnostic(string $message): string
    {
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', trim($message)) ?? '';
        return function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);
    }

    private function boundedProviderCode(string $code): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '', $code) ?? '';
        return substr($safe !== '' ? $safe : 'provider_error', 0, 100);
    }

    private function cents(string $amount): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new RuntimeException('The stored order total has an invalid format.');
        }
        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}

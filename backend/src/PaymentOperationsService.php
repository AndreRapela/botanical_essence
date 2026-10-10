<?php

declare(strict_types=1);

/** Durable after-payment work: webhook recovery, dispute sync and confirmation mail outbox. */
final class PaymentOperationsService
{
    private readonly CheckoutReconciliationService $checkoutReconciliation;

    public function __construct(
        private readonly PDO $pdo,
        private readonly StripeGateway $stripe,
        private readonly PaymentOrderLock $orderLock,
        private readonly MailTransport $mailer,
        private readonly Closure $applyCheckoutEvent,
        private readonly Closure $cancelUnpaidOrder,
        private readonly Closure $applyRefundEvent,
        private readonly ?Closure $listRefundsForPaymentIntent = null,
        private readonly ?Closure $retrieveCheckoutSession = null,
        private readonly ?Closure $retrievePaymentIntent = null,
        private readonly ?Closure $recoverExternalRefunds = null,
        private readonly ?Closure $dispatchLateRefunds = null
    ) {
        $this->checkoutReconciliation = new CheckoutReconciliationService(
            $this->pdo,
            $this->stripe,
            $this->orderLock,
            $this->applyCheckoutEvent,
            $this->cancelUnpaidOrder,
            $this->retrieveCheckoutSession,
            $this->retrievePaymentIntent
        );
    }

    public function runBatch(int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));
        $reconciled = $this->checkoutReconciliation->reconcilePendingSessions($limit);
        $confirmations = $this->recoverMissingPaymentConfirmations($limit);
        $lateRefunds = $this->dispatchLateRefunds === null ? 0 : ($this->dispatchLateRefunds)($limit);
        $refunds = $this->reconcilePendingRefunds($limit);
        $externalRefunds = $this->recoverExternalRefunds === null ? 0 : ($this->recoverExternalRefunds)($limit);
        $mail = $this->deliverConfirmationEmails($limit);
        $disputes = $this->syncDisputes($limit);
        return ['sessions' => $reconciled, 'confirmations' => $confirmations, 'lateRefunds' => $lateRefunds, 'refunds' => $refunds, 'externalRefunds' => $externalRefunds, 'emails' => $mail, 'disputes' => $disputes];
    }

    public function dispatchOutbox(int $limit = 20): int
    {
        return $this->deliverConfirmationEmails(max(1, min($limit, 100)));
    }

    public function recordDispute(array $dispute): void
    {
        if (!PaymentPolicy::stripeObjectMatchesEnvironment($dispute)) {
            throw new HttpException('A disputa Stripe pertence a um modo incompatível com este ambiente.', 409, [], 'stripe_dispute_mode_mismatch');
        }

        $disputeId = (string) ($dispute['id'] ?? '');
        if ($disputeId === '') {
            return;
        }
        $paymentIntent = $dispute['payment_intent'] ?? null;
        $paymentIntentId = is_array($paymentIntent) ? (string) ($paymentIntent['id'] ?? '') : (string) $paymentIntent;
        $charge = $dispute['charge'] ?? null;
        $chargeId = is_array($charge) ? (string) ($charge['id'] ?? '') : (string) $charge;
        $orderId = null;
        if ($paymentIntentId !== '') {
            $findOrder = $this->pdo->prepare('SELECT id, payment_provider, test_payment FROM orders WHERE payment_intent_id = :paymentIntent LIMIT 1');
            $findOrder->execute([':paymentIntent' => $paymentIntentId]);
            $order = $findOrder->fetch();
            if ($order) {
                if (!PaymentPolicy::stripeObjectMatchesOrder($dispute, $order)) {
                    throw new HttpException('A disputa Stripe não corresponde ao modo da cobrança local.', 409, [], 'stripe_dispute_order_mode_mismatch');
                }
                $orderId = (int) $order['id'];
            }
        }
        $created = isset($dispute['created']) && is_numeric($dispute['created'])
            ? gmdate('Y-m-d H:i:s', (int) $dispute['created'])
            : null;
        $dueBy = isset($dispute['evidence_details']['due_by']) && is_numeric($dispute['evidence_details']['due_by'])
            ? gmdate('Y-m-d H:i:s', (int) $dispute['evidence_details']['due_by'])
            : null;
        $statement = $this->pdo->prepare(
            "INSERT INTO payment_disputes
                (stripe_dispute_id, order_id, payment_intent_id, charge_id, status, reason,
                 amount_cents, currency, evidence_due_at, livemode, provider_created_at, last_synced_at, last_sync_error)
             VALUES (:id, :orderId, :paymentIntent, :chargeId, :status, :reason,
                     :amount, :currency, :dueAt, :livemode, :createdAt, CURRENT_TIMESTAMP, NULL)
             ON DUPLICATE KEY UPDATE
                order_id = COALESCE(VALUES(order_id), order_id),
                payment_intent_id = COALESCE(VALUES(payment_intent_id), payment_intent_id),
                charge_id = COALESCE(VALUES(charge_id), charge_id),
                status = VALUES(status), reason = VALUES(reason), amount_cents = VALUES(amount_cents),
                currency = VALUES(currency), evidence_due_at = VALUES(evidence_due_at),
                livemode = VALUES(livemode), provider_created_at = COALESCE(VALUES(provider_created_at), provider_created_at),
                last_synced_at = CURRENT_TIMESTAMP, last_sync_error = NULL"
        );
        $statement->execute([
            ':id' => $disputeId,
            ':orderId' => $orderId,
            ':paymentIntent' => $paymentIntentId !== '' ? $paymentIntentId : null,
            ':chargeId' => $chargeId !== '' ? $chargeId : null,
            ':status' => substr((string) ($dispute['status'] ?? 'unknown'), 0, 60),
            ':reason' => isset($dispute['reason']) ? substr((string) $dispute['reason'], 0, 100) : null,
            ':amount' => max(0, (int) ($dispute['amount'] ?? 0)),
            ':currency' => strtoupper(substr((string) ($dispute['currency'] ?? 'EUR'), 0, 3)),
            ':dueAt' => $dueBy,
            ':livemode' => (bool) ($dispute['livemode'] ?? false),
            ':createdAt' => $created,
        ]);
    }

    public function adminDisputes(array $query = []): array
    {
        Validation::allowedFields($query, ['page', 'limit']);
        $page = Validation::queryInteger($query, 'page', 1, 1, 1_000_000);
        $limit = Validation::queryInteger($query, 'limit', 50, 1, 100);
        $offset = ($page - 1) * $limit;
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM payment_disputes')->fetchColumn();
        $statement = $this->pdo->prepare(
            "SELECT d.stripe_dispute_id AS id, d.order_id AS orderId, o.order_number AS orderNumber,
                    o.customer_name AS customerName, o.email, d.payment_intent_id AS paymentIntentId,
                    d.charge_id AS chargeId, d.status, d.reason, d.amount_cents AS amountCents,
                    d.currency, DATE_FORMAT(d.evidence_due_at, '%Y-%m-%dT%H:%i:%sZ') AS evidenceDueAt,
                    d.livemode, DATE_FORMAT(d.provider_created_at, '%Y-%m-%dT%H:%i:%sZ') AS createdAt,
                    d.last_sync_error AS syncError
             FROM payment_disputes d LEFT JOIN orders o ON o.id = d.order_id
             ORDER BY CASE WHEN d.status IN ('needs_response','warning_needs_response') THEN 0
                           WHEN d.status IN ('under_review','warning_under_review') THEN 1 ELSE 2 END,
                      d.evidence_due_at IS NULL, d.evidence_due_at ASC, d.updated_at DESC, d.stripe_dispute_id ASC
             LIMIT :limit OFFSET :offset"
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $data = $statement->fetchAll();
        foreach ($data as &$dispute) {
            $dispute['orderId'] = $dispute['orderId'] === null ? null : (int) $dispute['orderId'];
            $dispute['amountCents'] = (int) $dispute['amountCents'];
            $dispute['livemode'] = (bool) $dispute['livemode'];
        }
        unset($dispute);
        return [
            'data' => $data,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'hasMore' => $offset + $limit < $total,
        ];
    }

    /** Backfill a missing payment email from committed paid orders, including legacy gaps. */
    private function recoverMissingPaymentConfirmations(int $limit): int
    {
        $insert = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $statement = $this->pdo->prepare(
            "{$insert} INTO payment_notification_outbox (order_id, notification_type)
             SELECT o.id, 'payment_confirmation'
             FROM orders o
             LEFT JOIN payment_notification_outbox n
                    ON n.order_id = o.id AND n.notification_type = 'payment_confirmation'
             WHERE o.payment_provider IN ('stripe','local_test') AND o.payment_status = 'paid'
               AND o.status IN ('confirmed','preparing','shipped','delivered') AND n.id IS NULL
             ORDER BY o.id ASC
             LIMIT :limit"
        );
        $statement->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->rowCount();
    }

    /** Poll unresolved Stripe refund attempts so a lost webhook cannot strand them indefinitely. */
    private function reconcilePendingRefunds(int $limit): int
    {
        if (!$this->stripe->configured()) return 0;

        // Five minutes bounds Stripe API traffic while ensuring missed events are recovered automatically.
        $cutoff = gmdate('Y-m-d H:i:s', time() - 300);
        $query = $this->pdo->prepare(
            "SELECT id FROM orders
             WHERE payment_provider = 'stripe' AND payment_status = 'refund_pending'
               AND payment_refund_outcome IN ('pending','unknown')
               AND payment_intent_id IS NOT NULL AND payment_refund_attempt > 0
               AND (payment_refund_reconciled_at IS NULL OR payment_refund_reconciled_at < :cutoff)
             ORDER BY COALESCE(payment_refund_reconciled_at, '1970-01-01'), created_at
             LIMIT :limit"
        );
        $query->bindValue(':cutoff', $cutoff);
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->execute();
        $ids = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));

        $reconciled = 0;
        foreach ($ids as $orderId) {
            $order = null;
            try {
                $this->orderLock->acquire($orderId);
                try {
                    $claim = $this->pdo->prepare(
                        "UPDATE orders SET payment_refund_reconciled_at = CURRENT_TIMESTAMP
                         WHERE id = :id AND payment_provider = 'stripe' AND payment_status = 'refund_pending'
                           AND payment_refund_outcome IN ('pending','unknown')
                           AND (payment_refund_reconciled_at IS NULL OR payment_refund_reconciled_at < :cutoff)"
                    );
                    $claim->execute([':id' => $orderId, ':cutoff' => $cutoff]);
                    if ($claim->rowCount() !== 1) continue;

                    $find = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
                    $find->execute([':id' => $orderId]);
                    $order = $find->fetch();
                    if (!$order) continue;

                    $refund = $this->findPendingRefund($order);
                    if ($refund === null) continue;

                    $this->pdo->beginTransaction();
                    try {
                        ($this->applyRefundEvent)($refund);
                        $this->pdo->commit();
                        $reconciled++;
                    } catch (Throwable $exception) {
                        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                        throw $exception;
                    }
                } finally {
                    $this->orderLock->release($orderId);
                }
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                if (is_array($order)) {
                    $this->recordRefundReconciliationError($order, $exception);
                }
                error_log('Stripe refund reconciliation failed for order ' . $orderId . ': ' . $exception->getMessage());
            }
        }

        return $reconciled;
    }

    private function findPendingRefund(array $order): ?array
    {
        $paymentIntentId = (string) ($order['payment_intent_id'] ?? '');
        if ($paymentIntentId === '') return null;

        $refundId = (string) ($order['payment_refund_id'] ?? '');
        if ($refundId !== '') {
            try {
                $refund = $this->stripe->retrieveRefund($refundId);
                if ($this->refundMatchesPendingAttempt($refund, $order)) return $refund;
                throw new RuntimeException('The stored Stripe refund does not match the current order attempt.');
            } catch (StripeApiException $exception) {
                if ($exception->httpStatus !== 404) throw $exception;
            }
        }

        $refunds = $this->listRefundsForPaymentIntent !== null
            ? ($this->listRefundsForPaymentIntent)($paymentIntentId)
            : $this->stripe->refundsForPaymentIntent($paymentIntentId);
        if (!is_iterable($refunds)) {
            throw new RuntimeException('Stripe returned an invalid refund list.');
        }
        foreach ($refunds as $refund) {
            if (is_array($refund) && $this->refundMatchesPendingAttempt($refund, $order)) {
                return $refund;
            }
        }
        return null;
    }

    private function refundMatchesPendingAttempt(array $refund, array $order): bool
    {
        $metadata = $refund['metadata'] ?? null;
        $paymentIntent = $refund['payment_intent'] ?? null;
        $paymentIntentId = is_array($paymentIntent)
            ? (string) ($paymentIntent['id'] ?? '')
            : (string) $paymentIntent;
        if ($paymentIntentId === '' || !hash_equals((string) $order['payment_intent_id'], $paymentIntentId)
            || (((string) ($order['payment_refund_id'] ?? '') === '' || ($refund['id'] ?? null) !== $order['payment_refund_id'])
                && (!is_array($metadata) || (string) ($metadata['order_number'] ?? '') !== (string) $order['order_number']
                    || (int) ($metadata['refund_attempt'] ?? 0) !== (int) $order['payment_refund_attempt']))) {
            return false;
        }
        if (!PaymentPolicy::stripeObjectMatchesOrder($refund, $order)) {
            throw new RuntimeException('Stripe refund mode does not match the environment and local order.');
        }
        return true;
    }

    private function recordRefundReconciliationError(array $order, Throwable $exception): void
    {
        $providerError = $exception instanceof StripeApiException;
        $errorCode = $providerError ? ($exception->providerCode ?? 'stripe_refund_reconciliation_failed') : 'stripe_refund_reconciliation_failed';
        $errorMessage = $providerError
            ? $exception->providerMessage
            : 'Automatic Stripe refund reconciliation failed. Check application logs.';
        $status = $providerError ? $exception->httpStatus : null;
        $errorMessage = substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $errorMessage) ?? 'Refund reconciliation failed.', 0, 500);
        try {
            $update = $this->pdo->prepare(
                "UPDATE orders SET payment_refund_error_code = :code, payment_refund_error_message = :message,
                        payment_refund_error_http_status = :httpStatus
                 WHERE id = :id AND payment_status = 'refund_pending' AND payment_refund_attempt = :attempt"
            );
            $update->execute([
                ':code' => $errorCode,
                ':message' => $errorMessage,
                ':httpStatus' => $status,
                ':id' => $order['id'],
                ':attempt' => $order['payment_refund_attempt'],
            ]);
        } catch (Throwable $saveException) {
            error_log('Could not persist Stripe refund reconciliation error for order ' . $order['id'] . ': ' . $saveException->getMessage());
        }
    }

    private function deliverConfirmationEmails(int $limit): int
    {
        $query = $this->pdo->prepare(
            "SELECT id FROM payment_notification_outbox
             WHERE (status = 'pending' AND available_at <= CURRENT_TIMESTAMP)
                OR (status = 'processing' AND locked_until <= CURRENT_TIMESTAMP)
             ORDER BY id LIMIT :limit"
        );
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->execute();
        $ids = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
        $sent = 0;
        foreach ($ids as $id) {
            $this->pdo->beginTransaction();
            try {
                $claim = $this->pdo->prepare(
                    "UPDATE payment_notification_outbox SET status = 'processing', attempts = attempts + 1,
                            locked_until = CURRENT_TIMESTAMP + INTERVAL 5 MINUTE
                     WHERE id = :id AND ((status = 'pending' AND available_at <= CURRENT_TIMESTAMP)
                        OR (status = 'processing' AND locked_until <= CURRENT_TIMESTAMP))"
                );
                $claim->execute([':id' => $id]);
                if ($claim->rowCount() !== 1) {
                    $this->pdo->commit();
                    continue;
                }
                $load = $this->pdo->prepare(
                    'SELECT n.order_id AS orderId, n.attempts, n.consecutive_failures AS consecutiveFailures,
                            n.notification_type AS notificationType,
                            n.reference_id AS referenceId, o.order_number, o.email, o.total, o.currency,
                            o.address_line, o.city, o.postal_code, o.region, o.country
                     FROM payment_notification_outbox n INNER JOIN orders o ON o.id = n.order_id WHERE n.id = :id'
                );
                $load->execute([':id' => $id]);
                $message = $load->fetch();
                $this->pdo->commit();
                if (!$message) continue;

                $currency = (string) $message['currency'];
                $symbol = $currency === 'EUR' ? '€' : '$';
                $address = implode(', ', array_filter([
                    $message['address_line'], $message['postal_code'], $message['city'], $message['region'], $message['country'],
                ], static fn ($value): bool => is_string($value) && trim($value) !== ''));
                if ($message['notificationType'] === 'refund_confirmation') {
                    $refundQuery = $this->pdo->prepare(
                        "SELECT r.amount_cents, r.reason FROM payment_refund_attempts r
                         WHERE r.order_id = :orderId AND r.attempt = :attempt
                            AND (r.status = 'succeeded' OR (r.status = 'manual' AND r.provider_error_code = 'manual_refunded'))
                            AND NOT EXISTS(SELECT 1 FROM payment_external_refunds e WHERE e.stripe_refund_id = r.stripe_refund_id
                                AND e.resolution = 'unmatched' AND e.provider_status IN ('failed','canceled','requires_action')) LIMIT 1"
                    );
                    $refundQuery->execute([':orderId' => $message['orderId'], ':attempt' => $message['referenceId']]);
                    $refund = $refundQuery->fetch();
                    if (!$refund) throw new RuntimeException('Confirmed refund details are missing from the refund ledger.');
                    $refunded = number_format(((int) $refund['amount_cents']) / 100, 2, '.', '');
                    $body = "A refund of {$symbol}{$refunded} {$currency} for order {$message['order_number']} has been confirmed.\n\n"
                        . "Reference: {$message['referenceId']}\nReason: {$refund['reason']}\n\n"
                        . 'Your bank or payment provider may take additional time to display the funds.';
                    $subject = "Refund confirmed · {$message['order_number']}";
                } elseif ($message['notificationType'] === 'full_refund_confirmation') {
                    $state = $this->pdo->prepare("SELECT 1 FROM orders WHERE id = :id AND payment_status = 'refunded'
                        AND payment_refunded_cents = ROUND(total * 100)
                        AND NOT EXISTS(SELECT 1 FROM payment_external_refunds e WHERE e.order_id = orders.id AND e.resolution = 'unmatched')");
                    $state->execute([':id' => $message['orderId']]);
                    if (!$state->fetchColumn()) throw new RuntimeException('Full refund confirmation is no longer current; support must review the provider reversal.');
                    $total = number_format((float) $message['total'], 2, '.', '');
                    $body = "A full refund of {$symbol}{$total} {$currency} for order {$message['order_number']} has been confirmed.\n\n"
                        . "Reference: {$message['referenceId']}\n\n"
                        . 'Your bank or payment provider may take additional time to display the funds.';
                    $subject = "Refund confirmed · {$message['order_number']}";
                } elseif ($message['notificationType'] === 'refund_reversal_notice') {
                    $subject = "Refund status changed · {$message['order_number']}";
                    $body = "The payment provider returned a refund or requested corrected recipient information for order {$message['order_number']}.\n\n"
                        . "Reference: {$message['referenceId']}\n\n"
                        . 'Our support team must reconfirm the refund route. This accounting review does not charge you again. Contact support for the latest status.';
                } else {
                    $itemsQuery = $this->pdo->prepare('SELECT product_name, quantity FROM order_items WHERE order_id = :orderId ORDER BY id');
                    $itemsQuery->execute([':orderId' => $message['orderId']]);
                    $items = array_map(static fn (array $item): string => $item['product_name'] . ' × ' . $item['quantity'], $itemsQuery->fetchAll());
                    $total = number_format((float) $message['total'], 2, '.', '');
                    $body = "We received your payment for order {$message['order_number']}.\n\n"
                        . "Items:\n- " . implode("\n- ", $items) . "\n\n"
                        . "Total paid: {$symbol}{$total} {$currency}\nDelivery address: {$address}\n\n"
                        . 'We will email you again when your order is prepared for dispatch.';
                    $subject = "Payment confirmed · {$message['order_number']}";
                }
                try {
                    $this->mailer->send((string) $message['email'], $subject, $body);
                    $markSent = $this->pdo->prepare(
                        "UPDATE payment_notification_outbox SET status = 'sent', sent_at = CURRENT_TIMESTAMP,
                                locked_until = NULL, consecutive_failures = 0, last_error = NULL
                         WHERE id = :id AND status = 'processing'"
                    );
                    $markSent->execute([':id' => $id]);
                    $sent++;
                } catch (Throwable $exception) {
                    $failure = PaymentNotificationPolicy::afterDeliveryFailure((int) $message['consecutiveFailures']);
                    $error = PaymentNotificationPolicy::safeDiagnostic($exception->getMessage());
                    $retry = $this->pdo->prepare(
                        "UPDATE payment_notification_outbox SET status = :status,
                                consecutive_failures = :failures,
                                available_at = CURRENT_TIMESTAMP + INTERVAL {$failure['delaySeconds']} SECOND,
                                locked_until = NULL, last_error = :error WHERE id = :id AND status = 'processing'"
                    );
                    $retry->execute([
                        ':status' => $failure['status'],
                        ':failures' => $failure['consecutiveFailures'],
                        ':error' => $error,
                        ':id' => $id,
                    ]);
                    error_log('Payment notification delivery failed for outbox row ' . $id . ' (' . $failure['status'] . '): ' . $error);
                }
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                error_log('Payment confirmation outbox processing failed for row ' . $id . ': ' . $exception->getMessage());
            }
        }
        return $sent;
    }

    private function syncDisputes(int $limit): int
    {
        if (!$this->stripe->configured()) return 0;
        $synced = 0;
        $backfillError = null;
        try {
            $recovery = new DisputeBackfillService($this->pdo, $this->stripe, $this->recordDispute(...));
            $synced += $recovery->run();
        } catch (Throwable $exception) {
            $backfillError = $exception;
            error_log('Stripe dispute backfill paused without advancing its cursor: '
                . PaymentNotificationPolicy::safeDiagnostic($exception->getMessage()));
        }

        $open = $this->pdo->prepare(
            "SELECT stripe_dispute_id, charge_id FROM payment_disputes
             WHERE status NOT IN ('won','lost','warning_closed')
               AND (last_synced_at IS NULL OR last_synced_at < CURRENT_TIMESTAMP - INTERVAL 5 MINUTE)
             ORDER BY evidence_due_at IS NULL, evidence_due_at LIMIT :limit"
        );
        $open->bindValue(':limit', min($limit, 25), PDO::PARAM_INT);
        $open->execute();
        foreach ($open->fetchAll() as $row) {
            try {
                $dispute = $this->stripe->retrieveDispute((string) $row['stripe_dispute_id']);
                if (empty($dispute['payment_intent']) && is_string($row['charge_id']) && $row['charge_id'] !== '') {
                    $charge = $this->stripe->retrieveCharge((string) $row['charge_id']);
                    if (!empty($charge['payment_intent'])) $dispute['payment_intent'] = $charge['payment_intent'];
                }
                $this->pdo->beginTransaction();
                try {
                    $this->recordDispute($dispute);
                    $this->pdo->commit();
                    $synced++;
                } catch (Throwable $exception) {
                    if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                    throw $exception;
                }
            } catch (Throwable $exception) {
                $error = PaymentNotificationPolicy::safeDiagnostic($exception->getMessage());
                $saveError = $this->pdo->prepare('UPDATE payment_disputes SET last_synced_at = CURRENT_TIMESTAMP, last_sync_error = :error WHERE stripe_dispute_id = :id');
                $saveError->execute([':error' => $error, ':id' => $row['stripe_dispute_id']]);
                error_log('Stripe dispute refresh failed: ' . $error);
            }
        }
        if ($backfillError !== null) {
            throw new RuntimeException('Stripe dispute backfill failed; its cursor was retained for retry.', 0, $backfillError);
        }
        return $synced;
    }

}

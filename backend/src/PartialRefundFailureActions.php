<?php

declare(strict_types=1);

/** Focused payment-domain operations extracted from the payment facade. */
trait PartialRefundFailureActions
{
    private function finishPartialRefundFailure(
        array $order,
        int $attempt,
        string $errorCode,
        string $errorMessage,
        ?int $adminId,
        bool $unknown,
        ?string $refundId = null
    ): void {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $ledgerStatus = $unknown ? 'unknown' : 'failed';
            $ledger = $this->pdo->prepare(
                'UPDATE payment_refund_attempts SET stripe_refund_id = COALESCE(:refundId, stripe_refund_id),
                        status = :status, provider_error_code = :errorCode, provider_error_message = :errorMessage
                 WHERE order_id = :orderId AND attempt = :attempt AND status IN (\'pending\',\'unknown\')'
            );
            $ledger->execute([
                ':refundId' => $refundId,
                ':status' => $ledgerStatus,
                ':errorCode' => substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $errorCode) ?: 'provider_error', 0, 100),
                ':errorMessage' => substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $errorMessage) ?? 'Provider refund failed.', 0, 500),
                ':orderId' => $order['id'],
                ':attempt' => $attempt,
            ]);
            if (!$unknown) {
                $returnQuery = $this->pdo->prepare('SELECT withdrawal_request_id FROM payment_refund_attempts WHERE order_id = :orderId AND attempt = :attempt');
                $returnQuery->execute([':orderId' => $order['id'], ':attempt' => $attempt]);
                $withdrawalRequestId = $returnQuery->fetchColumn();
                if ($withdrawalRequestId !== false && $withdrawalRequestId !== null) {
                    $updateReturn = $this->pdo->prepare("UPDATE withdrawal_requests SET status = 'refund_failed' WHERE id = :id AND status = 'refund_pending'");
                    $updateReturn->execute([':id' => $withdrawalRequestId]);
                    if ($updateReturn->rowCount() === 1) {
                        $event = $this->pdo->prepare(
                            "INSERT INTO withdrawal_request_events (request_id, actor_role, event_name, previous_status, new_status, note)
                             VALUES (:id, 'system', 'refund.failed', 'refund_pending', 'refund_failed', :note)"
                        );
                        $event->execute([':id' => $withdrawalRequestId, ':note' => $errorCode . ': ' . $errorMessage]);
                    }
                }
            }
            if (!$unknown) {
                $restore = $this->pdo->prepare(
                    "UPDATE orders SET payment_status = (
                     SELECT previous_payment_status FROM payment_refund_attempts WHERE order_id = :orderId AND attempt = :attemptLookup
                        ), payment_refund_outcome = 'definitive_failure', payment_refund_id = COALESCE(:refundId, payment_refund_id),
                        payment_refund_error_code = :errorCode, payment_refund_error_message = :errorMessage,
                        payment_refund_error_http_status = NULL
                     WHERE id = :id AND payment_status = 'refund_pending' AND payment_refund_attempt = :attemptCurrent"
                );
                $restore->execute([
                    ':orderId' => $order['id'], ':attemptLookup' => $attempt, ':refundId' => $refundId,
                    ':errorCode' => substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $errorCode) ?: 'provider_error', 0, 100),
                    ':errorMessage' => substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $errorMessage) ?? 'Provider refund failed.', 0, 500),
                    ':id' => $order['id'], ':attemptCurrent' => $attempt,
                ]);
            } else {
                $saveUnknown = $this->pdo->prepare(
                    "UPDATE orders SET payment_refund_outcome = 'unknown', payment_refund_id = COALESCE(:refundId, payment_refund_id),
                            payment_refund_error_code = :errorCode, payment_refund_error_message = :errorMessage
                     WHERE id = :id AND payment_status = 'refund_pending' AND payment_refund_attempt = :attempt"
                );
                $saveUnknown->execute([
                    ':refundId' => $refundId, ':errorCode' => substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $errorCode) ?: 'provider_error', 0, 100),
                    ':errorMessage' => substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $errorMessage) ?? 'Provider refund outcome unknown.', 0, 500),
                    ':id' => $order['id'], ':attempt' => $attempt,
                ]);
            }
            $audit = $this->pdo->prepare(
                "INSERT INTO admin_audit_log (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, :action, 'order', :orderId, 'refund_pending', :new)"
            );
            $audit->execute([
                ':adminId' => $adminId, ':action' => $unknown ? 'order.partial_refund_unknown' : 'order.partial_refund_failed',
                ':orderId' => $order['id'], ':new' => $ledgerStatus . '; attempt=' . $attempt . '; ' . $errorCode . ': ' . $errorMessage,
            ]);
            if ($ownsTransaction) $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function refundAttemptRow(int $orderId, int $attempt): ?array
    {
        $query = $this->pdo->prepare("SELECT * FROM payment_refund_attempts WHERE order_id = :orderId AND attempt = :attempt AND attempt_kind = 'partial' LIMIT 1");
        $query->execute([':orderId' => $orderId, ':attempt' => $attempt]);
        $row = $query->fetch();
        return $row ?: null;
    }
}

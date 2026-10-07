<?php

declare(strict_types=1);

/** Focused payment-domain operations extracted from the payment facade. */
trait RefundAuditActions
{
    protected function verifyProviderRefundLedger(array $order): void
    {
        $this->externalRefunds->scanOrder($order);
        ExternalRefundService::assertReconciled($this->pdo, (int) $order['id']);
    }

    private function auditRefundState(?int $adminId, int $orderId, string $action, string $previous, string $new, string $detail): void
    {
        $audit = $this->pdo->prepare(
            'INSERT INTO admin_audit_log
                (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
             VALUES (:adminId, :action, \'order\', :orderId, :previous, :new)'
        );
        $audit->execute([
            ':adminId' => $adminId,
            ':action' => $action,
            ':orderId' => $orderId,
            ':previous' => $previous,
            ':new' => $new . '; ' . $this->boundedDiagnostic($detail),
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
}

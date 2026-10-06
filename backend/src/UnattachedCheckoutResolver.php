<?php

declare(strict_types=1);

/** Resolves aged Stripe checkout attempts with no attached session after an audited human check. */
final class UnattachedCheckoutResolver
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function resolve(array $order, array $admin, string $reason): array
    {
        if (($order['payment_provider'] ?? null) !== 'stripe'
            || !in_array($order['status'], ['awaiting_payment', 'cancelled'], true)
            || $order['payment_status'] !== 'pending'
            || is_string($order['payment_session_id'] ?? null)) {
            throw new HttpException('Este pedido não tem uma tentativa Stripe sem sessão que possa ser resolvida manualmente.', 409, [], 'checkout_resolution_not_applicable');
        }
        if (!PaymentPolicy::requiresManualCheckoutReconciliation($order)) {
            throw new HttpException('A tentativa ainda está na janela segura de repetição. Consulte ou retome a mesma tentativa antes de uma resolução manual.', 409, [], 'checkout_resolution_too_early');
        }

        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT status, payment_status, payment_provider, payment_session_id,
                        payment_session_attempted_at, created_at
                 FROM orders WHERE id = :id FOR UPDATE'
            );
            $select->execute([':id' => $order['id']]);
            $current = $select->fetch();
            if (!$current || ($current['payment_provider'] ?? null) !== 'stripe'
                || !in_array($current['status'], ['awaiting_payment', 'cancelled'], true)
                || $current['payment_status'] !== 'pending'
                || is_string($current['payment_session_id'] ?? null)) {
                throw new HttpException('O pedido mudou durante a reconciliação. Atualize-o antes de continuar.', 409, [], 'checkout_resolution_state_changed');
            }
            if (!PaymentPolicy::requiresManualCheckoutReconciliation($current)) {
                throw new HttpException('A tentativa ainda não pode ser resolvida manualmente.', 409, [], 'checkout_resolution_too_early');
            }

            $previous = $current['status'] . ':pending';
            if ($current['status'] === 'awaiting_payment') {
                $this->restoreStock((int) $order['id']);
                $update = $this->pdo->prepare(
                    "UPDATE orders SET status = 'cancelled', payment_status = 'failed', reservation_expires_at = NULL
                     WHERE id = :id AND status = 'awaiting_payment' AND payment_status = 'pending'
                           AND payment_provider = 'stripe' AND payment_session_id IS NULL"
                );
            } else {
                $update = $this->pdo->prepare(
                    "UPDATE orders SET payment_status = 'failed', reservation_expires_at = NULL
                     WHERE id = :id AND status = 'cancelled' AND payment_status = 'pending'
                           AND payment_provider = 'stripe' AND payment_session_id IS NULL"
                );
            }
            $update->execute([':id' => $order['id']]);
            if ($update->rowCount() !== 1) {
                throw new HttpException('O pedido mudou durante a reconciliação. Atualize-o antes de continuar.', 409, [], 'checkout_resolution_state_changed');
            }

            $audit = $this->pdo->prepare(
                'INSERT INTO admin_audit_log
                    (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, \'order.checkout_attempt_resolved\', \'order\', :orderId, :previous, :new)'
            );
            $audit->execute([
                ':adminId' => $admin['id'],
                ':orderId' => $order['id'],
                ':previous' => $previous,
                ':new' => 'cancelled:failed; Stripe checked manually: ' . $reason,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }

        return [
            'message' => $order['status'] === 'awaiting_payment'
                ? 'A tentativa sem sessão foi marcada como não paga após verificação manual, e o estoque foi liberado.'
                : 'O pagamento pendente foi marcado como não pago após verificação manual.',
        ];
    }

    private function restoreStock(int $orderId): void
    {
        $items = $this->pdo->prepare(
            'SELECT product_id, SUM(quantity) AS quantity FROM order_items
             WHERE order_id = :orderId AND product_id IS NOT NULL GROUP BY product_id'
        );
        $items->execute([':orderId' => $orderId]);
        $restore = $this->pdo->prepare('UPDATE products SET stock = stock + :quantity WHERE id = :productId');
        foreach ($items->fetchAll() as $item) {
            $restore->execute([':quantity' => (int) $item['quantity'], ':productId' => (int) $item['product_id']]);
        }
    }
}

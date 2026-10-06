<?php

declare(strict_types=1);

final class InventoryService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function releaseExpiredReservations(): int
    {
        $lock = $this->pdo->query("SELECT GET_LOCK('botanical.inventory-release', 0)");
        if ((int) $lock->fetchColumn() !== 1) {
            return 0;
        }
        try {
            $this->pdo->beginTransaction();
            try {
                $statement = $this->pdo->query(
                    "SELECT id
                     FROM orders
                     WHERE status = 'awaiting_payment'
                       AND payment_status = 'pending'
                       AND reservation_expires_at IS NOT NULL
                       AND reservation_expires_at <= CURRENT_TIMESTAMP
                       AND payment_session_id IS NULL
                       AND (payment_provider IS NULL OR payment_provider <> 'stripe')
                     ORDER BY reservation_expires_at ASC
                     LIMIT 100
                     FOR UPDATE"
                );
                $orderIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
                if ($orderIds === []) {
                    $this->pdo->commit();
                    return 0;
                }

                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                $items = $this->pdo->prepare(
                    "SELECT product_id, SUM(quantity) AS quantity
                     FROM order_items
                     WHERE order_id IN ({$placeholders}) AND product_id IS NOT NULL
                     GROUP BY product_id"
                );
                $items->execute($orderIds);
                $restore = $this->pdo->prepare('UPDATE products SET stock = stock + :quantity WHERE id = :productId');
                foreach ($items->fetchAll() as $item) {
                    $restore->execute([
                        ':quantity' => (int) $item['quantity'],
                        ':productId' => (int) $item['product_id'],
                    ]);
                }

                $cancel = $this->pdo->prepare(
                    "UPDATE orders
                     SET status = 'cancelled', payment_status = 'failed', reservation_expires_at = NULL
                     WHERE id IN ({$placeholders})
                       AND status = 'awaiting_payment'
                       AND payment_status = 'pending'"
                );
                $cancel->execute($orderIds);
                $released = $cancel->rowCount();
                $this->pdo->commit();
                return $released;
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('botanical.inventory-release')");
        }
    }
}

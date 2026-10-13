<?php

declare(strict_types=1);

/** Ledger-wide safety: an order projection is not proof that all attempts settled. */
final class RefundLedgerSafety
{
    public function __construct(private readonly PDO $pdo) {}

    /** Caller holds the order lock; only a durable dispatcher may exclude its own exact attempt. */
    public function assertNoUnresolved(int $orderId, ?int $exceptAttempt = null): void
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('Refund ledger safety requires a financial transaction.');
        $exclude = $exceptAttempt === null ? '' : ' AND attempt <> :exceptAttempt';
        $query = $this->pdo->prepare("SELECT attempt FROM payment_refund_attempts
            WHERE order_id = :id AND status IN ('pending','unknown') {$exclude}
            ORDER BY attempt LIMIT 1 FOR UPDATE");
        $parameters = [':id' => $orderId];
        if ($exceptAttempt !== null) $parameters[':exceptAttempt'] = $exceptAttempt;
        $query->execute($parameters);
        $attempt = $query->fetchColumn();
        if ($attempt !== false) {
            throw new HttpException('Uma tentativa de reembolso do histórico ainda está pendente ou desconhecida. Concilie-a antes de enviar outra devolução.',
                409, ['attempt' => (int) $attempt], 'refund_history_unresolved');
        }
    }

    /** Read-only, one batch for the admin page; include identities without replacing its current pointer. */
    public function historicalUnresolvedForOrders(array $orders): array
    {
        if ($orders === []) return [];
        $currentAttempts = [];
        foreach ($orders as $order) $currentAttempts[(int) $order['id']] = (int) $order['refundAttempt'];
        $placeholders = implode(',', array_fill(0, count($currentAttempts), '?'));
        $query = $this->pdo->prepare("SELECT order_id, attempt, attempt_kind AS kind, stripe_refund_id AS id,
            amount_cents AS amountCents, status FROM payment_refund_attempts
            WHERE order_id IN ({$placeholders}) AND status IN ('pending','unknown') ORDER BY order_id, attempt");
        $query->execute(array_keys($currentAttempts));
        $history = [];
        foreach ($query->fetchAll() as $row) {
            $orderId = (int) $row['order_id'];
            if ((int) $row['attempt'] === $currentAttempts[$orderId]) continue;
            unset($row['order_id']);
            $row['attempt'] = (int) $row['attempt'];
            $row['amountCents'] = (int) $row['amountCents'];
            $history[$orderId][] = $row;
        }
        return $history;
    }
}

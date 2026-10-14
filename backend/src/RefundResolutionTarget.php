<?php

declare(strict_types=1);

/** The independently verified browser selection must survive the financial lock. */
final readonly class RefundResolutionTarget
{
    private function __construct(
        public string $orderNumber,
        public int $attempt,
        public ?string $refundId,
        public string $kind,
        public ?int $amountCents,
        public int $refundedTotalCents,
        public string $outcome,
        public int $totalCents,
        public string $currency
    ) {}

    public static function fromInput(array $input): self
    {
        if (!array_key_exists('refundTarget', $input)) {
            throw new HttpException('Atualize os pedidos e confira a tentativa antes de uma resolução manual.', 409, [], 'refund_target_required');
        }
        $target = $input['refundTarget'];
        if (!is_array($target) || array_is_list($target)) {
            throw new HttpException('A seleção de reembolso é inválida.', 422, [], 'refund_target_invalid');
        }
        $fields = ['orderNumber', 'attempt', 'refundId', 'kind', 'amountCents', 'refundedTotalCents', 'outcome', 'totalCents', 'currency'];
        Validation::allowedFields($target, $fields);
        foreach ($fields as $field) {
            if (!array_key_exists($field, $target)) throw new HttpException('A seleção de reembolso está incompleta.', 422, [], 'refund_target_invalid');
        }
        $attempt = Validation::integer($target, 'attempt', 1, PHP_INT_MAX);
        $orderNumber = Validation::requiredString($target, 'orderNumber', 8, 32);
        $outcome = Validation::oneOf($target, 'outcome', ['none', 'pending', 'unknown', 'definitive_failure', 'succeeded', 'manual_refunded', 'manual_not_refunded']);
        $total = Validation::integer($target, 'totalCents', 1, PHP_INT_MAX);
        $currency = Validation::oneOf($target, 'currency', ['EUR', 'USD']);
        $kind = Validation::oneOf($target, 'kind', ['full', 'partial']);
        $refunded = Validation::integer($target, 'refundedTotalCents', 0, PHP_INT_MAX);
        $amount = $target['amountCents'] === null ? null : Validation::integer($target, 'amountCents', 1, PHP_INT_MAX);
        if ($kind === 'partial' && $amount === null) throw new HttpException('O valor da tentativa parcial é obrigatório.', 422, [], 'refund_target_invalid');
        $refundId = $target['refundId'] === null ? null : Validation::requiredString($target, 'refundId', 4, 128);
        if ($refundId !== null && !preg_match('/^re_[A-Za-z0-9_]+$/D', $refundId)) {
            throw new HttpException('A referência de reembolso é inválida.', 422, [], 'refund_target_invalid');
        }
        return new self($orderNumber, $attempt, $refundId, $kind, $amount, $refunded, $outcome, $total, $currency);
    }

    /** Caller has locked both the order and any existing attempt in the same transaction. */
    public function assertMatches(PDO $pdo, array $order, ?array $ledger): void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Manual refund selection requires a financial transaction.');
        $actualReference = $ledger['stripe_refund_id'] ?? $order['payment_refund_id'] ?? null;
        $actualAmount = $ledger === null ? null : (int) $ledger['amount_cents'];
        if ($this->orderNumber !== $order['order_number']
            || $this->attempt !== (int) $order['payment_refund_attempt']
            || ($ledger !== null && (int) $ledger['attempt'] !== $this->attempt)
            || $this->refundId !== $actualReference
            || $this->kind !== ($ledger['attempt_kind'] ?? 'full')
            || $this->amountCents !== $actualAmount
            || $this->refundedTotalCents !== (int) $order['payment_refunded_cents']
            || $this->outcome !== $order['payment_refund_outcome']
            || $this->totalCents !== (int) round((float) $order['total'] * 100)
            || $this->currency !== strtoupper((string) $order['currency'])) {
            throw new HttpException('A tentativa ou o saldo mudou. Atualize os pedidos e confira novamente antes de resolver.', 409, [], 'refund_target_stale');
        }
    }
}

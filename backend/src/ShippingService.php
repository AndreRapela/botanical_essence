<?php

declare(strict_types=1);

final class ShippingService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimiter $limiter,
        private readonly ShippingTariffCalculator $calculator = new ShippingTariffCalculator()
    ) {
    }

    public function quote(array $input): array
    {
        Validation::allowedFields($input, ['country', 'postalCode', 'items']);
        $country = Validation::requiredString($input, 'country', 2, 80);
        $postalCode = Validation::requiredString($input, 'postalCode', 3, 12);
        $items = CartItems::quantities($input['items'] ?? null);

        $this->limiter->assertAllowed('shipping-quote', Request::ip(), 60, 3600);

        $placeholders = implode(',', array_fill(0, count($items), '?'));
        $statement = $this->pdo->prepare(
            "SELECT p.id, p.shipping_weight_grams AS shippingWeightGrams
             FROM products p
             INNER JOIN sellers s ON s.id = p.seller_id
             WHERE p.id IN ({$placeholders}) AND p.status = 'active' AND s.verified = TRUE"
        );
        $statement->execute(array_keys($items));
        $products = $statement->fetchAll();
        if (count($products) !== count($items)) {
            throw new HttpException('Um dos produtos não está disponível para entrega.', 409);
        }

        $netWeightGrams = 0;
        foreach ($products as $product) {
            $netWeightGrams += (int) $product['shippingWeightGrams'] * $items[(int) $product['id']];
        }

        return PaymentPolicy::freeTestShipping($this->calculator->quote($country, $postalCode, $netWeightGrams));
    }

    public function quotesForWeight(string $country, string $postalCode, int $netWeightGrams): array
    {
        return PaymentPolicy::freeTestShipping($this->calculator->quote($country, $postalCode, $netWeightGrams));
    }

    public function selectedQuote(array $quoteResponse, string $method): array
    {
        return $this->calculator->selectedQuote($quoteResponse, $method);
    }
}

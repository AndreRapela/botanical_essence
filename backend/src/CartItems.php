<?php

declare(strict_types=1);

/**
 * Normalizes the only cart representation accepted by the API.
 * Prices and stock deliberately never come from this payload.
 */
final class CartItems
{
    /** @return array<int, int> product id => quantity */
    public static function quantities(mixed $rawItems): array
    {
        if (!is_array($rawItems) || $rawItems === [] || count($rawItems) > 50) {
            throw new HttpException('A sacola está vazia ou inválida.', 422);
        }

        $quantities = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                throw new HttpException('Item da sacola inválido.', 422);
            }
            Validation::allowedFields($item, ['productId', 'quantity']);
            $id = Validation::integer($item, 'productId', 1, PHP_INT_MAX);
            $quantity = Validation::integer($item, 'quantity', 1, 20);
            $combined = ($quantities[$id] ?? 0) + $quantity;
            if ($combined > 20) {
                throw new HttpException('A quantidade máxima por produto é 20.', 422);
            }
            $quantities[$id] = $combined;
        }

        ksort($quantities, SORT_NUMERIC);
        return $quantities;
    }
}

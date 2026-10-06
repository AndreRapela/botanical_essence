<?php

declare(strict_types=1);

final class CatalogService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ExchangeRateService $exchangeRates
    )
    {
    }

    public function products(array $query): array
    {
        Validation::allowedFields($query, ['page', 'limit', 'marketplace', 'slug']);
        [$page, $limit, $offset] = $this->pagination($query);
        $marketplace = Validation::queryBoolean($query, 'marketplace');
        $slug = Validation::querySlug($query, 'slug');

        $where = "WHERE s.verified = TRUE AND p.status = 'active'";
        if ($marketplace !== null) {
            $where .= ' AND p.is_marketplace = :marketplace';
        }
        if ($slug !== null) {
            $where .= ' AND p.slug = :slug';
        }
        $statement = $this->pdo->prepare(
            "SELECT p.id, p.name, p.slug, p.description AS subtitle,
                    p.origin, p.dimensions, p.materials, p.care_instructions AS careInstructions,
                    CASE p.category
                      WHEN 'Plantas' THEN 'plants'
                      WHEN 'Casa & aromas' THEN 'home'
                      ELSE 'care'
                    END AS category,
                    p.price, p.shipping_weight_grams AS shippingWeightGrams,
                    p.stock, p.image_position AS sprite, p.badge,
                    p.is_marketplace AS marketplace, s.display_name AS seller, s.city,
                    s.state AS region, s.country,
                    COALESCE(r.average_rating, 0) AS averageRating,
                    COALESCE(r.review_count, 0) AS reviewCount
             FROM products p
             INNER JOIN sellers s ON s.id = p.seller_id
             LEFT JOIN (
                SELECT product_id, ROUND(AVG(rating), 1) AS average_rating, COUNT(*) AS review_count
                FROM product_reviews WHERE status = 'visible' GROUP BY product_id
             ) r ON r.product_id = p.id
             {$where}
             ORDER BY p.featured DESC, p.created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        if ($marketplace !== null) {
            $statement->bindValue(':marketplace', $marketplace ? 1 : 0, PDO::PARAM_INT);
        }
        if ($slug !== null) {
            $statement->bindValue(':slug', $slug);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $count = $this->pdo->prepare(
            "SELECT COUNT(*) FROM products p INNER JOIN sellers s ON s.id = p.seller_id {$where}"
        );
        if ($marketplace !== null) {
            $count->bindValue(':marketplace', $marketplace ? 1 : 0, PDO::PARAM_INT);
        }
        if ($slug !== null) {
            $count->bindValue(':slug', $slug);
        }
        $count->execute();

        $items = array_map(static function (array $product): array {
            $testPrice = PaymentPolicy::isTestProduct((string) $product['slug']);
            $priceCents = PaymentPolicy::unitCents(
                (string) $product['slug'],
                (int) round((float) $product['price'] * 100)
            );
            return [
                ...$product,
                'id' => (int) $product['id'],
                'price' => $priceCents / 100,
                'testPrice' => $testPrice,
                'shippingWeightGrams' => (int) $product['shippingWeightGrams'],
                'stock' => (int) $product['stock'],
                'sprite' => (int) $product['sprite'],
                'marketplace' => (bool) $product['marketplace'],
                'averageRating' => (float) $product['averageRating'],
                'reviewCount' => (int) $product['reviewCount'],
                'currency' => 'USD',
            ];
        }, $statement->fetchAll());

        try {
            $rate = $this->exchangeRates->currentReferenceRate();
        } catch (HttpException $exception) {
            if ($exception->errorCode !== 'exchange_rate_unavailable') {
                throw $exception;
            }
            $rate = null;
        }
        return [
            'data' => $items,
            'meta' => [
                ...$this->metadata($page, $limit, (int) $count->fetchColumn()),
                'baseCurrency' => 'USD',
                'currencyRates' => ['USD' => 1.0, 'EUR' => $rate['eurPerUsd'] ?? null],
                'currencyRateDate' => $rate['rateDate'] ?? null,
                'currencyRateSource' => 'ecb-daily-reference',
                'currencyRateType' => 'daily_reference',
                'currencyRateToken' => $rate['rateToken'] ?? null,
                'currencyRateStatus' => $rate['status'] ?? 'unavailable',
                'currencyRateCheckedAt' => $rate['checkedAt'] ?? null,
            ],
        ];
    }

    public function sellers(array $query): array
    {
        Validation::allowedFields($query, ['page', 'limit']);
        [$page, $limit, $offset] = $this->pagination($query);
        $statement = $this->pdo->prepare(
            'SELECT s.id, s.display_name AS displayName, s.bio, s.city,
                    s.state AS region, s.country, s.rating, s.verified,
                    COUNT(p.id) AS productCount
             FROM sellers s LEFT JOIN products p ON p.seller_id = s.id AND p.status = \'active\'
             WHERE s.verified = TRUE
             GROUP BY s.id ORDER BY s.rating DESC, s.id ASC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM sellers WHERE verified = TRUE')->fetchColumn();

        $items = array_map(static fn (array $seller): array => [
            ...$seller,
            'id' => (int) $seller['id'],
            'rating' => (float) $seller['rating'],
            'verified' => (bool) $seller['verified'],
            'productCount' => (int) $seller['productCount'],
        ], $statement->fetchAll());

        return ['data' => $items, 'meta' => $this->metadata($page, $limit, $total)];
    }

    private function pagination(array $query): array
    {
        $page = Validation::queryInteger($query, 'page', 1, 1, 10_000);
        $limit = Validation::queryInteger($query, 'limit', 24, 1, 100);
        return [$page, $limit, ($page - 1) * $limit];
    }

    private function metadata(int $page, int $limit, int $total): array
    {
        return [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $limit)),
        ];
    }

}

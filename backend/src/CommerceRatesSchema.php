<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaMigrationHelpers.php';

final class CommerceRatesSchema
{
    use SchemaMigrationHelpers;

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS commerce_exchange_rates (
                pair VARCHAR(7) PRIMARY KEY,
                usd_per_eur DECIMAL(14,10) NULL,
                rate_date DATE NULL,
                source VARCHAR(40) NULL,
                checked_at TIMESTAMP NULL,
                failure_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
                last_error_code VARCHAR(40) NULL
            ) ENGINE=InnoDB'
        );
        self::ensureColumn($pdo, 'orders', 'shipping_tariff_reviewed_at', 'DATE NULL AFTER shipping_tariff_source');
        self::ensureColumn($pdo, 'orders', 'shipping_tariff_valid_through', 'DATE NULL AFTER shipping_tariff_reviewed_at');
        self::ensureColumn($pdo, 'orders', 'fx_usd_per_eur', 'DECIMAL(14,10) NULL AFTER shipping_tariff_valid_through');
        self::ensureColumn($pdo, 'orders', 'fx_rate_date', 'DATE NULL AFTER fx_usd_per_eur');
        self::ensureColumn($pdo, 'orders', 'fx_rate_source', 'VARCHAR(40) NULL AFTER fx_rate_date');
    }
}

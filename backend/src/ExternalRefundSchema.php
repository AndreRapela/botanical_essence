<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaMigrationHelpers.php';

final class ExternalRefundSchema
{
    use SchemaMigrationHelpers;
    private const VERSION = '2026-10-07-external-refund-reconciliation-v22';

    public static function migrate(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::VERSION]);
        if ($check->fetchColumn()) return;
        self::ensureColumn($pdo, 'orders', 'payment_refunds_scanned_at', 'TIMESTAMP NULL');
        self::ensureColumn($pdo, 'orders', 'payment_refunds_scan_error', 'VARCHAR(500) NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS payment_external_refunds (
            stripe_refund_id VARCHAR(128) PRIMARY KEY,
            order_id BIGINT UNSIGNED NULL,
            payment_intent_id VARCHAR(128) NULL,
            amount_cents BIGINT UNSIGNED NOT NULL,
            currency CHAR(3) NOT NULL,
            livemode BOOLEAN NOT NULL,
            provider_status VARCHAR(32) NOT NULL,
            resolution ENUM('unmatched','linked','external','irrelevant','reversed') NOT NULL DEFAULT 'unmatched',
            local_attempt INT UNSIGNED NULL,
            reviewed_by_admin_id BIGINT UNSIGNED NULL,
            review_reason VARCHAR(500) NULL,
            review_revision INT UNSIGNED NOT NULL DEFAULT 0,
            last_synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_external_refund_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
            CONSTRAINT fk_external_refund_admin FOREIGN KEY (reviewed_by_admin_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_external_refund_queue (resolution, created_at),
            INDEX idx_external_refund_order (order_id, resolution)
        ) ENGINE=InnoDB");
        self::ensureIndex($pdo, 'orders', 'idx_orders_refund_scan', '(payment_provider, payment_refunds_scanned_at)');
        $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)')->execute([':version' => self::VERSION]);
    }
}

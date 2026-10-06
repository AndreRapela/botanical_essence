<?php

declare(strict_types=1);

final class RefundRecoverySchema
{
    private const VERSION = '2026-10-04-refund-recovery-v16';

    public static function migrate(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        self::ensureColumn($pdo, 'orders', 'payment_refund_outcome', "VARCHAR(24) NOT NULL DEFAULT 'none' AFTER payment_refund_attempt");
        self::ensureColumn($pdo, 'orders', 'payment_refund_error_code', 'VARCHAR(100) NULL AFTER payment_refund_outcome');
        self::ensureColumn($pdo, 'orders', 'payment_refund_error_message', 'VARCHAR(500) NULL AFTER payment_refund_error_code');
        self::ensureColumn($pdo, 'orders', 'payment_refund_error_http_status', 'SMALLINT UNSIGNED NULL AFTER payment_refund_error_message');
        $pdo->exec("UPDATE orders SET payment_refund_outcome = 'unknown' WHERE payment_status = 'refund_pending' AND payment_refund_outcome = 'none'");
        $pdo->exec(
            "UPDATE orders SET payment_refund_outcome = 'definitive_failure',
                    payment_refund_error_code = COALESCE(payment_refund_error_code, 'legacy_definitive_failure'),
                    payment_refund_error_message = COALESCE(payment_refund_error_message, 'An earlier application version recorded this refund attempt as failed.'),
                    payment_refund_error_http_status = NULL
             WHERE payment_provider = 'stripe' AND payment_status = 'paid'
                   AND payment_refund_attempt > 0 AND payment_refund_outcome = 'none'"
        );
        $pdo->exec('ALTER TABLE admin_audit_log MODIFY COLUMN previous_value TEXT NULL, MODIFY COLUMN new_value TEXT NULL');

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::VERSION]);
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $query = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
        );
        $query->execute([':table' => $table, ':column' => $column]);
        if ((int) $query->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }
}

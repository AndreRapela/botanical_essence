<?php

declare(strict_types=1);

final class PaymentOperationsSchema
{
    private const VERSION = '2026-10-05-payment-notification-recovery-v20';

    public static function migrate(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        $pdo->exec("ALTER TABLE orders MODIFY COLUMN payment_status ENUM('pending','paid','partially_refunded','failed','refunded','refund_pending') NOT NULL DEFAULT 'pending'");
        self::ensureColumn($pdo, 'orders', 'payment_refunded_cents', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER payment_refund_attempt');
        self::ensureColumn($pdo, 'orders', 'payment_reconciled_at', 'TIMESTAMP NULL AFTER payment_refunded_cents');
        self::ensureColumn($pdo, 'orders', 'payment_refund_reconciled_at', 'TIMESTAMP NULL AFTER payment_refund_error_http_status');
        self::ensureIndex($pdo, 'orders', 'idx_orders_refund_reconcile', '(payment_provider, payment_status, payment_refund_outcome, payment_refund_reconciled_at)');
        $pdo->exec("UPDATE orders SET payment_refunded_cents = ROUND(total * 100) WHERE payment_status = 'refunded' AND payment_refunded_cents = 0");

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS payment_refund_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                attempt INT UNSIGNED NOT NULL,
                attempt_kind ENUM('partial','full') NOT NULL DEFAULT 'partial',
                stripe_refund_id VARCHAR(128) NULL,
                amount_cents BIGINT UNSIGNED NOT NULL,
                currency CHAR(3) NOT NULL,
                previous_payment_status VARCHAR(32) NOT NULL,
                previous_order_status VARCHAR(32) NOT NULL,
                withdrawal_request_id BIGINT UNSIGNED NULL,
                status ENUM('pending','unknown','succeeded','failed','manual') NOT NULL DEFAULT 'pending',
                reason VARCHAR(500) NOT NULL,
                provider_error_code VARCHAR(100) NULL,
                provider_error_message VARCHAR(500) NULL,
                requested_by_admin_id BIGINT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_payment_refund_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_payment_refund_admin FOREIGN KEY (requested_by_admin_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_payment_refund_withdrawal FOREIGN KEY (withdrawal_request_id) REFERENCES withdrawal_requests(id) ON DELETE SET NULL,
                UNIQUE KEY uq_payment_refund_attempt (order_id, attempt),
                UNIQUE KEY uq_payment_refund_provider (stripe_refund_id),
                INDEX idx_payment_refund_status (status, updated_at)
            ) ENGINE=InnoDB"
        );
        self::ensureColumn($pdo, 'payment_refund_attempts', 'attempt_kind', "ENUM('partial','full') NOT NULL DEFAULT 'partial' AFTER attempt");
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS payment_notification_outbox (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                notification_type VARCHAR(60) NOT NULL,
                reference_id VARCHAR(128) NOT NULL DEFAULT '',
                status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                consecutive_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                locked_until TIMESTAMP NULL,
                last_error VARCHAR(500) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at TIMESTAMP NULL,
                CONSTRAINT fk_payment_notification_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                UNIQUE KEY uq_payment_notification_once (order_id, notification_type, reference_id),
                INDEX idx_payment_notification_ready (status, available_at, locked_until)
            ) ENGINE=InnoDB"
        );
        self::ensureColumn($pdo, 'payment_notification_outbox', 'consecutive_failures', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER attempts');
        $pdo->exec("ALTER TABLE payment_notification_outbox MODIFY COLUMN status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending'");
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS payment_disputes (
                stripe_dispute_id VARCHAR(128) PRIMARY KEY,
                order_id BIGINT UNSIGNED NULL,
                payment_intent_id VARCHAR(128) NULL,
                charge_id VARCHAR(128) NULL,
                status VARCHAR(60) NOT NULL,
                reason VARCHAR(100) NULL,
                amount_cents BIGINT UNSIGNED NOT NULL,
                currency CHAR(3) NOT NULL,
                evidence_due_at TIMESTAMP NULL,
                livemode BOOLEAN NOT NULL,
                provider_created_at TIMESTAMP NULL,
                last_synced_at TIMESTAMP NULL,
                last_sync_error VARCHAR(500) NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_payment_dispute_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
                INDEX idx_payment_dispute_status_due (status, evidence_due_at),
                INDEX idx_payment_dispute_order (order_id, updated_at),
                INDEX idx_payment_dispute_payment_intent (payment_intent_id)
            ) ENGINE=InnoDB"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS payment_dispute_sync_state (
                id TINYINT UNSIGNED PRIMARY KEY,
                created_gte INT UNSIGNED NOT NULL,
                starting_after VARCHAR(128) NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB"
        );
        $state = $pdo->prepare(
            'INSERT IGNORE INTO payment_dispute_sync_state (id, created_gte) VALUES (1, :createdGte)'
        );
        $state->execute([':createdGte' => max(0, time() - 172800)]);

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

    private static function ensureIndex(PDO $pdo, string $table, string $index, string $columns): void
    {
        $query = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index'
        );
        $query->execute([':table' => $table, ':index' => $index]);
        if ((int) $query->fetchColumn() === 0) {
            $pdo->exec("CREATE INDEX `{$index}` ON `{$table}` {$columns}");
        }
    }
}

<?php

declare(strict_types=1);

final class LateRefundDispatchSchema
{
    private const VERSION = '2026-10-07-durable-late-refund-dispatch-v23';

    public static function migrate(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::VERSION]);
        if ($check->fetchColumn()) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS payment_refund_dispatches (
            order_id BIGINT UNSIGNED NOT NULL,
            attempt INT UNSIGNED NOT NULL,
            request_hash CHAR(64) NOT NULL,
            request_started_at TIMESTAMP NULL,
            state ENUM('queued','retry','submitted','failed','manual_required','closed') NOT NULL DEFAULT 'queued',
            available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error_code VARCHAR(100) NULL,
            last_error VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (order_id, attempt),
            CONSTRAINT fk_refund_dispatch_attempt FOREIGN KEY (order_id, attempt)
                REFERENCES payment_refund_attempts(order_id, attempt) ON DELETE CASCADE,
            INDEX idx_refund_dispatch_ready (state, available_at)
        ) ENGINE=InnoDB");
        $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)')->execute([':version' => self::VERSION]);
    }
}

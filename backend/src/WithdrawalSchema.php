<?php

declare(strict_types=1);

final class WithdrawalSchema
{
    private const VERSION = '2026-10-04-consumer-withdrawal-v2-language';

    public static function migrate(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS withdrawal_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_number VARCHAR(32) NOT NULL UNIQUE,
                order_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                language ENUM('en','fr') NOT NULL DEFAULT 'en',
                request_type ENUM('withdrawal','problem') NOT NULL,
                status ENUM('submitted','reviewing','instructions_sent','return_received','refund_pending','refund_failed','refunded','not_eligible') NOT NULL DEFAULT 'submitted',
                customer_message VARCHAR(2000) NULL,
                declared_delivery_date DATE NULL,
                admin_note VARCHAR(2000) NULL,
                handled_by_admin_id BIGINT UNSIGNED NULL,
                acknowledgment_sent_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_withdrawal_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_withdrawal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_withdrawal_admin FOREIGN KEY (handled_by_admin_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_withdrawal_order_status (order_id, status, id),
                INDEX idx_withdrawal_status_created (status, created_at),
                INDEX idx_withdrawal_user_created (user_id, created_at)
            ) ENGINE=InnoDB"
        );
        $languageColumn = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'withdrawal_requests' AND column_name = 'language'"
        );
        $languageColumn->execute();
        if ((int) $languageColumn->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE withdrawal_requests ADD COLUMN language ENUM('en','fr') NOT NULL DEFAULT 'en' AFTER user_id");
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS withdrawal_request_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_id BIGINT UNSIGNED NOT NULL,
                actor_user_id BIGINT UNSIGNED NULL,
                actor_role ENUM('customer','admin','system') NOT NULL,
                event_name VARCHAR(60) NOT NULL,
                previous_status VARCHAR(32) NULL,
                new_status VARCHAR(32) NULL,
                note VARCHAR(2000) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_withdrawal_event_request FOREIGN KEY (request_id) REFERENCES withdrawal_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_withdrawal_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_withdrawal_event_request (request_id, created_at)
            ) ENGINE=InnoDB"
        );

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::VERSION]);
    }
}

<?php

declare(strict_types=1);

final class CheckoutPreparationSchema
{
    private const VERSION = '2026-10-04-checkout-preparation-v17';

    public static function migrate(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::VERSION]);
        if ($check->fetchColumn()) {
            return;
        }

        $column = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
        );
        $column->execute([':table' => 'orders', ':column' => 'payment_ready_email_sent_at']);
        if ((int) $column->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_ready_email_sent_at TIMESTAMP NULL AFTER payment_session_id');
        }

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::VERSION]);
    }
}

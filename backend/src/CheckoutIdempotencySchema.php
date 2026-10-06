<?php

declare(strict_types=1);

/** Persists the first Stripe Checkout attempt time for safe, byte-stable idempotent replays. */
final class CheckoutIdempotencySchema
{
    private const VERSION = '2026-10-04-checkout-idempotency-v19';

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
        $column->execute([':table' => 'orders', ':column' => 'payment_session_attempted_at']);
        if ((int) $column->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_session_attempted_at TIMESTAMP NULL AFTER payment_session_id');
        }

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute([':version' => self::VERSION]);
    }
}

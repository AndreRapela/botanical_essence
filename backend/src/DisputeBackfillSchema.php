<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaMigrationHelpers.php';

final class DisputeBackfillSchema
{
    use SchemaMigrationHelpers;
    private const VERSION = '2026-10-07-dispute-backfill-window-v21';

    public static function migrate(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => self::VERSION]);
        if ($check->fetchColumn()) return;

        self::ensureColumn($pdo, 'payment_dispute_sync_state', 'scan_created_lte', 'INT UNSIGNED NULL AFTER starting_after');
        // Recover any gaps left by the previous moving watermark and its initial
        // 48-hour horizon. Upserts make replay of already recorded disputes safe.
        $pdo->exec('UPDATE payment_dispute_sync_state SET created_gte = 0, starting_after = NULL, scan_created_lte = NULL WHERE id = 1');
        $save = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $save->execute([':version' => self::VERSION]);
    }
}

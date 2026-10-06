<?php

declare(strict_types=1);

/** Focused payment-domain operations extracted from the payment facade. */
trait SchemaMigrationHelpers
{
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

    private static function ensureUniqueIndex(PDO $pdo, string $table, string $index, string $columns): void
    {
        $query = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index'
        );
        $query->execute([':table' => $table, ':index' => $index]);
        if ((int) $query->fetchColumn() === 0) {
            $pdo->exec("CREATE UNIQUE INDEX `{$index}` ON `{$table}` {$columns}");
        }
    }

    private static function ensureCheckConstraint(PDO $pdo, string $table, string $constraint, string $expression): void
    {
        $query = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE table_schema = DATABASE() AND table_name = :table
               AND constraint_name = :constraint AND constraint_type = \'CHECK\''
        );
        $query->execute([':table' => $table, ':constraint' => $constraint]);
        if ((int) $query->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK ({$expression})");
        }
    }
}

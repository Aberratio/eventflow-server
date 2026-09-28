<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;

/**
 * Evaluates a migration's `-- @guard:` marker against `information_schema`.
 *
 * MariaDB supports `ADD COLUMN IF NOT EXISTS` / `DROP COLUMN IF EXISTS` etc. on ALTER
 * sub-clauses; MySQL (production/staging engine) does not. A guard marker keeps the SQL
 * itself portable (plain ALTER, no vendor-only syntax) while still making the statement
 * safe to re-run: the Migrator checks the condition here and skips the statement if it
 * does not hold.
 */
final class MigrationGuard
{
    public const KINDS = [
        'column-missing',
        'column-exists',
        'index-missing',
        'index-exists',
        'foreign-key-missing',
    ];

    public static function holds(PDO $pdo, string $kind, string $table, string $object): bool
    {
        return match ($kind) {
            'column-missing' => self::columnCount($pdo, $table, $object) === 0,
            'column-exists' => self::columnCount($pdo, $table, $object) > 0,
            'index-missing' => self::indexCount($pdo, $table, $object) === 0,
            'index-exists' => self::indexCount($pdo, $table, $object) > 0,
            'foreign-key-missing' => self::foreignKeyCount($pdo, $table, $object) === 0,
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown guard kind "%s" (known: %s)',
                $kind,
                implode(', ', self::KINDS)
            )),
        };
    }

    private static function columnCount(PDO $pdo, string $table, string $column): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn();
    }

    private static function indexCount(PDO $pdo, string $table, string $index): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);

        return (int) $stmt->fetchColumn();
    }

    private static function foreignKeyCount(PDO $pdo, string $table, string $constraint): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? '
            . "AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $stmt->execute([$table, $constraint]);

        return (int) $stmt->fetchColumn();
    }
}

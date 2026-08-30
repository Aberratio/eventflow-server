<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;

/**
 * Removes demo accounts that predate the seed manifest.
 *
 * Until this cleanup existed, migration 003_test_login_users.sql inserted five
 * well-known accounts — including a superadmin whose bcrypt hash is committed to the
 * repository — on every environment the migrations ran against, production included.
 * The migration is gone, but the rows it already created are not, so this routine
 * deletes them explicitly. It is idempotent and safe to run on a database that never
 * had them.
 */
final class DemoData
{
    /** Accounts created by the removed 003_test_login_users.sql migration. */
    public const LEGACY_USER_IDS = [
        'test-participant',
        'test-organizer',
        'test-moderator',
        'test-admin',
        'test-superadmin',
    ];

    /** Credit batches created by the same migration. */
    public const LEGACY_CREDIT_BATCH_IDS = [
        'credit-test-organizer-standard-demo',
        'credit-test-organizer-virtual-demo',
        'credit-test-organizer-timing-demo',
    ];

    /**
     * Columns that hold a users.id. The schema declares almost no foreign keys, so the
     * link has to be found by column name rather than by constraint.
     */
    private const USER_REFERENCE_COLUMNS = [
        'user_id',
        'organizer_id',
        'participant_id',
        'created_by',
        'registered_by',
        'granted_by',
        'authorized_user_id',
        'owner_id',
    ];

    /**
     * @return array<string, int> rows deleted per table
     */
    public static function pruneLegacyAccounts(PDO $pdo): array
    {
        $userIds = self::LEGACY_USER_IDS;
        $existing = self::existingUserIds($pdo, $userIds);
        $deleted = [];

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            if ($existing !== []) {
                foreach (self::referencingColumns($pdo) as [$table, $column]) {
                    $placeholders = implode(', ', array_fill(0, count($existing), '?'));
                    $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ({$placeholders})");
                    $stmt->execute($existing);
                    if ($stmt->rowCount() > 0) {
                        $deleted[$table] = ($deleted[$table] ?? 0) + $stmt->rowCount();
                    }
                }

                $placeholders = implode(', ', array_fill(0, count($existing), '?'));
                $stmt = $pdo->prepare("DELETE FROM `users` WHERE `id` IN ({$placeholders})");
                $stmt->execute($existing);
                if ($stmt->rowCount() > 0) {
                    $deleted['users'] = $stmt->rowCount();
                }
            }

            if (self::hasTable($pdo, 'organizer_event_credit_batches')) {
                $ids = self::LEGACY_CREDIT_BATCH_IDS;
                $placeholders = implode(', ', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare(
                    "DELETE FROM `organizer_event_credit_batches` WHERE `id` IN ({$placeholders})"
                );
                $stmt->execute($ids);
                if ($stmt->rowCount() > 0) {
                    $deleted['organizer_event_credit_batches'] =
                        ($deleted['organizer_event_credit_batches'] ?? 0) + $stmt->rowCount();
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        return $deleted;
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private static function existingUserIds(PDO $pdo, array $ids): array
    {
        if (!self::hasTable($pdo, 'users')) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id IN ({$placeholders})");
        $stmt->execute($ids);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function referencingColumns(PDO $pdo): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::USER_REFERENCE_COLUMNS), '?'));
        $stmt = $pdo->prepare(
            "SELECT c.TABLE_NAME, c.COLUMN_NAME
             FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES t
               ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = DATABASE()
               AND t.TABLE_TYPE = 'BASE TABLE'
               AND c.TABLE_NAME <> 'users'
               AND c.COLUMN_NAME IN ({$placeholders})
             ORDER BY c.TABLE_NAME, c.COLUMN_NAME"
        );
        $stmt->execute(self::USER_REFERENCE_COLUMNS);

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            $columns[] = [(string) $row[0], (string) $row[1]];
        }

        return $columns;
    }

    private static function hasTable(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }
}

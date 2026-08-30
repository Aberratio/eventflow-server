<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;
use RuntimeException;

/**
 * Tracks exactly which rows the demo seeders inserted, so they can be removed again
 * without dropping the database.
 *
 * The seeders are plain SQL files with hand-written ids that follow no single naming
 * convention ("u-participant", "test-organizer", "e-test-...", "past-p12"), so a
 * prefix-based cleanup would either miss rows or risk matching real ones. Instead the
 * seed runner snapshots every table's primary keys before and after applying the
 * seeders, and records the difference here. Purging then deletes precisely those rows.
 *
 * This is what makes staging workable: refreshing demo data there is a purge + seed,
 * never a drop of the whole schema.
 */
final class SeedManifest
{
    public const TABLE = 'seed_records';

    public static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                table_name VARCHAR(64) NOT NULL,
                record_key VARCHAR(512) NOT NULL,
                seeded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_seed_record (table_name, record_key(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Primary key columns for every base table, excluding the manifest itself.
     *
     * @return array<string, list<string>>
     */
    public static function primaryKeys(PDO $pdo): array
    {
        $sql = 'SELECT t.TABLE_NAME, k.COLUMN_NAME, k.ORDINAL_POSITION
                FROM information_schema.TABLES t
                JOIN information_schema.KEY_COLUMN_USAGE k
                  ON k.TABLE_SCHEMA = t.TABLE_SCHEMA
                 AND k.TABLE_NAME = t.TABLE_NAME
                 AND k.CONSTRAINT_NAME = "PRIMARY"
                WHERE t.TABLE_SCHEMA = DATABASE()
                  AND t.TABLE_TYPE = "BASE TABLE"
                ORDER BY t.TABLE_NAME, k.ORDINAL_POSITION';

        $keys = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) $row['TABLE_NAME'];
            if ($table === self::TABLE) {
                continue;
            }
            $keys[$table][] = (string) $row['COLUMN_NAME'];
        }

        return $keys;
    }

    /**
     * Set of primary keys currently present, per table.
     *
     * @return array<string, array<string, true>>
     */
    public static function snapshot(PDO $pdo): array
    {
        $snapshot = [];

        foreach (self::primaryKeys($pdo) as $table => $columns) {
            $select = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns));
            $rows = $pdo->query("SELECT {$select} FROM `{$table}`")->fetchAll(PDO::FETCH_NUM);

            $set = [];
            foreach ($rows as $row) {
                $set[self::keyOf($row)] = true;
            }
            $snapshot[$table] = $set;
        }

        return $snapshot;
    }

    /**
     * Records every key present in $after but not in $before.
     *
     * @param array<string, array<string, true>> $before
     * @param array<string, array<string, true>> $after
     */
    public static function recordDiff(PDO $pdo, array $before, array $after): int
    {
        self::ensureTable($pdo);

        $insert = $pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (table_name, record_key) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE seeded_at = CURRENT_TIMESTAMP'
        );

        $recorded = 0;
        $pdo->beginTransaction();
        try {
            foreach ($after as $table => $keys) {
                $known = $before[$table] ?? [];
                foreach (array_keys($keys) as $key) {
                    if (isset($known[$key])) {
                        continue;
                    }
                    $insert->execute([$table, $key]);
                    $recorded++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $recorded;
    }

    /**
     * Deletes every recorded row and empties the manifest.
     *
     * @return array<string, int> rows deleted per table
     */
    public static function purge(PDO $pdo): array
    {
        if (!self::tableExists($pdo)) {
            return [];
        }

        $pkColumns = self::primaryKeys($pdo);
        $rows = $pdo->query('SELECT table_name, record_key FROM ' . self::TABLE)->fetchAll(PDO::FETCH_ASSOC);

        $byTable = [];
        foreach ($rows as $row) {
            $byTable[(string) $row['table_name']][] = (string) $row['record_key'];
        }

        $deleted = [];
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($byTable as $table => $keys) {
                if (!isset($pkColumns[$table])) {
                    // Table was dropped by a later migration; nothing to clean up.
                    continue;
                }

                $columns = $pkColumns[$table];
                $where = implode(' AND ', array_map(static fn (string $c): string => '`' . $c . '` <=> ?', $columns));
                $delete = $pdo->prepare("DELETE FROM `{$table}` WHERE {$where}");

                $count = 0;
                foreach ($keys as $key) {
                    $values = self::valuesOf($key);
                    if (count($values) !== count($columns)) {
                        throw new RuntimeException("Manifest key for `{$table}` does not match its primary key.");
                    }
                    $delete->execute($values);
                    $count += $delete->rowCount();
                }

                if ($count > 0) {
                    $deleted[$table] = $count;
                }
            }

            $pdo->exec('DELETE FROM ' . self::TABLE);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        return $deleted;
    }

    public static function count(PDO $pdo): int
    {
        if (!self::tableExists($pdo)) {
            return 0;
        }

        return (int) $pdo->query('SELECT COUNT(*) FROM ' . self::TABLE)->fetchColumn();
    }

    private static function tableExists(PDO $pdo): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([self::TABLE]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param list<mixed> $values */
    private static function keyOf(array $values): string
    {
        return (string) json_encode(array_map(
            static fn (mixed $v): ?string => $v === null ? null : (string) $v,
            $values
        ), JSON_UNESCAPED_UNICODE);
    }

    /** @return list<mixed> */
    private static function valuesOf(string $key): array
    {
        $decoded = json_decode($key, true);

        return is_array($decoded) ? array_values($decoded) : [$key];
    }
}

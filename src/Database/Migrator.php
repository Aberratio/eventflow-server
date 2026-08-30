<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;

/**
 * Applies the schema migrations from database/migrations.
 *
 * Migrations are re-runnable: every statement uses IF NOT EXISTS / ON DUPLICATE KEY,
 * so applying the whole directory twice is a no-op. There is deliberately no ledger
 * table — introducing one would need a backfill on the already-migrated production
 * database, and the idempotent style makes it unnecessary.
 */
final class Migrator
{
    public static function migrationsDir(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }

    /**
     * @return list<string> applied file names, in order
     */
    public static function applyAll(PDO $pdo): array
    {
        $files = glob(self::migrationsDir() . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            self::applyFile($pdo, $file);
        }

        return array_map('basename', $files);
    }

    public static function applyFile(PDO $pdo, string $file): void
    {
        $sql = (string) file_get_contents($file);
        foreach (self::statements($sql) as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * Splits a SQL script into executable statements.
     *
     * Naive splitting on ";" corrupts the seed data, which carries JSON columns full of
     * semicolons inside string literals (HTML entities, regulations markup). This walks
     * the script and only splits on semicolons that sit outside quotes and comments.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                $current .= $char;
                if ($char === chr(92) && $i + 1 < $length) {
                    $current .= $sql[++$i];
                    continue;
                }
                if ($char === $quote) {
                    // Doubled quote inside a literal ('' or "") is an escaped quote, not a close.
                    if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                        $current .= $sql[++$i];
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            // Line comments: "--" followed by whitespace or end of line, and "# ...".
            $isDashComment = $char === '-'
                && substr($sql, $i, 2) === '--'
                && ($i + 2 >= $length || ctype_space($sql[$i + 2]));

            if ($isDashComment || $char === '#') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline;
                $current .= "\n";
                continue;
            }

            // Block comments: /* ... */
            if ($char === '/' && substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }

            if ($char === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * Drops every base table in the current database. Callers are responsible for
     * making sure this is not pointed at anything that matters.
     */
    public static function dropAllTables(PDO $pdo): int
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as $table) {
            $name = str_replace('`', '``', (string) $table[0]);
            $pdo->exec("DROP TABLE IF EXISTS `{$name}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        return count($tables);
    }
}

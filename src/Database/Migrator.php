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

        // E1.14 verification fix: migration 041's legacy customFields JSON
        // backfill moved out of SQL (see that file's own comment — it needed
        // JSON_TABLE/JSON_ARRAYAGG, unavailable on this environment's MariaDB).
        // Runs after every .sql file so `form_fields` always already exists.
        CustomFormFieldsBackfill::run($pdo);

        return array_map('basename', $files);
    }

    /**
     * PHP steps a migration script may call on itself with a `-- @php: Name::method`
     * line, keyed by the exact spelling allowed in that marker.
     *
     * An allowlist rather than a resolved class name: a migration file is data, and
     * letting one name an arbitrary callable would turn the schema directory into an
     * execution surface. A typo then fails loudly at the migration that carries it,
     * instead of silently skipping a step.
     *
     * @var array<string, array{class-string, string}>
     */
    private const PHP_STEPS = [
        'CompetitorProfileBackfill::run' => [CompetitorProfileBackfill::class, 'run'],
        'QrCodeBackfill::registrations' => [QrCodeBackfill::class, 'registrations'],
        'QrCodeBackfill::competitors' => [QrCodeBackfill::class, 'competitors'],
    ];

    /** A whole line that is nothing but the marker; anything else stays a plain SQL comment. */
    private const PHP_STEP_PATTERN = '/^[ \t]*--[ \t]*@php:[ \t]*([A-Za-z0-9_]+::[A-Za-z0-9_]+)[ \t\r]*$/m';

    /**
     * Applies one migration script, running any `-- @php:` step it declares at the
     * point in the file where the marker sits.
     *
     * The marker exists because a few backfills cannot be written in SQL that this
     * environment's MariaDB supports (see QrCodeBackfill), and yet have to run
     * *between* two DDL statements of the same migration — after the column is added,
     * before it is made NOT NULL. Keeping that ordering inside the .sql file is the
     * point: it sits next to the statements it is ordered against, and a single-file
     * apply (which several tests use) behaves exactly like a full run.
     *
     * A marker must sit between complete statements, on a line of its own. Placed
     * mid-statement it splits that statement in two and the migration fails — loudly,
     * which is the intended outcome for a malformed marker.
     */
    public static function applyFile(PDO $pdo, string $file): void
    {
        $sql = (string) file_get_contents($file);

        // Odd offsets are the captured step names, even offsets the SQL around them.
        $chunks = preg_split(self::PHP_STEP_PATTERN, $sql, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$sql];

        foreach ($chunks as $offset => $chunk) {
            if ($offset % 2 === 1) {
                self::applyPhpStep($pdo, $file, $chunk);
                continue;
            }

            self::applyStatements($pdo, $file, $chunk);
        }
    }

    private static function applyPhpStep(PDO $pdo, string $file, string $step): void
    {
        $callable = self::PHP_STEPS[$step] ?? null;
        if ($callable === null) {
            throw new \RuntimeException(sprintf(
                'Migration %s declares unknown PHP step "%s" (known: %s)',
                basename($file),
                $step,
                implode(', ', array_keys(self::PHP_STEPS))
            ));
        }

        try {
            $callable($pdo);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                'Migration failed in %s: PHP step %s: %s',
                basename($file),
                $step,
                $e->getMessage()
            ), 0, $e);
        }
    }

    private static function applyStatements(PDO $pdo, string $file, string $sql): void
    {
        foreach (self::statements($sql) as $statement) {
            try {
                $pdo->exec($statement);
            } catch (\PDOException $e) {
                // Without this, a broken statement aborts the whole migration
                // run silently mid-file (no ledger, no per-file try/catch
                // upstream) and every caller sees only the raw driver message,
                // with no clue which file/statement caused it — exactly what
                // let migration 041's JSON_TABLE statement block 042 for a
                // long time before anyone noticed (E1.14 verification fix).
                throw new \RuntimeException(sprintf(
                    'Migration failed in %s: %s (statement: %s)',
                    basename($file),
                    $e->getMessage(),
                    self::truncate($statement)
                ), 0, $e);
            }
        }
    }

    private static function truncate(string $statement, int $maxLength = 300): string
    {
        $statement = preg_replace('/\s+/', ' ', trim($statement)) ?? $statement;
        return strlen($statement) > $maxLength ? substr($statement, 0, $maxLength) . '…' : $statement;
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

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;

/**
 * Applies the schema migrations from database/migrations.
 *
 * A `schema_migrations` ledger (filename, sha256 checksum, applied_at) tracks which
 * files have already run. `applyAll()` bootstraps the ledger on any database — on a
 * database that has never seen it (every dev DB today, staging until its first deploy
 * with this class), every file is missing from the ledger, so the first run applies
 * everything and records it, exactly like the old no-ledger behaviour. A later run only
 * (re)applies a file that is missing from the ledger or whose checksum changed, so
 * backfills stop re-executing against live data on every deploy.
 *
 * Every migration file must still be idempotent regardless of the ledger: MySQL/MariaDB
 * DDL is not transactional, so a crash mid-file leaves it unrecorded and it is retried
 * from the top; the ledger can also be bootstrapped mid-history if a file was already
 * applied by hand or by an older deploy. `MigrationIdempotencyTest` enforces this by
 * forcing a full re-apply (`applyAll($pdo, reapplyAll: true)`) and checking nothing in
 * the schema or the data changes.
 *
 * Idempotent ALTER sub-clauses use a `-- @guard: <kind> <table>.<object>` marker
 * evaluated against `information_schema` by {@see MigrationGuard}, never MariaDB-only
 * `ADD COLUMN IF NOT EXISTS` / `DROP COLUMN IF EXISTS` syntax: production and staging
 * run MySQL, which rejects that syntax outright. A guard marker applies to exactly the
 * next SQL statement. `CREATE TABLE IF NOT EXISTS` / `DROP TABLE IF EXISTS` are portable
 * on both engines and stay as plain SQL, no guard needed.
 */
final class Migrator
{
    public const LEDGER_TABLE = 'schema_migrations';

    public static function migrationsDir(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }

    /**
     * @return list<string> files applied by THIS call, in order (empty = everything was
     *     already up to date)
     */
    public static function applyAll(PDO $pdo, bool $reapplyAll = false): array
    {
        self::ensureLedger($pdo);
        $recorded = self::recordedChecksums($pdo);

        $files = glob(self::migrationsDir() . '/*.sql') ?: [];
        sort($files);

        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            $checksum = self::checksum($file);

            if (!$reapplyAll && ($recorded[$name] ?? null) === $checksum) {
                continue;
            }

            self::applyFile($pdo, $file);
            self::record($pdo, $name, $checksum);
            $applied[] = $name;
        }

        // E1.14 verification fix: migration 041's legacy customFields JSON
        // backfill moved out of SQL (see that file's own comment — it needed
        // JSON_TABLE/JSON_ARRAYAGG, unavailable on this environment's MariaDB).
        // Runs after every applyAll() call, ledger or not, so `form_fields`
        // always already exists and any newly added field is backfilled.
        CustomFormFieldsBackfill::run($pdo);

        // PP.13: CompetitorProfileBackfill (invoked from inside 027 via its
        // `-- @php:` marker) is an ongoing catch-up, not a one-time schema
        // backfill — its own docblock explains it exists precisely because
        // "every seeded Konto is waiting for the next migrate run to get its
        // profile" (bin/seed.php migrates before seeding accounts). Before the
        // ledger, every applyAll() re-ran 027 in full and caught up every
        // profile-less user; with the ledger, 027 is normally skipped once its
        // checksum is recorded, which would silently stop backfilling new
        // users. Call it here too so applyAll() keeps that guarantee
        // regardless of whether 027 itself needed to (re-)run. It is
        // idempotent (per-row NOT EXISTS guard), so calling it twice in the
        // same run (027 ran AND this call) is a safe no-op the second time.
        CompetitorProfileBackfill::run($pdo);

        // PP.13: same reasoning as CompetitorProfileBackfill above — 054's
        // condition-tree backfill is an ongoing catch-up for challenges
        // inserted directly by seeders/fixtures after migrations (and the
        // ledger) have already run. See ChallengeConditionTreeBackfill's docblock.
        ChallengeConditionTreeBackfill::run($pdo);

        // PP.13: same reasoning again — 041's system form-field catalogue for
        // events inserted directly by seeders/fixtures. See FormFieldsCatalogBackfill.
        FormFieldsCatalogBackfill::run($pdo);

        return $applied;
    }

    private static function ensureLedger(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::LEDGER_TABLE . ' ('
            . 'filename VARCHAR(191) NOT NULL PRIMARY KEY, '
            . 'checksum CHAR(64) NOT NULL, '
            . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array<string, string> filename => checksum */
    private static function recordedChecksums(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT filename, checksum FROM ' . self::LEDGER_TABLE);

        /** @var array<string, string> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        return $rows;
    }

    /** sha256 of the file's contents with "\r\n" normalised to "\n", so line endings never change the checksum. */
    private static function checksum(string $file): string
    {
        $contents = (string) file_get_contents($file);

        return hash('sha256', str_replace("\r\n", "\n", $contents));
    }

    private static function record(PDO $pdo, string $filename, string $checksum): void
    {
        // REPLACE INTO, not ON DUPLICATE KEY UPDATE: the VALUES() alias form is deprecated
        // on MySQL 8.4, and MariaDB does not support the "AS new" alias replacement either.
        $stmt = $pdo->prepare('REPLACE INTO ' . self::LEDGER_TABLE . ' (filename, checksum) VALUES (?, ?)');
        $stmt->execute([$filename, $checksum]);
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
        'ChallengeConditionTreeBackfill::run' => [ChallengeConditionTreeBackfill::class, 'run'],
        'FormFieldsCatalogBackfill::run' => [FormFieldsCatalogBackfill::class, 'run'],
    ];

    /** A whole line that is nothing but the marker; anything else stays a plain SQL comment. */
    private const PHP_STEP_PATTERN = '/^[ \t]*--[ \t]*@php:[ \t]*([A-Za-z0-9_]+::[A-Za-z0-9_]+)[ \t\r]*$/m';

    /**
     * A whole line that is nothing but a guard marker: `-- @guard: <kind> <table>.<object>`.
     * Applies to exactly the next SQL statement in the file.
     */
    private const GUARD_PATTERN
        = '/^[ \t]*--[ \t]*@guard:[ \t]*([a-z-]+)[ \t]+([A-Za-z0-9_]+)\.([A-Za-z0-9_]+)[ \t\r]*$/m';

    /** Anything starting like a guard but not matching GUARD_PATTERN is an error, never a silent comment. */
    private const GUARD_PREFIX_PATTERN = '/^[ \t]*--[ \t]*@guard\b/m';

    /**
     * Parses a migration file's contents into an ordered list of steps: PHP steps
     * (`-- @php:`) and SQL statements, each optionally carrying the guard that must
     * hold (evaluated by {@see MigrationGuard}) for it to run.
     *
     * @return list<array{type: 'php', step: string}
     *   |array{type: 'sql', statement: string, guard: array{kind: string, table: string, object: string}|null}>
     * @throws \InvalidArgumentException on a malformed guard line, an unknown guard kind,
     *     or a guard marker not immediately followed by a SQL statement
     */
    public static function parse(string $sql): array
    {
        $prefixCount = preg_match_all(self::GUARD_PREFIX_PATTERN, $sql);
        $guardCount = preg_match_all(self::GUARD_PATTERN, $sql);
        if ($prefixCount !== $guardCount) {
            throw new \InvalidArgumentException(
                'Malformed "-- @guard:" marker (expected "-- @guard: <kind> <table>.<object>")'
            );
        }

        $steps = [];
        $chunks = preg_split(self::PHP_STEP_PATTERN, $sql, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$sql];

        foreach ($chunks as $offset => $chunk) {
            if ($offset % 2 === 1) {
                $steps[] = ['type' => 'php', 'step' => $chunk];
                continue;
            }

            foreach (self::parseGuardedSql($chunk) as $step) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    /**
     * @return list<array{type: 'sql', statement: string, guard: array{kind: string, table: string, object: string}|null}>
     */
    private static function parseGuardedSql(string $sql): array
    {
        // PREG_SPLIT_DELIM_CAPTURE with 3 capture groups yields groups of 4:
        // [sql, kind, table, object, sql, kind, table, object, sql, ...].
        $parts = preg_split(self::GUARD_PATTERN, $sql, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$sql];

        $steps = [];
        $pendingGuard = null;
        $pendingKind = null;
        $pendingTable = null;

        foreach ($parts as $index => $part) {
            switch ($index % 4) {
                case 1:
                    $pendingKind = $part;
                    continue 2;
                case 2:
                    $pendingTable = $part;
                    continue 2;
                case 3:
                    if (!in_array($pendingKind, MigrationGuard::KINDS, true)) {
                        throw new \InvalidArgumentException(sprintf(
                            'Unknown guard kind "%s" (known: %s)',
                            (string) $pendingKind,
                            implode(', ', MigrationGuard::KINDS)
                        ));
                    }
                    $pendingGuard = ['kind' => $pendingKind, 'table' => $pendingTable, 'object' => $part];
                    continue 2;
            }

            $statements = self::statements($part);

            if ($pendingGuard !== null) {
                if ($statements === []) {
                    throw new \InvalidArgumentException(sprintf(
                        'Guard "-- @guard: %s %s.%s" is not followed by a SQL statement',
                        $pendingGuard['kind'],
                        $pendingGuard['table'],
                        $pendingGuard['object']
                    ));
                }

                $first = array_shift($statements);
                $steps[] = ['type' => 'sql', 'statement' => $first, 'guard' => $pendingGuard];
                $pendingGuard = null;
            }

            foreach ($statements as $statement) {
                $steps[] = ['type' => 'sql', 'statement' => $statement, 'guard' => null];
            }
        }

        return $steps;
    }

    /**
     * Applies one migration script, running any `-- @php:` step and evaluating any
     * `-- @guard:` marker at the point in the file where it sits.
     *
     * The `-- @php:` marker exists because a few backfills cannot be written in SQL that
     * this environment's MariaDB supports (see QrCodeBackfill), and yet have to run
     * *between* two DDL statements of the same migration — after the column is added,
     * before it is made NOT NULL. Keeping that ordering inside the .sql file is the
     * point: it sits next to the statements it is ordered against, and a single-file
     * apply (which several tests use) behaves exactly like a full run.
     */
    public static function applyFile(PDO $pdo, string $file): void
    {
        $sql = (string) file_get_contents($file);

        try {
            $steps = self::parse($sql);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException(
                sprintf('Migration failed in %s: %s', basename($file), $e->getMessage()),
                0,
                $e
            );
        }

        foreach ($steps as $step) {
            if ($step['type'] === 'php') {
                self::applyPhpStep($pdo, $file, $step['step']);
                continue;
            }

            $guard = $step['guard'];
            if ($guard !== null && !MigrationGuard::holds($pdo, $guard['kind'], $guard['table'], $guard['object'])) {
                continue;
            }

            try {
                $pdo->exec($step['statement']);
            } catch (\PDOException $e) {
                // Without this, a broken statement aborts the whole migration
                // run silently mid-file (no per-file try/catch upstream) and
                // every caller sees only the raw driver message, with no clue
                // which file/statement caused it — exactly what let migration
                // 041's JSON_TABLE statement block 042 for a long time before
                // anyone noticed (E1.14 verification fix).
                throw new \RuntimeException(sprintf(
                    'Migration failed in %s: %s (statement: %s)',
                    basename($file),
                    $e->getMessage(),
                    self::truncate($step['statement'])
                ), 0, $e);
            }
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
     * Drops every base table in the current database, including the ledger, so a fresh
     * `seed --fresh` re-applies (and re-records) every migration.
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

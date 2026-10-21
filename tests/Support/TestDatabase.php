<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Support;

use ZmierzymyCzas\Support\Env;

/**
 * Points the test suite at a separate local database, selected through
 * `ZC_TEST_DB_*` process environment variables instead of `.env` — never edits
 * `.env`, never touches the developer's dev database unless they explicitly export
 * these on top of it.
 *
 * `Env::get()` prefers a value already loaded from `.env` over the process
 * environment (`Support\Env`), so plain `DB_DATABASE=… phpunit` cannot select a test
 * database by itself: `.env`'s `DB_DATABASE` wins the race the moment anything has
 * called `Env::load()`. `Env::set()` overrides survive every later `load()` call, so
 * applying the overrides here — called from tests/bootstrap.php, before the app
 * boots and calls `Env::load()` again — is the seam that actually works.
 */
final class TestDatabase
{
    private const OVERRIDABLE = ['HOST' => 'DB_HOST', 'PORT' => 'DB_PORT', 'DATABASE' => 'DB_DATABASE',
        'USERNAME' => 'DB_USERNAME', 'PASSWORD' => 'DB_PASSWORD'];

    /**
     * Applies every set `ZC_TEST_DB_*` override. Returns whether any were set
     * (ZC_TEST_DB_DATABASE in particular — CI sets none of these and keeps using its
     * own `.env`-provisioned `zmierzymy_czas_test`, unaffected by this class).
     *
     * @throws \RuntimeException if ZC_TEST_DB_DATABASE is set without a "_test" suffix
     */
    public static function applyOverrides(): bool
    {
        $applied = false;
        $databaseOverridden = false;

        foreach (self::OVERRIDABLE as $suffix => $envKey) {
            $value = getenv('ZC_TEST_DB_' . $suffix);
            if ($value === false) {
                continue;
            }

            Env::set($envKey, $value);
            $applied = true;
            if ($suffix === 'DATABASE') {
                $databaseOverridden = true;
            }
        }

        if ($databaseOverridden) {
            self::assertIsTestDatabase();
        }

        return $applied;
    }

    /** MySQL 8.4's default sql_mode, which production, staging and CI run with. */
    public const TEST_SQL_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /**
     * Every test connection runs with production's strict sql_mode, so a missing NOT NULL
     * column (1364), NULL into NOT NULL (1048), bad ENUM (1265) or non-numeric number (1366)
     * fails on a developer's non-strict MariaDB exactly as on MySQL 8.4.
     * ZC_TEST_DB_SQL_MODE overrides the mode; ZC_TEST_DB_SQL_MODE=server keeps the server's own.
     */
    public static function applySessionSqlMode(): void
    {
        $override = getenv('ZC_TEST_DB_SQL_MODE');
        if ($override === 'server') {
            return;
        }
        Env::set('DB_SESSION_SQL_MODE', $override !== false && $override !== '' ? $override : self::TEST_SQL_MODE);
    }

    /** @throws \RuntimeException unless Env's DB_DATABASE ends with "_test" */
    public static function assertIsTestDatabase(): void
    {
        $database = (string) Env::get('DB_DATABASE', '');
        if (!str_ends_with($database, '_test')) {
            throw new \RuntimeException(sprintf(
                'Refusing to run against database "%s": it does not end in "_test". '
                . 'Set ZC_TEST_DB_DATABASE to a database name ending in "_test" — '
                . 'never point tests at a development database.',
                $database
            ));
        }
    }
}

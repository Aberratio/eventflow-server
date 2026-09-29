<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Support\Env;

/**
 * PP.54: migration 062 adds teams.invite_token + its unique index, portably
 * (information_schema + PREPARE, no `IF NOT EXISTS` inside ALTER) and idempotently.
 */
final class TeamInviteTokenMigrationTest extends TestCase
{
    private const FILE = '062_pp54_team_invite_token.sql';

    public function testMigrationIsIdempotent(): void
    {
        Env::load(__DIR__ . '/../.env');
        $pdo = Connection::fromEnv();
        $file = Migrator::migrationsDir() . '/' . self::FILE;

        Migrator::applyFile($pdo, $file);
        Migrator::applyFile($pdo, $file);

        $column = $pdo->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'teams' AND column_name = 'invite_token'"
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $column);
        self::assertSame('varchar(64)', strtolower((string) $column[0]['COLUMN_TYPE']));
        self::assertSame('YES', $column[0]['IS_NULLABLE']);

        $index = $pdo->query(
            "SELECT NON_UNIQUE, COLUMN_NAME FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'teams' AND index_name = 'uq_teams_invite_token'"
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $index);
        self::assertSame(0, (int) $index[0]['NON_UNIQUE']);
        self::assertSame('invite_token', $index[0]['COLUMN_NAME']);
    }

    public function testMigrationFileHasNoIfNotExistsInAlter(): void
    {
        $path = Migrator::migrationsDir() . '/' . self::FILE;
        self::assertFileExists($path);

        $sql = (string) file_get_contents($path);
        self::assertSame(0, preg_match('/ALTER\s+TABLE[^;]*IF\s+(NOT\s+)?EXISTS/i', $sql));
    }
}

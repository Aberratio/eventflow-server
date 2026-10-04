<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Support\Env;

/**
 * PP.7: migration 064 adds users.token_version and the auth token tables,
 * portably (guarded ALTERs) and idempotently (applied twice here).
 */
final class AuthTokensMigrationTest extends TestCase
{
    private const FILE = '064_pp7_auth_tokens.sql';

    private PDO $pdo;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../.env');
        $this->pdo = Connection::fromEnv();
        $file = Migrator::migrationsDir() . '/' . self::FILE;
        Migrator::applyFile($this->pdo, $file);
        Migrator::applyFile($this->pdo, $file);
    }

    public function testTokenVersionColumnExists(): void
    {
        $column = $this->pdo->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'token_version'"
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $column);
        self::assertStringStartsWith('int', strtolower((string) $column[0]['COLUMN_TYPE']));
        self::assertSame('NO', $column[0]['IS_NULLABLE']);
        self::assertSame('0', (string) $column[0]['COLUMN_DEFAULT']);
    }

    public function testRevokedSessionTokensTableExists(): void
    {
        $columns = $this->pdo->query(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'revoked_session_tokens'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $columns = array_map('strtolower', $columns);
        sort($columns);
        self::assertSame(['created_at', 'expires_at', 'jti', 'user_id'], $columns);
    }

    public function testUserActionTokensTableAndUniqueHashExist(): void
    {
        $columns = $this->pdo->query(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'user_action_tokens'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $columns = array_map('strtolower', $columns);
        sort($columns);
        self::assertSame(
            ['created_at', 'expires_at', 'id', 'new_email', 'purpose', 'token_hash', 'used_at', 'user_id'],
            $columns
        );

        $index = $this->pdo->query(
            "SELECT NON_UNIQUE, COLUMN_NAME FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'user_action_tokens' AND index_name = 'uq_uat_token_hash'"
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $index);
        self::assertSame(0, (int) $index[0]['NON_UNIQUE']);
        self::assertSame('token_hash', $index[0]['COLUMN_NAME']);
    }

    public function testMigrationFileHasNoIfNotExistsInAlter(): void
    {
        $sql = (string) file_get_contents(Migrator::migrationsDir() . '/' . self::FILE);
        self::assertSame(0, preg_match('/ALTER\s+TABLE[^;]*IF\s+(NOT\s+)?EXISTS/i', $sql));
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\FaultInjectingPdo;
use ZmierzymyCzas\Tests\Support\FaultInjectingRefundPdo;
use ZmierzymyCzas\Tests\Support\SavepointPdo;
use ZmierzymyCzas\Tests\Support\TestDatabase;

/**
 * PP.76: guards the wiring that makes every test DB connection run with MySQL 8.4's strict
 * sql_mode. Without it the 422 contract tests would not discriminate on non-strict local
 * MariaDB (the missing-column inserts would silently succeed instead of failing like production).
 * A new PDO test double must call Connection::applySessionSqlMode($this) after its constructor
 * and be added here.
 */
final class StrictSessionTest extends TestCase
{
    private function optedOut(): bool
    {
        return getenv('ZC_TEST_DB_SQL_MODE') === 'server';
    }

    private function sessionMode(PDO $pdo): string
    {
        return (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    }

    private function assertStrictOrServerMode(PDO $pdo): void
    {
        $mode = $this->sessionMode($pdo);
        if ($this->optedOut()) {
            self::assertSame((string) $pdo->query('SELECT @@GLOBAL.sql_mode')->fetchColumn(), $mode);

            return;
        }
        self::assertStringContainsString('STRICT_TRANS_TABLES', $mode);
        self::assertStringContainsString('NO_ZERO_DATE', $mode);
    }

    private function connect(): PDO
    {
        Env::load(__DIR__ . '/../../.env');
        try {
            $pdo = Connection::fromEnv();
            Migrator::applyAll($pdo);

            return $pdo;
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    public function testConnectionFromEnvRunsMysql84StrictMode(): void
    {
        $this->assertStrictOrServerMode($this->connect());
    }

    public function testPdoTestDoublesRunStrictMode(): void
    {
        $this->connect();
        $this->assertStrictOrServerMode(new SavepointPdo());
        $this->assertStrictOrServerMode(new FaultInjectingPdo('never-matches', new \PDOException('x')));
        $this->assertStrictOrServerMode(new FaultInjectingRefundPdo('none'));
    }

    public function testStrictSessionRejectsMissingNotNullColumn(): void
    {
        if ($this->optedOut()) {
            self::markTestSkipped('ZC_TEST_DB_SQL_MODE=server: the server mode may be non-strict.');
        }
        $pdo = $this->connect();
        $pdo->beginTransaction();
        try {
            $this->expectException(\PDOException::class);
            $this->expectExceptionMessageMatches('/1364/');
            $pdo->prepare('INSERT INTO series (id, name, slug) VALUES (:id, :name, :slug)')
                ->execute(['id' => 'strict-' . bin2hex(random_bytes(4)), 'name' => 'x', 'slug' => 'strict-' . bin2hex(random_bytes(4))]);
        } finally {
            $pdo->rollBack();
        }
    }

    public function testWithoutSessionModeKeyTheServerDefaultApplies(): void
    {
        if (getenv('DB_SESSION_SQL_MODE') !== false) {
            self::markTestSkipped('DB_SESSION_SQL_MODE is set in the process env; its absence cannot be tested.');
        }
        $this->connect();
        Env::restore('DB_SESSION_SQL_MODE');
        try {
            $pdo = Connection::fromEnv();
            self::assertSame((string) $pdo->query('SELECT @@GLOBAL.sql_mode')->fetchColumn(), $this->sessionMode($pdo));
        } finally {
            TestDatabase::applySessionSqlMode();
        }
    }
}

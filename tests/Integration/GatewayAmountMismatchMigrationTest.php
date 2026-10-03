<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Support\Env;

/**
 * PP.6: migration 063 adds the `amount_mismatch` status to
 * registration_gateway_transactions.status — MODIFY only, portable and idempotent.
 */
final class GatewayAmountMismatchMigrationTest extends TestCase
{
    private const FILE = '063_pp6_gateway_amount_mismatch_status.sql';

    public function testStatusEnumContainsAmountMismatchAndMigrationIsIdempotent(): void
    {
        Env::load(__DIR__ . '/../.env');
        try {
            $pdo = Connection::fromEnv();
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
        $file = Migrator::migrationsDir() . '/' . self::FILE;

        Migrator::applyFile($pdo, $file);
        Migrator::applyFile($pdo, $file);

        $type = (string) $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'registration_gateway_transactions' AND column_name = 'status'"
        )->fetch(PDO::FETCH_COLUMN);
        self::assertStringContainsString("'amount_mismatch'", $type);
        self::assertStringContainsString("'created'", $type);
        self::assertStringContainsString("'paid'", $type);
        self::assertStringContainsString("'failed'", $type);
    }

    public function testMigrationFileHasNoIfNotExists(): void
    {
        $path = Migrator::migrationsDir() . '/' . self::FILE;
        self::assertFileExists($path);

        self::assertSame(0, preg_match('/\bIF\s+(NOT\s+)?EXISTS\b/i', (string) file_get_contents($path)));
    }
}

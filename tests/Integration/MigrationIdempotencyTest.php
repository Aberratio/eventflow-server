<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\SchemaFingerprint;

/**
 * Forces every migration file to re-run (bypassing the ledger) against an already
 * migrated/seeded database and asserts nothing changes: no error, identical schema,
 * identical per-table row counts. This is what keeps the ledger safe — a file that
 * only behaves once (missing this check) would silently corrupt data the very first
 * time it needs to be replayed (bootstrap on an existing DB, a crash mid-file, or an
 * edited migration).
 */
final class MigrationIdempotencyTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for migration idempotency tests: ' . $e->getMessage());
        }
    }

    public function testReapplyingEveryMigrationChangesNeitherSchemaNorRowCounts(): void
    {
        $before = SchemaFingerprint::of($this->pdo);
        $rowCountsBefore = $this->rowCountsByTable();

        // Two full re-applies, not one: catches a file that is idempotent on its
        // second run but not its third (e.g. it toggles some derived value).
        Migrator::applyAll($this->pdo, reapplyAll: true);
        Migrator::applyAll($this->pdo, reapplyAll: true);

        $after = SchemaFingerprint::of($this->pdo);
        $rowCountsAfter = $this->rowCountsByTable();

        self::assertSame($before, $after, 'Schema fingerprint changed after forcing a full migration re-apply.');
        self::assertSame($rowCountsBefore, $rowCountsAfter, 'Per-table row counts changed after forcing a full migration re-apply.');
    }

    /** @return array<string, int> */
    private function rowCountsByTable(): array
    {
        $tables = $this->pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME NOT IN ('" . Migrator::LEDGER_TABLE . "', 'seed_records')"
        )->fetchAll(PDO::FETCH_COLUMN);

        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $this->pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
        }

        return $counts;
    }
}

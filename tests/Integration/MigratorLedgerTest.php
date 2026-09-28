<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Support\Env;

final class MigratorLedgerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for migration ledger tests: ' . $e->getMessage());
        }
    }

    public function testSecondApplyAllAppliesNothing(): void
    {
        $applied = Migrator::applyAll($this->pdo);
        self::assertSame([], $applied);

        $total = count(glob(Migrator::migrationsDir() . '/*.sql') ?: []);
        $rowCount = (int) $this->pdo->query('SELECT COUNT(*) FROM ' . Migrator::LEDGER_TABLE)->fetchColumn();
        self::assertSame($total, $rowCount);

        $files = glob(Migrator::migrationsDir() . '/*.sql') ?: [];
        foreach ($files as $file) {
            $stmt = $this->pdo->prepare('SELECT checksum FROM ' . Migrator::LEDGER_TABLE . ' WHERE filename = ?');
            $stmt->execute([basename($file)]);
            $checksum = $stmt->fetchColumn();
            self::assertSame(
                hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($file))),
                $checksum,
                basename($file) . ' checksum mismatch'
            );
        }
    }

    public function testMissingLedgerRowIsReapplied(): void
    {
        $file = '061_td8_relay_team_pricing_mode.sql';
        $this->pdo->prepare('DELETE FROM ' . Migrator::LEDGER_TABLE . ' WHERE filename = ?')->execute([$file]);

        $applied = Migrator::applyAll($this->pdo);

        self::assertSame([$file], $applied);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . Migrator::LEDGER_TABLE . ' WHERE filename = ?');
        $stmt->execute([$file]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testChangedChecksumIsReapplied(): void
    {
        $file = '061_td8_relay_team_pricing_mode.sql';
        $realChecksum = hash(
            'sha256',
            str_replace("\r\n", "\n", (string) file_get_contents(Migrator::migrationsDir() . '/' . $file))
        );

        $this->pdo->prepare('UPDATE ' . Migrator::LEDGER_TABLE . ' SET checksum = ? WHERE filename = ?')
            ->execute([str_repeat('0', 64), $file]);

        $applied = Migrator::applyAll($this->pdo);
        self::assertSame([$file], $applied);

        $stmt = $this->pdo->prepare('SELECT checksum FROM ' . Migrator::LEDGER_TABLE . ' WHERE filename = ?');
        $stmt->execute([$file]);
        self::assertSame($realChecksum, $stmt->fetchColumn());
    }
}

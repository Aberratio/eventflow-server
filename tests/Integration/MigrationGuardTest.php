<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Support\Env;

/**
 * Guard evaluation (MigrationGuard::holds(), through Migrator::applyFile()) against
 * a real engine — a scratch table dropped in tearDown, not any application table.
 */
final class MigrationGuardTest extends TestCase
{
    private PDO $pdo;
    private string $table;
    /** @var list<string> temp .sql files to clean up */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->table = 'phpunit_guard_' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->pdo->exec("CREATE TABLE {$this->table} (id INT NOT NULL PRIMARY KEY)");
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for migration guard tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$this->table}");
        }
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    private function applySql(string $sql): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pp13_guard_') . '.sql';
        file_put_contents($file, $sql);
        $this->tempFiles[] = $file;
        Migrator::applyFile($this->pdo, $file);
    }

    public function testColumnMissingAppliesWhenAbsent(): void
    {
        $this->applySql(
            "-- @guard: column-missing {$this->table}.extra\n"
            . "ALTER TABLE {$this->table} ADD COLUMN extra VARCHAR(10) NULL;"
        );

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = '{$this->table}' AND COLUMN_NAME = 'extra'"
        )->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testColumnMissingSkipsWhenColumnExists(): void
    {
        $sql = "-- @guard: column-missing {$this->table}.extra\n"
            . "ALTER TABLE {$this->table} ADD COLUMN extra VARCHAR(10) NULL;";
        $this->applySql($sql);
        // Second apply of an equivalent statement must not throw "duplicate column".
        $this->applySql($sql);

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = '{$this->table}' AND COLUMN_NAME = 'extra'"
        )->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testColumnExistsGuardsDrop(): void
    {
        $this->applySql(
            "-- @guard: column-missing {$this->table}.extra\n"
            . "ALTER TABLE {$this->table} ADD COLUMN extra VARCHAR(10) NULL;"
        );
        $drop = "-- @guard: column-exists {$this->table}.extra\n"
            . "ALTER TABLE {$this->table} DROP COLUMN extra;";
        $this->applySql($drop);
        // Applying the drop again must not throw "unknown column".
        $this->applySql($drop);

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = '{$this->table}' AND COLUMN_NAME = 'extra'"
        )->fetchColumn();
        self::assertSame(0, $count);
    }

    public function testIndexGuards(): void
    {
        $addIndex = "-- @guard: index-missing {$this->table}.idx_extra\n"
            . "ALTER TABLE {$this->table} ADD INDEX idx_extra (id);";
        $this->applySql($addIndex);
        $this->applySql($addIndex);

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = '{$this->table}' AND INDEX_NAME = 'idx_extra'"
        )->fetchColumn();
        self::assertGreaterThan(0, $count);

        $dropIndex = "-- @guard: index-exists {$this->table}.idx_extra\n"
            . "ALTER TABLE {$this->table} DROP INDEX idx_extra;";
        $this->applySql($dropIndex);
        $this->applySql($dropIndex);

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = '{$this->table}' AND INDEX_NAME = 'idx_extra'"
        )->fetchColumn();
        self::assertSame(0, $count);
    }

    public function testForeignKeyMissingGuard(): void
    {
        $other = $this->table . '_ref';
        $this->pdo->exec("CREATE TABLE {$other} (id INT NOT NULL PRIMARY KEY)");

        try {
            $this->applySql(
                "-- @guard: column-missing {$this->table}.ref_id\n"
                . "ALTER TABLE {$this->table} ADD COLUMN ref_id INT NULL;"
            );
            $addFk = "-- @guard: foreign-key-missing {$this->table}.fk_guard_test\n"
                . "ALTER TABLE {$this->table} ADD CONSTRAINT fk_guard_test FOREIGN KEY (ref_id) REFERENCES {$other}(id);";
            $this->applySql($addFk);
            $this->applySql($addFk);

            $count = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() "
                . "AND TABLE_NAME = '{$this->table}' AND CONSTRAINT_NAME = 'fk_guard_test' AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
            )->fetchColumn();
            self::assertSame(1, $count);
        } finally {
            try {
                $this->pdo->exec("ALTER TABLE {$this->table} DROP FOREIGN KEY fk_guard_test");
            } catch (\PDOException) {
                // Already gone or never created — fine, this is best-effort cleanup.
            }
            $this->pdo->exec("DROP TABLE IF EXISTS {$other}");
        }
    }
}

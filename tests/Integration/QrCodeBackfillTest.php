<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\MigrationGuard;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Support\Env;

/**
 * Regression cover for the PHP-side QR backfills of migrations 039
 * (`registrations.qr_code`) and 040 (`competitors.identity_code`).
 *
 * Both originally generated their codes with `RANDOM_BYTES(24)` in SQL — a MySQL
 * 8.0 function MariaDB only has from 11.3, so on both environments this project
 * runs on (10.4 in development, 10.6 in production) that statement was error 1305
 * and took the entire migration run down with it. These tests reproduce the case
 * the error was hiding: a table that already holds rows when the column is added,
 * which is exactly production and never a fresh database — on an empty table the
 * backfill has nothing to do and every shape of it "passes".
 *
 * Each test rewinds the column to its pre-migration shape (nullable, no unique
 * index), plants rows, and re-applies the real migration file, so what is under
 * test is the migration as it will actually run, `-- @php:` marker and all.
 */
final class QrCodeBackfillTest extends TestCase
{
    private const ID_PREFIX = 'phpunit-qr-';

    private const REGISTRATIONS = [
        'file' => '039_e1_11_registration_start_code.sql',
        'table' => 'registrations',
        'column' => 'qr_code',
        'index' => 'uniq_registrations_qr_code',
        'pattern' => '/^pqr_[a-f0-9]{48}$/',
    ];

    private const COMPETITORS = [
        'file' => '040_e1_12_competitor_identity_code.sql',
        'table' => 'competitors',
        'column' => 'identity_code',
        'index' => 'uniq_competitors_identity_code',
        'pattern' => '/^zqr_[a-f0-9]{48}$/',
    ];

    private PDO $pdo;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');

        try {
            $this->pdo = Connection::fromEnv();
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }

        // Deliberately outside the skip guard the other integration tests wrap the
        // whole of setUp() in: a migration that refuses to apply is precisely the
        // regression this class exists to catch. Swallowing it into a skip is how
        // `RANDOM_BYTES()` could have gone on passing CI on a MariaDB that has no
        // such function.
        Migrator::applyAll($this->pdo);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        $this->pdo
            ->prepare('DELETE FROM competitors WHERE user_id LIKE :prefix')
            ->execute(['prefix' => self::ID_PREFIX . '%']);
        $this->pdo
            ->prepare('DELETE FROM users WHERE id LIKE :prefix')
            ->execute(['prefix' => self::ID_PREFIX . '%']);

        foreach ([self::REGISTRATIONS, self::COMPETITORS] as $target) {
            $this->pdo
                ->prepare("DELETE FROM {$target['table']} WHERE id LIKE :prefix")
                ->execute(['prefix' => self::ID_PREFIX . '%']);

            // A failed assertion leaves the column rewound; re-applying puts the
            // shared development database back the way the suite found it.
            Migrator::applyFile($this->pdo, Migrator::migrationsDir() . '/' . $target['file']);
        }
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function targets(): array
    {
        return [
            'kod startowy (pqr_)' => [self::REGISTRATIONS],
            'kod tozsamosci (zqr_)' => [self::COMPETITORS],
        ];
    }

    /**
     * The case the SQL version could never reach on MariaDB: rows that existed
     * before the column did each come out with a code in the BZ-compatible format.
     *
     * @dataProvider targets
     * @param array<string, string> $target
     */
    public function testBackfillsRowsThatPredateTheColumn(array $target): void
    {
        $this->rewindColumn($target);
        $ids = [$this->plantRow($target), $this->plantRow($target), $this->plantRow($target)];

        Migrator::applyFile($this->pdo, Migrator::migrationsDir() . '/' . $target['file']);

        $codes = [];
        foreach ($ids as $id) {
            $code = $this->readCode($target, $id);
            self::assertMatchesRegularExpression($target['pattern'], $code);
            $codes[] = $code;
        }

        // Distinct per row — the one thing a single set-based UPDATE with a
        // constant expression would get wrong, and which the unique index added
        // by the very next statement would then reject.
        self::assertCount(3, array_unique($codes));
    }

    /**
     * Migrations carry no ledger and are re-applied on every `composer migrate`,
     * so the backfill must leave codes that already exist alone. Rotating them
     * would silently invalidate every QR code already printed on a start number.
     *
     * @dataProvider targets
     * @param array<string, string> $target
     */
    public function testDoesNotRotateCodesThatAlreadyExist(array $target): void
    {
        $this->rewindColumn($target);
        $id = $this->plantRow($target);

        Migrator::applyFile($this->pdo, Migrator::migrationsDir() . '/' . $target['file']);
        $first = $this->readCode($target, $id);

        Migrator::applyFile($this->pdo, Migrator::migrationsDir() . '/' . $target['file']);
        self::assertSame($first, $this->readCode($target, $id));
    }

    /**
     * The backfill is only half the migration: the point of moving it out of SQL
     * was to let the two statements after it run at all.
     *
     * @dataProvider targets
     * @param array<string, string> $target
     */
    public function testLeavesColumnNotNullAndUniquelyIndexed(array $target): void
    {
        $this->rewindColumn($target);
        $this->plantRow($target);

        Migrator::applyFile($this->pdo, Migrator::migrationsDir() . '/' . $target['file']);

        $column = $this->pdo
            ->query("SHOW COLUMNS FROM {$target['table']} LIKE " . $this->pdo->quote($target['column']))
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame('NO', $column['Null']);

        $index = $this->pdo
            ->query("SHOW INDEX FROM {$target['table']} WHERE Key_name = " . $this->pdo->quote($target['index']))
            ->fetchAll(PDO::FETCH_ASSOC);
        self::assertNotSame([], $index);
        self::assertSame(0, (int) $index[0]['Non_unique']);
    }

    /** A `-- @php:` marker naming something off the allowlist fails the migration, loudly. */
    public function testUnknownPhpStepMarkerFailsWithTheMigrationName(): void
    {
        $file = sys_get_temp_dir() . '/phpunit-unknown-step-' . bin2hex(random_bytes(4)) . '.sql';
        file_put_contents($file, "-- @php: NoSuchBackfill::nope\n");

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/unknown PHP step "NoSuchBackfill::nope"/');
            Migrator::applyFile($this->pdo, $file);
        } finally {
            @unlink($file);
        }
    }

    /**
     * The shape a row takes when a seeder omits the column after it is already
     * NOT NULL: this MariaDB runs without STRICT_TRANS_TABLES, so the value is not
     * rejected, it becomes `''`. A backfill that only looks for NULL leaves such a
     * row codeless forever — and the unique index fits exactly one of them, so the
     * next one is error 1062.
     *
     * @dataProvider targets
     * @param array<string, string> $target
     */
    public function testBackfillsRowsWhoseCodeIsTheEmptyString(array $target): void
    {
        $this->rewindColumn($target);
        $id = $this->plantRow($target);
        $this->pdo
            ->prepare("UPDATE {$target['table']} SET {$target['column']} = '' WHERE id = :id")
            ->execute(['id' => $id]);

        Migrator::applyFile($this->pdo, Migrator::migrationsDir() . '/' . $target['file']);

        self::assertMatchesRegularExpression($target['pattern'], $this->readCode($target, $id));
    }

    /**
     * Migration 027 gives every Konto a profil Zawodnika, and it re-runs on every
     * `composer migrate` — including the runs where `competitors.identity_code`
     * already exists, is NOT NULL and is UNIQUE. Two Konta without profiles is all
     * it takes: two rows, both silently `''`, 1062 on the second, and the whole
     * migration run dies at 027 long before 040 could heal anything.
     *
     * `bin/seed.php` migrates first and seeds accounts afterwards, so "Konta waiting
     * for their profiles" is the normal state of a seeded database, not a corner case.
     */
    public function testMigration027GivesEveryAccountAProfileWithItsOwnIdentityCode(): void
    {
        $first = $this->plantUser('Jan Kowalski');
        $second = $this->plantUser('Anna Zielinska');

        Migrator::applyFile($this->pdo, Migrator::migrationsDir() . '/027_zawodnicy_i_opieka.sql');

        $codes = [];
        foreach ([$first => 'Jan', $second => 'Anna'] as $userId => $firstName) {
            $stmt = $this->pdo->prepare(
                'SELECT first_name, identity_code FROM competitors WHERE user_id = :userId'
            );
            $stmt->execute(['userId' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            self::assertCount(1, $rows, "expected exactly one profile for {$userId}");
            self::assertSame($firstName, $rows[0]['first_name']);
            self::assertMatchesRegularExpression(self::COMPETITORS['pattern'], (string) $rows[0]['identity_code']);
            $codes[] = $rows[0]['identity_code'];
        }

        self::assertCount(2, array_unique($codes));
    }

    /** Re-applying 027 must not hand the same Konto a second profile. */
    public function testMigration027DoesNotDuplicateProfilesOnReapply(): void
    {
        $userId = $this->plantUser('Piotr Wisniewski');
        $file = Migrator::migrationsDir() . '/027_zawodnicy_i_opieka.sql';

        Migrator::applyFile($this->pdo, $file);
        $stmt = $this->pdo->prepare('SELECT identity_code FROM competitors WHERE user_id = :userId');
        $stmt->execute(['userId' => $userId]);
        $first = $stmt->fetchAll(PDO::FETCH_COLUMN);

        Migrator::applyFile($this->pdo, $file);
        $stmt->execute(['userId' => $userId]);

        self::assertSame($first, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** A Konto with no profil Zawodnika — what 027's backfill exists to fix up. */
    private function plantUser(string $name): string
    {
        $id = self::ID_PREFIX . bin2hex(random_bytes(8));

        $this->pdo->prepare(
            'INSERT INTO users (id, name, email, password_hash)
             VALUES (:id, :name, :email, :hash)'
        )->execute([
            'id' => $id,
            'name' => $name,
            'email' => $id . '@example.test',
            'hash' => 'x',
        ]);

        return $id;
    }

    /**
     * Puts the column back the way it looked between the migration's ADD COLUMN
     * and its MODIFY ... NOT NULL, so a row can be planted without a code at all.
     *
     * @param array<string, string> $target
     */
    private function rewindColumn(array $target): void
    {
        // "DROP INDEX IF EXISTS" is MariaDB-only syntax (production/CI run MySQL 8.4,
        // which rejects it) — use the same information_schema guard the migrations
        // themselves use (MigrationGuard), not vendor-only SQL, even in test code.
        if (MigrationGuard::holds($this->pdo, 'index-exists', $target['table'], $target['index'])) {
            $this->pdo->exec("ALTER TABLE {$target['table']} DROP INDEX {$target['index']}");
        }
        $this->pdo->exec("ALTER TABLE {$target['table']} MODIFY {$target['column']} VARCHAR(52) NULL");
    }

    /**
     * @param array<string, string> $target
     */
    private function plantRow(array $target): string
    {
        $id = self::ID_PREFIX . bin2hex(random_bytes(8));

        if ($target['table'] === 'competitors') {
            $this->pdo->prepare(
                'INSERT INTO competitors (id, first_name, last_name, identity_code)
                 VALUES (:id, :first, :last, NULL)'
            )->execute(['id' => $id, 'first' => 'Backfill', 'last' => 'Test']);

            return $id;
        }

        // No foreign keys on `registrations`, so synthetic event/user ids are enough
        // to exercise the backfill without dragging in a whole event fixture.
        $this->pdo->prepare(
            'INSERT INTO registrations
                (id, event_id, event_name, user_id, user_name, user_email,
                 distance_id, distance_name, category, registered_at, qr_code)
             VALUES
                (:id, :eventId, :eventName, :userId, :userName, :userEmail,
                 :distanceId, :distanceName, :category, :registeredAt, NULL)'
        )->execute([
            'id' => $id,
            'eventId' => $id . '-event',
            'eventName' => 'Backfill Test',
            'userId' => $id . '-user',
            'userName' => 'Backfill Test',
            'userEmail' => 'backfill@example.test',
            'distanceId' => $id . '-distance',
            'distanceName' => '10 km',
            'category' => 'OPEN',
            'registeredAt' => '2026-01-01T00:00:00+00:00',
        ]);

        return $id;
    }

    /**
     * @param array<string, string> $target
     */
    private function readCode(array $target, string $id): string
    {
        $stmt = $this->pdo->prepare("SELECT {$target['column']} FROM {$target['table']} WHERE id = :id");
        $stmt->execute(['id' => $id]);

        return (string) $stmt->fetchColumn();
    }
}

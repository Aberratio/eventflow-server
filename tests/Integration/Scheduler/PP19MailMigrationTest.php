<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Scheduler;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Support\Env;

/** PP.19: migration 066 (portable, idempotent, legacy rows keep next_attempt_at NULL). */
final class PP19MailMigrationTest extends TestCase
{
    private const FILE = '066_pp19_mail_outbox_retry.sql';

    private PDO $pdo;
    private string $prefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        $this->prefix = 'phpmig66-' . bin2hex(random_bytes(4));
        $this->pdo = Connection::fromEnv();
        Migrator::applyAll($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE idempotency_key LIKE :p')->execute(['p' => $this->prefix . '%']);
        }
    }

    public function testColumnsSourceIdWidthAndRequestsTableHaveTheRightShape(): void
    {
        $file = Migrator::migrationsDir() . '/' . self::FILE;
        Migrator::applyFile($this->pdo, $file);
        Migrator::applyFile($this->pdo, $file);

        $attempts = $this->column('central_mailer_outbox', 'attempts');
        self::assertSame('NO', $attempts['IS_NULLABLE']);
        self::assertSame('0', (string) $attempts['COLUMN_DEFAULT']);
        $next = $this->column('central_mailer_outbox', 'next_attempt_at');
        self::assertSame('varchar(64)', strtolower((string) $next['COLUMN_TYPE']));
        self::assertSame('YES', $next['IS_NULLABLE']);
        $attachments = $this->column('central_mailer_outbox', 'attachments');
        self::assertSame('longtext', strtolower((string) $attachments['DATA_TYPE']));
        self::assertSame('YES', $attachments['IS_NULLABLE']);
        self::assertSame(80, (int) $this->column('central_mailer_outbox', 'source_entity_id')['CHARACTER_MAXIMUM_LENGTH']);
        self::assertSame('YES', $this->column('central_mailer_outbox', 'source_entity_id')['IS_NULLABLE']);

        self::assertSame('NO', $this->column('results_publication_mail_requests', 'requested_at')['IS_NULLABLE']);
        self::assertSame('YES', $this->column('results_publication_mail_requests', 'processed_at')['IS_NULLABLE']);
        $pk = $this->pdo->query(
            "SELECT column_name FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'results_publication_mail_requests' AND index_name = 'PRIMARY'
             ORDER BY seq_in_index"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['event_id', 'distance_id'], $pk);
    }

    public function testBackfillGivesLegacyRowsOneAttemptAndNoRetrySchedule(): void
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO central_mailer_outbox
               (id, source_entity_type, source_entity_id, idempotency_key, priority, recipient, subject, html, status, central_mailer_email_id, created_at)
             VALUES (:id, 'phpunit-fixture', NULL, :key, 'normal', 'legacy@example.test', 's', '<p>x</p>', :status, :cm, :createdAt)"
        );
        $rows = ['accepted' => ['sent', 'cm-legacy'], 'failed' => ['failed', null], 'queued' => ['queued', null]];
        foreach ($rows as $name => [$status, $cm]) {
            $insert->execute(['id' => $this->prefix . $name, 'key' => $this->prefix . '-' . $name, 'status' => $status, 'cm' => $cm, 'createdAt' => date(DATE_ATOM)]);
        }
        // Simulate rows written before the migration: attempts 0, next_attempt_at NULL.
        $this->pdo->prepare('UPDATE central_mailer_outbox SET attempts = 0, next_attempt_at = NULL WHERE idempotency_key LIKE :p')->execute(['p' => $this->prefix . '%']);

        $file = Migrator::migrationsDir() . '/' . self::FILE;
        Migrator::applyFile($this->pdo, $file);
        Migrator::applyFile($this->pdo, $file);

        $stmt = $this->pdo->prepare('SELECT attempts, next_attempt_at FROM central_mailer_outbox WHERE id = :id');
        foreach (['accepted' => 1, 'failed' => 1, 'queued' => 0] as $name => $attempts) {
            $stmt->execute(['id' => $this->prefix . $name]);
            $row = $stmt->fetch();
            self::assertSame($attempts, (int) $row['attempts'], $name);
            self::assertNull($row['next_attempt_at'], $name);
        }
    }

    public function testMigrationFileHasNoIfNotExistsInAlter(): void
    {
        $sql = (string) file_get_contents(Migrator::migrationsDir() . '/' . self::FILE);

        self::assertSame(0, preg_match('/ALTER\s+TABLE[^;]*IF\s+(NOT\s+)?EXISTS/i', $sql));
    }

    /** @return array<string, mixed> */
    private function column(string $table, string $column): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        $rows = $stmt->fetchAll();
        self::assertCount(1, $rows, $table . '.' . $column);

        return $rows[0];
    }
}

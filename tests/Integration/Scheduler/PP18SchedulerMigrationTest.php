<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Scheduler;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;

/** Migration 065 (portable, idempotent, backfill only where the old mechanism already sent a reminder). */
final class PP18SchedulerMigrationTest extends TestCase
{
    private const FILE = '065_pp18_scheduler.sql';

    private PDO $pdo;
    private string $slugPrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        $this->slugPrefix = 'phpmig65-' . bin2hex(random_bytes(4));
        $this->pdo = Connection::fromEnv();
        Migrator::applyAll($this->pdo);
        $this->deleteFixtures();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->deleteFixtures();
        }
    }

    public function testColumnsAndRunLogTableExistWithTheRightShape(): void
    {
        $file = Migrator::migrationsDir() . '/' . self::FILE;
        Migrator::applyFile($this->pdo, $file);
        Migrator::applyFile($this->pdo, $file);

        $reminder = $this->column('registrations', 'payment_reminder_for');
        self::assertSame('varchar(64)', strtolower((string) $reminder['COLUMN_TYPE']));
        self::assertSame('YES', $reminder['IS_NULLABLE']);

        $attempts = $this->column('bz_webhook_outbox', 'attempts');
        self::assertSame('NO', $attempts['IS_NULLABLE']);
        self::assertSame('0', (string) $attempts['COLUMN_DEFAULT']);
        self::assertSame('YES', $this->column('bz_webhook_outbox', 'next_attempt_at')['IS_NULLABLE']);

        $runs = $this->column('scheduler_job_runs', 'last_started_at');
        self::assertSame('NO', $runs['IS_NULLABLE']);
        self::assertSame('YES', $this->column('scheduler_job_runs', 'last_error_class')['IS_NULLABLE']);
    }

    public function testBackfillMarksOnlyRegistrationsAlreadyRemindedByTheOldMechanism(): void
    {
        $events = new EventRepository($this->pdo);
        $registrations = new RegistrationRepository($this->pdo);
        $event = $events->create([
            'name' => 'PHPUnit Migration 065',
            'slug' => $this->slugPrefix . '-ev',
            'discipline' => 'running',
            'date' => '2026-12-15',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'draft',
            'registrationDeadline' => '2026-12-01',
            'featured' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $this->slugPrefix . '-10k', 'name' => '10 km', 'length' => 10, 'unit' => 'km',
                'categories' => ['Open'], 'ageCategories' => [], 'specialCategories' => [],
                'limit' => 100, 'registeredCount' => 0, 'price' => 80, 'routeFiles' => [],
            ]],
        ]);
        $make = static fn (string $user) => $registrations->create([
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'userId' => $user,
            'userName' => 'PHPUnit User',
            'userEmail' => $user . '@example.test',
            'distanceId' => $event['distances'][0]['id'],
            'distanceName' => $event['distances'][0]['name'],
            'category' => 'Open',
            'merchandiseOrders' => [],
        ]);
        $reminded = $make('phpunit-mig-a');
        $untouched = $make('phpunit-mig-b');
        $this->pdo->prepare('UPDATE registrations SET payment_reminder_for = NULL WHERE event_id = :id')->execute(['id' => $event['id']]);
        $this->pdo->prepare(
            "INSERT INTO central_mailer_outbox
               (id, source_entity_type, source_entity_id, idempotency_key, priority, recipient, subject, html, status, created_at)
             VALUES (:id, 'unpaid_reminder', :reg, :key, 'normal', 'phpunit-mig-a@example.test', 's', '<p>x</p>', 'sent', :createdAt)"
        )->execute(['id' => 'phpmig65-' . bin2hex(random_bytes(4)), 'reg' => $reminded['id'], 'key' => $reminded['id'] . ':unpaid_reminder:v1', 'createdAt' => date(DATE_ATOM)]);

        $file = Migrator::migrationsDir() . '/' . self::FILE;
        Migrator::applyFile($this->pdo, $file);
        Migrator::applyFile($this->pdo, $file);

        $stmt = $this->pdo->prepare('SELECT payment_reminder_for, payment_expires_at FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $reminded['id']]);
        $row = $stmt->fetch();
        self::assertNotNull($row['payment_expires_at']);
        self::assertSame($row['payment_expires_at'], $row['payment_reminder_for']);
        $stmt->execute(['id' => $untouched['id']]);
        self::assertNull($stmt->fetch()['payment_reminder_for']);
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
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        $rows = $stmt->fetchAll();
        self::assertCount(1, $rows, $table . '.' . $column);

        return $rows[0];
    }

    private function deleteFixtures(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => 'phpmig65-%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $eventId) {
            $regs = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :eventId');
            $regs->execute(['eventId' => $eventId]);
            foreach ($regs->fetchAll(PDO::FETCH_COLUMN) ?: [] as $registrationId) {
                foreach (['registration_gateway_transactions', 'registration_refunds', 'registration_payment_details',
                    'registration_settlement_items', 'registration_merchandise_orders'] as $table) {
                    $this->pdo->prepare("DELETE FROM {$table} WHERE registration_id = :id")->execute(['id' => $registrationId]);
                }
                $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE source_entity_id = :id')->execute(['id' => $registrationId]);
            }
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :eventId')->execute(['eventId' => $eventId]);
        }
    }
}

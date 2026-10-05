<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Scheduler;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\FailOncePdo;

/**
 * PP.18: the scheduler's expiry job and its money guards. Every decision is made against an
 * injected $now, never the wall clock.
 */
final class UnpaidExpiryJobTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private TestHandler $logs;
    private string $slugPrefix;
    private string $previousTimezone;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        $this->previousTimezone = date_default_timezone_get();
        $this->slugPrefix = 'phpexpjob-' . bin2hex(random_bytes(4));
        $this->pdo = Connection::fromEnv();
        // Outside any try/catch: a broken migration must fail, not skip.
        Migrator::applyAll($this->pdo);
        $this->deleteFixtures();
        $this->events = new EventRepository($this->pdo);
        $this->logs = new TestHandler();
        $this->registrations = new RegistrationRepository($this->pdo, logger: new Logger('test', [$this->logs]));
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
        if (isset($this->pdo)) {
            $this->deleteFixtures();
        }
    }

    public function testExpiresOverdueAndReleasesSlotOnce(): void
    {
        [$id, $distanceId] = $this->pendingRegistration('once');
        self::assertSame(1, $this->registeredCount($distanceId));
        $now = new \DateTimeImmutable();
        $this->setExpiry($id, $now->modify('-1 minute'));

        $first = $this->registrations->expireOverdueRegistrations($now);
        $second = $this->registrations->expireOverdueRegistrations($now);

        self::assertSame('expired', $this->statusOf($id));
        self::assertSame(0, $this->registeredCount($distanceId), 'the slot is released exactly once');
        self::assertGreaterThanOrEqual(1, $first['expired']);
        self::assertSame(0, $second['expired']);
    }

    public function testReadPathsNoLongerExpireAnything(): void
    {
        [$id, $distanceId, $eventId] = $this->pendingRegistration('reads');
        $this->setExpiry($id, (new \DateTimeImmutable())->modify('-1 hour'));

        $this->registrations->find($id);
        $this->registrations->byEvent($eventId);
        $this->registrations->list(['eventId' => $eventId]);
        $this->registrations->publicStartList($eventId, true);
        $this->registrations->byUser('phpunit-expjob-user');
        $this->registrations->organizerStats($eventId);

        self::assertSame('pending', $this->statusOf($id));
        self::assertSame(1, $this->registeredCount($distanceId));
    }

    /**
     * An expiry decision never depends on the PHP default timezone: a deadline stored as
     * "UTC now + 10 min" must stay pending when PHP's default zone is two hours ahead of UTC.
     * (A lexical compare of "…T12:xx+02:00" against "…T10:yy+00:00" expires it two hours early.)
     */
    public function testDoesNotExpireEarlyWhenPhpTimezoneDiffersFromStoredOffset(): void
    {
        [$id] = $this->pendingRegistration('tz');
        $utc = new \DateTimeZone('UTC');
        $this->setExpiryRaw($id, (new \DateTimeImmutable('now', $utc))->modify('+10 minutes')->format(DATE_ATOM));

        date_default_timezone_set('Europe/Warsaw');
        $result = $this->registrations->expireOverdueRegistrations(new \DateTimeImmutable());

        self::assertSame('pending', $this->statusOf($id));
        self::assertSame(0, $result['expired']);
    }

    /** The opposite direction: a UTC deadline already past still expires under a different default zone. */
    public function testExpiresPastUtcDeadlineEvenWhenPhpTimezoneIsAhead(): void
    {
        [$id] = $this->pendingRegistration('tz-past');
        $utc = new \DateTimeZone('UTC');
        $this->setExpiryRaw($id, (new \DateTimeImmutable('now', $utc))->modify('-10 minutes')->format(DATE_ATOM));

        date_default_timezone_set('Pacific/Kiritimati');
        $this->registrations->expireOverdueRegistrations(new \DateTimeImmutable());

        self::assertSame('expired', $this->statusOf($id));
    }

    public function testKeepsPendingWithPaidStartFee(): void
    {
        [$id, $distanceId] = $this->pendingRegistration('paidfee');
        $now = new \DateTimeImmutable();
        $this->setExpiry($id, $now->modify('-1 hour'));
        $this->pdo->prepare(
            'INSERT INTO registration_payment_details (registration_id, start_fee_paid) VALUES (:id, 1)
             ON DUPLICATE KEY UPDATE start_fee_paid = 1'
        )->execute(['id' => $id]);

        $result = $this->registrations->expireOverdueRegistrations($now);

        self::assertSame('pending', $this->statusOf($id));
        self::assertSame(1, $this->registeredCount($distanceId));
        self::assertGreaterThanOrEqual(1, $result['keptPaid']);
        self::assertTrue($this->logs->hasWarningThatContains('paid start fee'));
    }

    /** @return array<string, array{0: string}> */
    public static function moneyArrivedStatuses(): array
    {
        return ['paid' => ['paid'], 'amount_mismatch' => ['amount_mismatch']];
    }

    #[DataProvider('moneyArrivedStatuses')]
    public function testKeepsPendingWithAmountMismatchOrPaidTransaction(string $transactionStatus): void
    {
        [$id, $distanceId] = $this->pendingRegistration('tx-' . substr($transactionStatus, 0, 3));
        $now = new \DateTimeImmutable();
        $this->setExpiry($id, $now->modify('-2 hours'));
        $this->insertTransaction($id, 'TX-' . $this->slugPrefix . '-' . $transactionStatus, $transactionStatus);

        $result = $this->registrations->expireOverdueRegistrations($now);

        self::assertSame('pending', $this->statusOf($id));
        self::assertSame(1, $this->registeredCount($distanceId));
        self::assertGreaterThanOrEqual(1, $result['keptPaid']);
    }

    public function testDefersInFlightGatewayPaymentWithinGraceThenExpires(): void
    {
        [$id, $distanceId] = $this->pendingRegistration('inflight');
        $deadline = new \DateTimeImmutable('2026-10-05 12:00:00+00:00');
        $this->setExpiryRaw($id, $deadline->format(DATE_ATOM));
        $this->insertTransaction($id, 'TX-' . $this->slugPrefix . '-created', 'created');

        $within = $this->registrations->expireOverdueRegistrations($deadline->modify('+5 minutes'));
        self::assertSame('pending', $this->statusOf($id));
        self::assertSame(1, $this->registeredCount($distanceId));
        self::assertGreaterThanOrEqual(1, $within['deferredInFlight']);

        $after = $this->registrations->expireOverdueRegistrations($deadline->modify('+16 minutes'));
        self::assertSame('expired', $this->statusOf($id));
        self::assertSame(0, $this->registeredCount($distanceId));
        self::assertGreaterThanOrEqual(1, $after['expired']);
    }

    public function testOneFailingRowDoesNotAbortBatch(): void
    {
        [$badId] = $this->pendingRegistration('bad');
        [$goodId] = $this->pendingRegistration('good');
        // Both sort before any other overdue row (ORDER BY payment_expires_at); the bad one first.
        $this->setExpiryRaw($badId, '2000-01-01T00:00:00+00:00');
        $this->setExpiryRaw($goodId, '2000-01-02T00:00:00+00:00');
        $failing = new FailOncePdo("UPDATE registrations SET status = 'expired'", new \PDOException('simulated deadlock', 40001));
        $registrations = new RegistrationRepository($failing, logger: new Logger('test', [$this->logs]));

        $result = $registrations->expireOverdueRegistrations(new \DateTimeImmutable());

        self::assertSame('pending', $this->statusOf($badId), 'the failing row stays pending and is retried next tick');
        self::assertSame('expired', $this->statusOf($goodId), 'the batch continues after the failure');
        self::assertGreaterThanOrEqual(1, $result['expired']);
        self::assertTrue($this->logs->hasWarningThatContains('Registration expiry failed'));
    }

    public function testCreateStillExpiresOverdueOccupantOfTheSameDistance(): void
    {
        [$id, $distanceId, $eventId] = $this->pendingRegistration('create-scope', limit: 1);
        $this->setExpiry($id, (new \DateTimeImmutable())->modify('-1 hour'));
        $event = $this->events->find($eventId);

        $second = $this->registrations->create($this->registrationPayload($event, 'phpunit-expjob-second'));

        self::assertSame('expired', $this->statusOf($id));
        self::assertSame('pending', $second['status']);
        self::assertSame(1, $this->registeredCount($distanceId));
    }

    // --- fixtures -------------------------------------------------------------

    /** @return array{0: string, 1: string, 2: string} registration id, distance id, event id */
    private function pendingRegistration(string $name, int $limit = 100): array
    {
        $slug = $this->slugPrefix . '-' . $name;
        $event = $this->events->create([
            'name' => 'PHPUnit Expiry ' . $name,
            'slug' => $slug,
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
                'id' => $slug . '-10k', 'name' => '10 km', 'length' => 10, 'unit' => 'km',
                'categories' => ['Open'], 'ageCategories' => [], 'specialCategories' => [],
                'limit' => $limit, 'registeredCount' => 0, 'price' => 80, 'routeFiles' => [],
            ]],
        ]);
        $registration = $this->registrations->create($this->registrationPayload($event, 'phpunit-expjob-user'));
        self::assertSame('pending', $registration['status']);

        return [(string) $registration['id'], (string) $event['distances'][0]['id'], (string) $event['id']];
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    private function registrationPayload(array $event, string $userId): array
    {
        return [
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'userId' => $userId,
            'userName' => 'PHPUnit User',
            'userEmail' => $userId . '@example.test',
            'distanceId' => $event['distances'][0]['id'],
            'distanceName' => $event['distances'][0]['name'],
            'category' => 'Open',
            'merchandiseOrders' => [],
        ];
    }

    private function setExpiry(string $id, \DateTimeImmutable $when): void
    {
        $this->setExpiryRaw($id, $when->format(DATE_ATOM));
    }

    private function setExpiryRaw(string $id, string $raw): void
    {
        $this->pdo->prepare('UPDATE registrations SET payment_expires_at = :when WHERE id = :id')
            ->execute(['when' => $raw, 'id' => $id]);
    }

    private function insertTransaction(string $registrationId, string $transactionId, string $status): void
    {
        $this->pdo->prepare(
            'INSERT INTO registration_gateway_transactions
               (transaction_id, registration_id, gateway, status, amount, created_at)
             VALUES (:tx, :reg, :gateway, :status, :amount, :createdAt)'
        )->execute([
            'tx' => $transactionId, 'reg' => $registrationId, 'gateway' => 'tpay', 'status' => $status,
            'amount' => '80.00', 'createdAt' => date(DATE_ATOM),
        ]);
    }

    private function statusOf(string $id): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return (string) $stmt->fetchColumn();
    }

    private function registeredCount(string $distanceId): int
    {
        $stmt = $this->pdo->prepare('SELECT registered_count FROM event_distances WHERE id = :id');
        $stmt->execute(['id' => $distanceId]);

        return (int) $stmt->fetchColumn();
    }

    private function deleteFixtures(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => 'phpexpjob-%']);
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

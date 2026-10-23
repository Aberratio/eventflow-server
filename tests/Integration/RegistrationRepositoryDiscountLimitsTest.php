<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;

/**
 * Discount code limits: a percent code can never discount more than the start fee, and the
 * `max_uses` / `used_by` bookkeeping is read-and-written under the row lock (no stale copy).
 */
final class RegistrationRepositoryDiscountLimitsTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private string $slugPrefix;

    /** @var array<string, mixed> */
    private array $event;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->slugPrefix = 'phpdisc-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->deleteFixtures();
            $this->events = new EventRepository($this->pdo);
            $this->registrations = new RegistrationRepository($this->pdo);
            $this->event = $this->events->create($this->eventPayload());
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->deleteFixtures();
        }
    }

    public function testPercentCodeAboveOneHundredNeverDiscountsMoreThanTheStartFee(): void
    {
        $this->insertCode('OVER150', 'percent', 150);

        $registration = $this->registrations->create($this->payload('user-a', 'OVER150'));

        self::assertSame(80.0, (float) $registration['discountAmount']);
        self::assertSame(0.0, (float) $registration['totalAmount']);
    }

    public function testCodeAlreadyAtItsLimitIsRejected(): void
    {
        $this->insertCode('ONCE', 'fixed', 10, 1, 1);

        try {
            $this->registrations->create($this->payload('user-a', 'ONCE'));
            self::fail('A used-up code must be refused.');
        } catch (\RuntimeException $e) {
            self::assertSame('discount_code_used_up', $e->getMessage());
        }
    }

    public function testMultiUseCodeKeepsEarlierUsedByEntries(): void
    {
        $this->insertCode('THREE', 'fixed', 10, 3);

        $first = $this->registrations->create($this->payload('user-a', 'THREE'));
        $second = $this->registrations->create($this->payload('user-b', 'THREE'));

        [$count, $usedBy] = $this->codeState('THREE');
        self::assertSame(2, $count);
        self::assertSame([$first['id'], $second['id']], array_column($usedBy, 'registrationId'));
    }

    /**
     * The race itself: another registration took the last use after this one had read the code.
     * markDiscountUsed() gets the stale copy (`usedBy` empty) the pricing step read earlier.
     */
    public function testLastUseTakenByAnotherRegistrationAfterTheReadIsRefusedAndKeepsUsedBy(): void
    {
        $id = $this->insertCode('RACE', 'fixed', 10, 1);
        $winner = $this->registrations->create($this->payload('user-a', 'RACE'));

        $method = new ReflectionMethod(RegistrationRepository::class, 'markDiscountUsed');
        $stale = ['usedBy' => [], 'distanceName' => '10 km'];
        try {
            $method->invoke($this->registrations, $id, 'late-registration', ['userId' => 'user-b', 'userName' => 'B'], $stale);
            self::fail('The use over the limit must be refused.');
        } catch (\RuntimeException $e) {
            self::assertSame('discount_code_used_up', $e->getMessage());
        }

        [$count, $usedBy] = $this->codeState('RACE');
        self::assertSame(1, $count);
        self::assertSame([$winner['id']], array_column($usedBy, 'registrationId'));
    }

    public function testUsedByIsBuiltFromTheCurrentRowNotTheStaleCopy(): void
    {
        $id = $this->insertCode('MANY', 'fixed', 10, 5);
        $first = $this->registrations->create($this->payload('user-a', 'MANY'));

        $method = new ReflectionMethod(RegistrationRepository::class, 'markDiscountUsed');
        $method->invoke($this->registrations, $id, 'second-registration', ['userId' => 'user-b', 'userName' => 'B'], ['usedBy' => [], 'distanceName' => '10 km']);

        [$count, $usedBy] = $this->codeState('MANY');
        self::assertSame(2, $count);
        self::assertSame([$first['id'], 'second-registration'], array_column($usedBy, 'registrationId'));
    }

    /** @return array{0: int, 1: array<int, array<string, mixed>>} */
    private function codeState(string $code): array
    {
        $stmt = $this->pdo->prepare('SELECT used_count, used_by FROM event_discount_codes WHERE event_id = :e AND code = :c');
        $stmt->execute(['e' => $this->event['id'], 'c' => $code]);
        $row = $stmt->fetch();

        return [(int) $row['used_count'], (array) json_decode((string) $row['used_by'], true)];
    }

    private function insertCode(string $code, string $type, float $value, ?int $maxUses = null, int $usedCount = 0): string
    {
        $id = 'dc-' . bin2hex(random_bytes(6));
        $this->pdo->prepare(
            'INSERT INTO event_discount_codes (id, event_id, code, discount_type, discount_value, max_uses, used_count) VALUES (:id, :e, :c, :t, :v, :m, :u)'
        )->execute(['id' => $id, 'e' => $this->event['id'], 'c' => $code, 't' => $type, 'v' => $value, 'm' => $maxUses, 'u' => $usedCount]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function payload(string $userId, string $code): array
    {
        return [
            'eventId' => $this->event['id'],
            'eventName' => $this->event['name'],
            'userId' => $userId,
            'userName' => 'PHPUnit ' . $userId,
            'userEmail' => $userId . '@example.test',
            'distanceId' => $this->event['distances'][0]['id'],
            'distanceName' => $this->event['distances'][0]['name'],
            'category' => 'Open',
            'merchandiseOrders' => [],
            'discountCode' => $code,
        ];
    }

    /** @return array<string, mixed> */
    private function eventPayload(): array
    {
        $slug = $this->slugPrefix;

        return [
            'name' => 'PHPUnit Discount Limits Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-06-15',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'draft',
            'registrationDeadline' => '2026-06-01',
            'featured' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $slug . '-10k',
                'name' => '10 km',
                'length' => 10,
                'unit' => 'km',
                'categories' => ['Open'],
                'ageCategories' => [],
                'specialCategories' => [],
                'limit' => 100,
                'registeredCount' => 0,
                'price' => 80,
                'routeFiles' => [],
            ]],
        ];
    }

    private function deleteFixtures(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => 'phpdisc-%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $eventId) {
            $regStmt = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :eventId');
            $regStmt->execute(['eventId' => $eventId]);
            foreach ($regStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $registrationId) {
                foreach (['registration_gateway_transactions', 'registration_refunds', 'registration_payment_details', 'registration_settlement_items', 'registration_merchandise_orders'] as $table) {
                    $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE registration_id = :id')->execute(['id' => $registrationId]);
                }
            }
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_discount_codes WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :eventId')->execute(['eventId' => $eventId]);
        }
    }
}

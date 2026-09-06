<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;

/**
 * E2.2: manual create/update of a Result (POST/PATCH /results, backing
 * AddResultDialog.tsx) must enforce the same DNF/DNS/DSQ-excluded-from-
 * ranking rule that ResultImportRepository already enforces for CSV import
 * (spec 14 sekcja 1), and must keep sibling positions in the same
 * event+distance correct after a manual insert/edit - see
 * ResultRepository::renumberEventDistance().
 */
final class ResultRepositoryTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private ResultRepository $results;
    private UserRepository $users;
    private string $prefix;
    private array $event;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->prefix = 'phpres-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->deletePhpUnitData();
            $this->events = new EventRepository($this->pdo);
            $this->registrations = new RegistrationRepository($this->pdo);
            $this->results = new ResultRepository($this->pdo);
            $this->users = new UserRepository($this->pdo);
            $this->event = $this->events->create($this->eventPayload());
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for result repository tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        $this->deletePhpUnitData();
    }

    public function testManualCreateWithDnsStatusIsForcedToPositionZeroRegardlessOfPayload(): void
    {
        $participant = $this->createParticipant('Ewa Krol');

        // A manual entry (or a client bug) could still send a nonzero
        // position for a non-finished status - the repository must not
        // trust it.
        $created = $this->results->create($this->resultPayload($participant, [
            'status' => 'dns',
            'time' => '',
            'timeInSeconds' => 0,
            'position' => 5,
            'categoryPosition' => 3,
        ]));

        self::assertSame('dns', $created['status']);
        self::assertSame(0, (int) $created['position']);
        self::assertSame(0, (int) $created['categoryPosition']);
    }

    public function testManualCreateWithDnfStatusMayKeepAPartialTimeButNoPosition(): void
    {
        $participant = $this->createParticipant('Piotr Zielinski');

        $created = $this->results->create($this->resultPayload($participant, [
            'status' => 'dnf',
            'time' => '0:20:00',
            'timeInSeconds' => 1200,
            'position' => 7,
            'categoryPosition' => 4,
        ]));

        self::assertSame('dnf', $created['status']);
        self::assertSame('0:20:00', $created['time']);
        self::assertSame(0, (int) $created['position']);
        self::assertSame(0, (int) $created['categoryPosition']);
    }

    public function testManualUpdateFlippingStatusToDsqZeroesOutItsPosition(): void
    {
        $participant = $this->createParticipant('Tomasz Nowicki');
        $created = $this->results->create($this->resultPayload($participant, [
            'status' => 'finished',
            'time' => '0:30:00',
            'timeInSeconds' => 1800,
            'position' => 1,
            'categoryPosition' => 1,
        ]));

        $updated = $this->results->update((string) $created['id'], [
            'status' => 'dsq',
            // Payload still carries the old ranking - repository must
            // override it, not trust it.
            'position' => 1,
            'categoryPosition' => 1,
        ]);

        self::assertSame('dsq', $updated['status']);
        self::assertSame(0, (int) $updated['position']);
        self::assertSame(0, (int) $updated['categoryPosition']);
    }

    public function testManualNetTimeRoundTripsThroughCreateAndUpdate(): void
    {
        $participant = $this->createParticipant('Kamil Wojcik');

        $created = $this->results->create($this->resultPayload($participant, [
            'time' => '0:41:10',
            'timeInSeconds' => 2470,
            'netTime' => '0:40:58',
            'netTimeInSeconds' => 2458,
            'position' => 1,
            'categoryPosition' => 1,
        ]));

        self::assertSame('0:40:58', $created['netTime']);
        self::assertEqualsWithDelta(2458.0, (float) $created['netTimeInSeconds'], 0.001);

        $updated = $this->results->update((string) $created['id'], [
            'netTime' => '0:40:50',
            'netTimeInSeconds' => 2450,
        ]);

        self::assertSame('0:40:50', $updated['netTime']);
        self::assertEqualsWithDelta(2450.0, (float) $updated['netTimeInSeconds'], 0.001);
    }

    /**
     * Inserting a faster finisher into an event+distance that already has a
     * result must renumber the whole finished group by timeInSeconds
     * ascending (no explicit position given by either), mirroring
     * ResultImportRepository::positions()'s ordering rule.
     */
    public function testInsertingAFasterFinisherRenumbersExistingSiblings(): void
    {
        $slow = $this->createParticipant('Marta Wisniewska');
        $slowResult = $this->results->create($this->resultPayload($slow, [
            'time' => '1:00:00',
            'timeInSeconds' => 3600,
            'position' => 0,
            'categoryPosition' => 0,
        ]));
        self::assertSame(1, (int) $slowResult['position']);

        $fast = $this->createParticipant('Anna Nowak');
        $fastResult = $this->results->create($this->resultPayload($fast, [
            'time' => '0:30:00',
            'timeInSeconds' => 1800,
            'position' => 0,
            'categoryPosition' => 0,
        ]));

        self::assertSame(1, (int) $fastResult['position']);
        self::assertSame(1, (int) $fastResult['categoryPosition']);

        $refreshedSlow = $this->results->find((string) $slowResult['id']);
        self::assertSame(2, (int) $refreshedSlow['position']);
        self::assertSame(2, (int) $refreshedSlow['categoryPosition']);
    }

    /**
     * Flipping a result from `finished` to `dns` must not leave the
     * remaining finished sibling with a stale (skipped) position.
     */
    public function testFlippingAResultToDnsRenumbersRemainingFinishedSiblings(): void
    {
        $first = $this->createParticipant('Jan Kowalski');
        $firstResult = $this->results->create($this->resultPayload($first, [
            'time' => '0:30:00',
            'timeInSeconds' => 1800,
            'position' => 0,
            'categoryPosition' => 0,
        ]));

        $second = $this->createParticipant('Ewa Krol');
        $secondResult = $this->results->create($this->resultPayload($second, [
            'time' => '0:40:00',
            'timeInSeconds' => 2400,
            'position' => 0,
            'categoryPosition' => 0,
        ]));
        self::assertSame(1, (int) $firstResult['position']);
        self::assertSame(2, (int) $secondResult['position']);

        $this->results->update((string) $firstResult['id'], ['status' => 'dns', 'time' => '', 'timeInSeconds' => 0]);

        $refreshedSecond = $this->results->find((string) $secondResult['id']);
        self::assertSame(1, (int) $refreshedSecond['position']);
        self::assertSame(1, (int) $refreshedSecond['categoryPosition']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function resultPayload(array $participant, array $overrides = []): array
    {
        return array_merge([
            'eventId' => $this->event['id'],
            'eventName' => $this->event['name'],
            'distanceId' => $this->event['distances'][0]['id'],
            'distanceName' => $this->event['distances'][0]['name'],
            'userId' => $participant['id'],
            'userName' => $participant['name'],
            'bibNumber' => '1',
            'category' => 'Open',
            'time' => '1:00:00',
            'timeInSeconds' => 3600,
            'position' => 0,
            'categoryPosition' => 0,
            'status' => 'finished',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function createParticipant(string $name): array
    {
        return $this->users->create([
            'name' => $name,
            'email' => $this->prefix . '.' . strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(2)) . '@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
    }

    /** @return array<string, mixed> */
    private function eventPayload(): array
    {
        return [
            'name' => 'PHPUnit Result Event',
            'slug' => $this->prefix . '-event',
            'discipline' => 'running',
            'date' => '2026-06-15',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->prefix . '-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'active',
            'registrationDeadline' => '2026-06-01',
            'featured' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [
                [
                    'id' => $this->prefix . '-10k',
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
                ],
            ],
        ];
    }

    private function deletePhpUnitData(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => $this->prefix . '%']);
        foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $eventId) {
            $resultStmt = $this->pdo->prepare('SELECT id FROM results WHERE event_id = :id');
            $resultStmt->execute(['id' => $eventId]);
            foreach (($resultStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $resultId) {
                $this->pdo->prepare('DELETE FROM result_splits WHERE result_id = :id')->execute(['id' => $resultId]);
            }

            $this->pdo->prepare('DELETE FROM results WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distance_pricing_variants WHERE distance_id IN (SELECT id FROM event_distances WHERE event_id = :id)')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        }

        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '.%@example.test']);
    }
}

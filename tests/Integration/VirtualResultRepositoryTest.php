<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;
use ZmierzymyCzas\Domain\Results\VirtualResultRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;

final class VirtualResultRepositoryTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $users;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private ResultRepository $results;
    private VirtualResultRepository $virtualResults;
    private string $prefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->prefix = 'phpvirtual-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->deletePhpUnitData();
            $this->users = new UserRepository($this->pdo);
            $this->events = new EventRepository($this->pdo);
            $this->registrations = new RegistrationRepository($this->pdo);
            $this->results = new ResultRepository($this->pdo);
            $this->virtualResults = new VirtualResultRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        $this->deletePhpUnitData();
    }

    public function testSubmitApproveAndRecalculateOfficialResults(): void
    {
        $organizer = $this->users->create([
            'name' => 'PHPUnit Virtual Organizer',
            'email' => $this->prefix . '-organizer@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
        ]);
        $participantSlow = $this->users->create([
            'name' => 'PHPUnit Slow Runner',
            'email' => $this->prefix . '-slow@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
            'gender' => 'M',
            'birthYear' => 1990,
        ]);
        $participantFast = $this->users->create([
            'name' => 'PHPUnit Fast Runner',
            'email' => $this->prefix . '-fast@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
            'gender' => 'K',
            'birthYear' => 1992,
        ]);
        $event = $this->events->create($this->eventPayload((string) $organizer['id']));

        $slowRegistration = $this->createRegistration($event, $participantSlow, '101');
        $fastRegistration = $this->createRegistration($event, $participantFast, '102');

        $slowSubmission = $this->virtualResults->submit([
            'eventId' => $event['id'],
            'distanceId' => $event['distances'][0]['id'],
            'submittedTime' => '00:55:00',
            'submittedTimeInSeconds' => 3300,
            'proofUrl' => 'https://example.test/slow',
        ], (string) $participantSlow['id']);

        self::assertSame('pending', $slowSubmission['status']);
        self::assertSame($slowRegistration['id'], $slowSubmission['registrationId']);

        $this->expectDuplicateSubmission($event, $participantSlow);

        $this->virtualResults->review((string) $slowSubmission['id'], 'approved', (string) $organizer['id']);

        $fastSubmission = $this->virtualResults->submit([
            'eventId' => $event['id'],
            'distanceId' => $event['distances'][0]['id'],
            'submittedTime' => '00:45:00',
            'submittedTimeInSeconds' => 2700,
        ], (string) $participantFast['id']);
        $this->virtualResults->review((string) $fastSubmission['id'], 'approved', (string) $organizer['id']);

        $official = $this->results->byEvent((string) $event['id'], (string) $event['distances'][0]['id']);

        self::assertCount(2, $official);
        self::assertSame($participantFast['id'], $official[0]['userId']);
        self::assertSame($fastRegistration['id'], $official[0]['registrationId']);
        self::assertSame('102', $official[0]['bibNumber']);
        self::assertSame(1, (int) $official[0]['position']);
        self::assertSame($participantSlow['id'], $official[1]['userId']);
        self::assertSame(2, (int) $official[1]['position']);
    }

    private function expectDuplicateSubmission(array $event, array $participant): void
    {
        try {
            $this->virtualResults->submit([
                'eventId' => $event['id'],
                'distanceId' => $event['distances'][0]['id'],
                'submittedTime' => '00:56:00',
                'submittedTimeInSeconds' => 3360,
            ], (string) $participant['id']);
            self::fail('Duplicate active virtual submission should be rejected.');
        } catch (\RuntimeException $e) {
            self::assertSame('virtual_result_duplicate', $e->getMessage());
        }
    }

    private function eventPayload(string $organizerId): array
    {
        return [
            'name' => 'PHPUnit Virtual Run',
            'slug' => $this->prefix . '-event',
            'discipline' => 'running',
            'date' => '2026-06-21',
            'startDate' => '2026-01-01',
            'endDate' => '2026-12-31',
            'location' => 'Wirtualnie',
            'city' => 'Online',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $organizerId,
            'organizerName' => 'PHPUnit Virtual Organizer',
            'status' => 'active',
            'registrationDeadline' => '2026-12-01',
            'featured' => false,
            'isVirtual' => true,
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

    private function createRegistration(array $event, array $participant, string $bibNumber): array
    {
        $registration = $this->registrations->create([
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'userId' => $participant['id'],
            'userName' => $participant['name'],
            'userEmail' => $participant['email'],
            'distanceId' => $event['distances'][0]['id'],
            'distanceName' => $event['distances'][0]['name'],
            'category' => 'Open',
            'merchandiseOrders' => [],
            'paymentDetails' => [
                'startFeePaid' => true,
                'startFeePaidAt' => '2026-06-01',
                'startFeeAmount' => 80,
                'insurancePaid' => false,
                'merchandisePayments' => [],
            ],
        ]);

        return $this->registrations->update((string) $registration['id'], ['bibNumber' => $bibNumber]) ?? $registration;
    }

    private function deletePhpUnitData(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => 'phpvirtual-%']);
        foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $eventId) {
            $registrationIds = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
            $registrationIds->execute(['id' => $eventId]);
            foreach (($registrationIds->fetchAll(PDO::FETCH_COLUMN) ?: []) as $registrationId) {
                $this->pdo->prepare('DELETE FROM registration_merchandise_orders WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_payment_details WHERE registration_id = :id')->execute(['id' => $registrationId]);
            }
            $this->pdo->prepare('DELETE FROM virtual_result_submissions WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM results WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        }

        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute([
            'email' => 'phpvirtual-%@example.test',
        ]);
    }
}

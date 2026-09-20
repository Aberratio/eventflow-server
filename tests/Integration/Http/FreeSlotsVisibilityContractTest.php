<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * E8.2 (spec 21 Answer §4): free-slots counter, default visible, organizer
 * can hide it per Event. Mirrors the existing `display_options.showParticipants`
 * toggle's own mechanism exactly (same JSON column, same per-Event scope,
 * same generic PATCH /events/{id} write path) — this test only exercises the
 * READ side (public GET /events/{id} must genuinely omit `slotsRemaining`,
 * not send it as null, when the toggle is off).
 */
final class FreeSlotsVisibilityContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private string $prefix;
    private array $organizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->prefix = 'http-freeslots-' . bin2hex(random_bytes(4));

        $this->organizer = $this->users->create([
            'name' => 'Free Slots Organizer',
            'email' => $this->prefix . '-organizer@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            'eventLimit' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slug');
        $stmt->execute(['slug' => $this->prefix . '-%']);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testSlotsRemainingIsVisibleByDefaultToAGuest(): void
    {
        $event = $this->createPublishedEvent();

        $response = $this->request('GET', '/api/v1/events/' . $event['id']);
        self::assertSame(200, $response->getStatusCode());
        $distance = $this->json($response)['data']['distances'][0];

        self::assertArrayHasKey('slotsRemaining', $distance);
        self::assertSame(100, $distance['slotsRemaining']);
    }

    public function testOrganizerCanHideSlotsRemainingAndAGuestNeverSeesTheField(): void
    {
        $event = $this->createPublishedEvent();
        $this->events->update((string) $event['id'], ['displayOptions' => ['showFreeSlots' => false]]);

        $response = $this->request('GET', '/api/v1/events/' . $event['id']);
        self::assertSame(200, $response->getStatusCode());
        $distance = $this->json($response)['data']['distances'][0];

        self::assertArrayNotHasKey('slotsRemaining', $distance, 'Hidden must omit the field entirely, not send null.');

        // The owning organizer keeps seeing it — same "manager reads keep
        // everything" contract as $stripBzToken's other guarded field.
        $ownerResponse = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'],
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        $ownerDistance = $this->json($ownerResponse)['data']['distances'][0];
        self::assertArrayHasKey('slotsRemaining', $ownerDistance);
    }

    private function createPublishedEvent(): array
    {
        $slug = $this->prefix . '-event-' . bin2hex(random_bytes(3));
        $event = $this->events->createForOrganizer([
            'name' => 'Free Slots Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => $this->organizer['name'],
            'status' => 'draft',
            'registrationDeadline' => '2026-09-01',
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [
                [
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
                ],
            ],
        ], $this->organizer, $this->organizer['id']);

        $this->events->update((string) $event['id'], ['publicationStatus' => 'published']);

        return $this->events->find((string) $event['id']);
    }
}

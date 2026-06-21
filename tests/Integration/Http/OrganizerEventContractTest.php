<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

final class OrganizerEventContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private string $prefix;
    private array $organizerA;
    private array $organizerB;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->prefix = 'http-org-' . bin2hex(random_bytes(4));

        $this->ensureEventStatusSchema();

        $this->organizerA = $this->users->create([
            'name' => 'Organizer A',
            'email' => $this->prefix . '-orgA@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
        ]);
        $this->organizerB = $this->users->create([
            'name' => 'Organizer B',
            'email' => $this->prefix . '-orgB@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
        ]);
        $this->admin = $this->users->create([
            'name' => 'Org Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slug');
        $stmt->execute(['slug' => $this->prefix . '-%']);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $id) {
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_discount_codes WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_payment_methods WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_merchandise WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_gallery_albums WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testOrganizerCanCreateEventForSelf(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-create']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $payload = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame($this->organizerA['id'], $payload['data']['organizerId']);
        // Organizer-created events start as draft
        self::assertSame('draft', $payload['data']['status']);
    }

    public function testOrganizerCannotSetFeaturedFlag(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-featured', 'featured' => true]),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $payload = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        // featured flag is stripped for non-staff
        self::assertFalse((bool) $payload['data']['featured']);
    }

    public function testOrganizerCannotEditAnotherOrganizersEvent(): void
    {
        $event = $this->events->create($this->eventPayload([
            'slug' => $this->prefix . '-other',
            'organizerId' => $this->organizerA['id'],
            'organizerName' => 'Organizer A',
            'status' => 'draft',
        ]));

        $response = $this->request(
            'PATCH',
            '/api/v1/events/' . $event['id'],
            ['name' => 'Hacked Name'],
            $this->bearer($this->organizerB['id'], 'organizer', $this->organizerB['email'])
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->json($response)['error']['code']);
    }

    public function testOrganizerCanAccessOwnEventsList(): void
    {
        $response = $this->request(
            'GET',
            '/api/v1/organizers/' . $this->organizerA['id'] . '/events',
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
    }

    public function testOrganizerCannotAccessOtherOrganizersEventsList(): void
    {
        $response = $this->request(
            'GET',
            '/api/v1/organizers/' . $this->organizerA['id'] . '/events',
            [],
            $this->bearer($this->organizerB['id'], 'organizer', $this->organizerB['email'])
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->json($response)['error']['code']);
    }

    public function testAdminCanAccessAnyOrganizersEventsList(): void
    {
        $response = $this->request(
            'GET',
            '/api/v1/organizers/' . $this->organizerA['id'] . '/events',
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->json($response)['success']);
    }

    public function testModerationWorkflowViaHttpSubmitApprovePublish(): void
    {
        // Organizer creates a draft event
        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-workflow']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(201, $createResp->getStatusCode());
        $eventId = $this->json($createResp)['data']['id'];

        // Organizer submits for review
        $submitResp = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/submit',
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(200, $submitResp->getStatusCode());
        self::assertSame('awaiting_approval', $this->json($submitResp)['data']['status']);

        // Admin approves
        $approveResp = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/approve',
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $approveResp->getStatusCode());
        self::assertSame('approved', $this->json($approveResp)['data']['status']);

        // Organizer publishes
        $publishResp = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/publish',
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(200, $publishResp->getStatusCode());
        self::assertSame('active', $this->json($publishResp)['data']['status']);
    }

    public function testAdminCanRejectEventWithReason(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-reject']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];

        $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/submit',
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );

        $rejectResp = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/reject',
            ['reason' => 'Brakuje regulaminu'],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        $payload = $this->json($rejectResp);

        self::assertSame(200, $rejectResp->getStatusCode());
        self::assertSame('draft', $payload['data']['status']);
        self::assertSame('Brakuje regulaminu', $payload['data']['rejectionReason']);
    }

    public function testRejectWithoutReasonReturns422(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-reject-empty']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];

        $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/submit',
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );

        $rejectResp = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/reject',
            ['reason' => ''],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );

        self::assertSame(422, $rejectResp->getStatusCode());
        self::assertSame('validation_error', $this->json($rejectResp)['error']['code']);
    }

    private function ensureEventStatusSchema(): void
    {
        $this->pdo->exec("ALTER TABLE events MODIFY status ENUM('draft','awaiting_approval','approved','active','past','archived','deleted') NOT NULL DEFAULT 'draft'");
    }

    private function eventPayload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? $this->prefix . '-default');

        return array_replace_recursive([
            'name' => 'PHPUnit Organizer Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizerA['id'],
            'organizerName' => 'Organizer A',
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
        ], $overrides);
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

final class RegistrationContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private string $prefix;
    private array $participant;
    private array $organizer;
    private array $admin;
    private array $activeEvent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo);
        $this->prefix = 'http-reg-' . bin2hex(random_bytes(4));

        $this->ensureEventStatusSchema();

        $this->participant = $this->users->create([
            'name' => 'Reg Participant',
            'email' => $this->prefix . '-participant@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $this->organizer = $this->users->create([
            'name' => 'Reg Organizer',
            'email' => $this->prefix . '-organizer@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
        ]);
        $this->admin = $this->users->create([
            'name' => 'Reg Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
        $this->activeEvent = $this->events->create($this->eventPayload([
            'slug' => $this->prefix . '-event',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'Reg Organizer',
            'status' => 'active',
        ]));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $this->deleteRegistrationsForEvent((string) $this->activeEvent['id']);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testCreateRegistrationRequiresAuth(): void
    {
        $response = $this->request('POST', '/api/v1/registrations', $this->registrationPayload());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('unauthorized', $this->json($response)['error']['code']);
    }

    public function testCreateRegistrationSucceeds(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/registrations',
            $this->registrationPayload(),
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        $payload = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame($this->participant['id'], $payload['data']['userId']);
        self::assertSame($this->activeEvent['distances'][0]['id'], $payload['data']['distanceId']);
        self::assertSame('pending', $payload['data']['status']);
    }

    public function testInvalidDiscountCodeReturns422(): void
    {
        $payload = $this->registrationPayload();
        $payload['discountCode'] = 'NONEXISTENT';

        $response = $this->request(
            'POST',
            '/api/v1/registrations',
            $payload,
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        $json = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($json['success']);
        self::assertSame('discount_code_invalid', $json['error']['code']);
    }

    public function testGetUserRegistrationsRequiresAuth(): void
    {
        $response = $this->request('GET', '/api/v1/users/' . $this->participant['id'] . '/registrations');

        self::assertSame(401, $response->getStatusCode());
    }

    public function testGetUserRegistrationsReturnsOwnRegistrations(): void
    {
        $this->registrations->create($this->registrationPayload());

        $response = $this->request(
            'GET',
            '/api/v1/users/' . $this->participant['id'] . '/registrations',
            [],
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
        self::assertGreaterThanOrEqual(1, count($payload['data']));
    }

    public function testEventRegistrationsEndpointRequiresEventManagerRole(): void
    {
        $noAuth = $this->request('GET', '/api/v1/events/' . $this->activeEvent['id'] . '/registrations');
        self::assertSame(401, $noAuth->getStatusCode());

        $participantResp = $this->request(
            'GET',
            '/api/v1/events/' . $this->activeEvent['id'] . '/registrations',
            [],
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        self::assertSame(403, $participantResp->getStatusCode());

        $adminResp = $this->request(
            'GET',
            '/api/v1/events/' . $this->activeEvent['id'] . '/registrations',
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $adminResp->getStatusCode());
        self::assertTrue($this->json($adminResp)['success']);
    }

    public function testOrganizerCanAccessOwnEventRegistrations(): void
    {
        $response = $this->request(
            'GET',
            '/api/v1/events/' . $this->activeEvent['id'] . '/registrations',
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->json($response)['success']);
    }

    public function testPublicStartListIsAccessibleWithoutAuth(): void
    {
        $response = $this->request('GET', '/api/v1/events/' . $this->activeEvent['id'] . '/start-list');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
    }

    public function testCancelRegistrationRequiresAuth(): void
    {
        $reg = $this->registrations->create($this->registrationPayload());

        $response = $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/cancel');

        self::assertSame(401, $response->getStatusCode());
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'eventId' => $this->activeEvent['id'],
            'eventName' => $this->activeEvent['name'],
            'userId' => $this->participant['id'],
            'userName' => $this->participant['name'],
            'userEmail' => $this->participant['email'],
            'distanceId' => $this->activeEvent['distances'][0]['id'],
            'distanceName' => $this->activeEvent['distances'][0]['name'],
            'category' => 'Open',
            'merchandiseOrders' => [],
        ], $overrides);
    }

    private function deleteRegistrationsForEvent(string $eventId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
        $stmt->execute(['id' => $eventId]);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $regId) {
            $this->pdo->prepare('DELETE FROM registration_merchandise_orders WHERE registration_id = :id')->execute(['id' => $regId]);
            $this->pdo->prepare('DELETE FROM registration_payment_details WHERE registration_id = :id')->execute(['id' => $regId]);
        }
        $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $eventId]);
    }

    private function ensureEventStatusSchema(): void
    {
        $this->pdo->exec("ALTER TABLE events MODIFY status ENUM('draft','awaiting_approval','approved','active','past','archived','deleted') NOT NULL DEFAULT 'draft'");
    }

    private function eventPayload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? $this->prefix);

        return array_replace_recursive([
            'name' => 'PHPUnit Reg Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'Reg Organizer',
            'status' => 'active',
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

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * E8.11 ("Zewnetrzny formularz w iframe", contract §5.3): per-event iframe-embed
 * config — an organizer/admin-settable enable toggle + origin allowlist, written
 * through the existing generic PATCH /events/{id} path (same canManageEvent gate
 * as every other organizer-owns-event-or-admin setting), and read back through a
 * dedicated unauthenticated endpoint for the client's embed page.
 */
final class EventEmbedContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private string $prefix;
    private array $admin;
    private array $organizerA;
    private array $organizerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->prefix = 'embed-' . bin2hex(random_bytes(4));

        $this->admin = $this->users->create([
            'name' => 'Embed Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
        $this->organizerA = $this->users->create([
            'name' => 'Embed Organizer A',
            'email' => $this->prefix . '-orgA@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            'eventLimit' => 5,
        ]);
        $this->organizerB = $this->users->create([
            'name' => 'Embed Organizer B',
            'email' => $this->prefix . '-orgB@example.test',
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
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $id) {
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_payment_methods WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM activity_logs WHERE user_id IN (SELECT id FROM users WHERE email LIKE :email)')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testOrganizerCanSetEmbedSettingsOnOwnEvent(): void
    {
        $event = $this->events->create($this->eventPayload(['slug' => $this->prefix . '-own']));

        $response = $this->request(
            'PATCH',
            '/api/v1/events/' . $event['id'],
            [
                'embedEnabled' => true,
                'embedAllowedOrigins' => ['https://example.com', 'https://sub.example.org:8443'],
            ],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['data']['embedEnabled']);
        self::assertSame(['https://example.com', 'https://sub.example.org:8443'], $payload['data']['embedAllowedOrigins']);
    }

    public function testOrganizerCannotSetEmbedSettingsOnAnotherOrganizersEvent(): void
    {
        $event = $this->events->create($this->eventPayload(['slug' => $this->prefix . '-not-mine']));

        $response = $this->request(
            'PATCH',
            '/api/v1/events/' . $event['id'],
            ['embedEnabled' => true, 'embedAllowedOrigins' => ['https://example.com']],
            $this->bearer($this->organizerB['id'], 'organizer', $this->organizerB['email'])
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->json($response)['error']['code']);
    }

    public function testAdminCanSetEmbedSettingsOnAnyEvent(): void
    {
        $event = $this->events->create($this->eventPayload(['slug' => $this->prefix . '-admin-set']));

        $response = $this->request(
            'PATCH',
            '/api/v1/events/' . $event['id'],
            ['embedEnabled' => true, 'embedAllowedOrigins' => ['https://admin-allowed.test']],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['data']['embedEnabled']);
        self::assertSame(['https://admin-allowed.test'], $payload['data']['embedAllowedOrigins']);
    }

    public function testInvalidOriginStringsAreRejected(): void
    {
        $event = $this->events->create($this->eventPayload(['slug' => $this->prefix . '-invalid']));

        $cases = [
            'not-a-url',
            'ftp://example.com',
            'https://example.com/some/path',
            'https://example.com?query=1',
            'javascript:alert(1)',
        ];

        foreach ($cases as $badOrigin) {
            $response = $this->request(
                'PATCH',
                '/api/v1/events/' . $event['id'],
                ['embedAllowedOrigins' => [$badOrigin]],
                $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
            );

            self::assertSame(422, $response->getStatusCode(), 'Expected rejection for origin: ' . $badOrigin);
            self::assertSame('validation_error', $this->json($response)['error']['code']);
        }
    }

    public function testPublicEmbedStatusEndpointReturnsEnabledShapeForEnabledEvent(): void
    {
        $event = $this->events->create($this->eventPayload(['slug' => $this->prefix . '-public-enabled']));

        $this->request(
            'PATCH',
            '/api/v1/events/' . $event['id'],
            ['embedEnabled' => true, 'embedAllowedOrigins' => ['https://public-check.test']],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );

        $response = $this->request('GET', '/api/v1/events/' . $event['id'] . '/embed/public');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertTrue($payload['data']['enabled']);
        self::assertSame(['https://public-check.test'], $payload['data']['allowedOrigins']);
    }

    public function testPublicEmbedStatusEndpointReturnsDisabledShapeWithoutRequiringAuth(): void
    {
        $event = $this->events->create($this->eventPayload(['slug' => $this->prefix . '-public-disabled']));

        $response = $this->request('GET', '/api/v1/events/' . $event['id'] . '/embed/public');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($payload['data']['enabled']);
        self::assertSame([], $payload['data']['allowedOrigins']);
    }

    public function testPublicEmbedStatusEndpointReturns404ForNonexistentEvent(): void
    {
        $response = $this->request('GET', '/api/v1/events/does-not-exist/embed/public');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->json($response)['error']['code']);
    }

    public function testEmbedSettingsChangeIsLogged(): void
    {
        $event = $this->events->create($this->eventPayload(['slug' => $this->prefix . '-logged']));

        $this->request(
            'PATCH',
            '/api/v1/events/' . $event['id'],
            ['embedEnabled' => true, 'embedAllowedOrigins' => ['https://logged.test']],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );

        $logs = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'] . '/activity-logs',
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $payload = $this->json($logs);

        self::assertSame(200, $logs->getStatusCode());
        self::assertNotEmpty($payload['data']);
        self::assertSame($event['id'], $payload['data'][0]['targetId']);
    }

    private function eventPayload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? $this->prefix . '-default');

        return array_replace_recursive([
            'name' => 'PHPUnit Embed Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizerA['id'],
            'organizerName' => 'Embed Organizer A',
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

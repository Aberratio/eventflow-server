<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

final class ResultsContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private ResultRepository $results;
    private string $prefix;
    private array $organizer;
    private array $participant;
    private array $admin;
    private array $activeEvent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo);
        $this->results = new ResultRepository($this->pdo);
        $this->prefix = 'http-res-' . bin2hex(random_bytes(4));

        $this->ensureEventStatusSchema();

        $this->organizer = $this->users->create([
            'name' => 'Results Organizer',
            'email' => $this->prefix . '-org@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
        ]);
        $this->participant = $this->users->create([
            'name' => 'Results Participant',
            'email' => $this->prefix . '-part@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $this->admin = $this->users->create([
            'name' => 'Results Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
        $this->activeEvent = $this->events->create($this->eventPayload([
            'slug' => $this->prefix . '-event',
            'status' => 'active',
        ]));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $eventId = (string) $this->activeEvent['id'];
        $this->pdo->prepare('DELETE FROM results WHERE event_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testPublicResultsListIsAccessibleWithoutAuth(): void
    {
        $response = $this->request('GET', '/api/v1/results');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('data', $payload);
        self::assertArrayHasKey('total', $payload);
    }

    public function testEventResultsEndpointIsPublic(): void
    {
        $response = $this->request('GET', '/api/v1/events/' . $this->activeEvent['id'] . '/results');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
    }

    public function testCreateResultRequiresEventManagerRole(): void
    {
        $noAuth = $this->request('POST', '/api/v1/results', $this->resultPayload());
        self::assertSame(401, $noAuth->getStatusCode());

        $participantResp = $this->request(
            'POST',
            '/api/v1/results',
            $this->resultPayload(),
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        self::assertSame(403, $participantResp->getStatusCode());
    }

    public function testOrganizerCanCreateResultForOwnActiveEvent(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/results',
            $this->resultPayload(),
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        $payload = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame($this->participant['id'], $payload['data']['userId']);
        self::assertSame('01:00:00', $payload['data']['time']);
    }

    public function testOrganizerCannotCreateResultForAnotherOrganizersEvent(): void
    {
        $otherOrganizer = $this->users->create([
            'name' => 'Other Org',
            'email' => $this->prefix . '-other-org@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
        ]);

        $response = $this->request(
            'POST',
            '/api/v1/results',
            $this->resultPayload(),
            $this->bearer($otherOrganizer['id'], 'organizer', $otherOrganizer['email'])
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('result_event_not_open', $this->json($response)['error']['code']);
    }

    public function testAdminCanCreateAndDeleteResult(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/results',
            $this->resultPayload(),
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(201, $createResp->getStatusCode());

        $resultId = $this->json($createResp)['data']['id'];

        $deleteResp = $this->request(
            'DELETE',
            '/api/v1/results/' . $resultId,
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $deleteResp->getStatusCode());
        self::assertTrue($this->json($deleteResp)['success']);
    }

    public function testUserPersonalRecordsEndpointIsPublic(): void
    {
        $response = $this->request('GET', '/api/v1/users/' . $this->participant['id'] . '/personal-records');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
    }

    public function testUserResultsEndpointIsPublic(): void
    {
        $response = $this->request('GET', '/api/v1/users/' . $this->participant['id'] . '/results');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
    }

    private function resultPayload(array $overrides = []): array
    {
        return array_merge([
            'eventId' => $this->activeEvent['id'],
            'eventName' => $this->activeEvent['name'],
            'distanceId' => $this->activeEvent['distances'][0]['id'],
            'distanceName' => $this->activeEvent['distances'][0]['name'],
            'userId' => $this->participant['id'],
            'userName' => $this->participant['name'],
            'bibNumber' => '1',
            'category' => 'Open',
            'time' => '01:00:00',
            'timeInSeconds' => 3600,
            'position' => 1,
            'categoryPosition' => 1,
            'status' => 'finished',
        ], $overrides);
    }

    /**
     * E1.1 dropped the free-text `status` column entirely (see
     * 031_e11_event_lifecycle.sql), so there is no ENUM to patch in place any more — the
     * schema this test needs only exists once every migration has actually run. Calling
     * the real Migrator (idempotent, no ledger — see its docblock) is the only thing that
     * still makes sense here.
     */
    private function ensureEventStatusSchema(): void
    {
        \ZmierzymyCzas\Database\Migrator::applyAll($this->pdo);
    }

    private function eventPayload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? $this->prefix);

        return array_replace_recursive([
            'name' => 'PHPUnit Results Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'Results Organizer',
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

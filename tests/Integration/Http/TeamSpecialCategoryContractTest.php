<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Teams\TeamRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * Captain's manual declaration of the Kategoria specjalna ("Kategoria specjalna: deklaracja ręczna
 * kapitana") — the HTTP contract for PATCH /teams/{teamId}/special-category,
 * the route a captain uses to change a previously declared value (creation
 * itself goes through RegistrationRepository::create(), covered by
 * RegistrationTeamTest). Exercises the permission model (only
 * created_by_user_id) and the validation (only a configured category name)
 * through the real route/middleware stack, not just TeamRepository directly.
 */
final class TeamSpecialCategoryContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private TeamRepository $teams;
    private string $prefix;
    private array $captain;
    private array $stranger;
    private array $event;

    protected function setUp(): void
    {
        parent::setUp();

        \ZmierzymyCzas\Database\Migrator::applyAll($this->pdo);

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->teams = new TeamRepository($this->pdo);
        $this->prefix = 'http-teamcat-' . bin2hex(random_bytes(4));

        $this->captain = $this->users->create([
            'name' => 'Kapitan',
            'email' => $this->prefix . '-captain@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $this->stranger = $this->users->create([
            'name' => 'Ktos Inny',
            'email' => $this->prefix . '-stranger@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);

        $this->event = $this->events->create([
            'name' => 'PHPUnit Team Category Event',
            'slug' => $this->prefix . '-event',
            'discipline' => 'running',
            'date' => '2026-12-31',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->captain['id'],
            'organizerName' => 'Kapitan',
            'status' => 'active',
            'registrationDeadline' => '2026-12-01',
            'featured' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $this->prefix . '-relay',
                'name' => 'Sztafeta 4x400m',
                'length' => 1.6,
                'unit' => 'km',
                'categories' => ['Open'],
                'ageCategories' => [],
                'specialCategories' => [
                    ['id' => 'mixed', 'name' => 'Drużyna mieszana'],
                ],
                'limit' => 100,
                'registeredCount' => 0,
                'price' => 0,
                'routeFiles' => [],
                'startType' => 'relay',
                'relayMinTeamSize' => 2,
                'relayMaxTeamSize' => 4,
            ]],
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $eventId = (string) $this->event['id'];
        $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM teams WHERE event_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM event_distance_pricing_variants WHERE distance_id IN (SELECT id FROM event_distances WHERE event_id = :id)')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    private function createTeam(): array
    {
        return $this->teams->create([
            'eventId' => (string) $this->event['id'],
            'distanceId' => (string) $this->event['distances'][0]['id'],
            'name' => 'Drużyna Testowa',
            'createdByUserId' => $this->captain['id'],
        ]);
    }

    public function testCaptainCanSetASpecialCategory(): void
    {
        $team = $this->createTeam();

        $response = $this->request(
            'PATCH',
            '/api/v1/teams/' . $team['id'] . '/special-category',
            ['specialCategory' => 'Drużyna mieszana'],
            $this->bearer($this->captain['id'], 'user')
        );
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame('Drużyna mieszana', $payload['data']['specialCategory']);
    }

    public function testSomeoneWhoIsNotTheCaptainIsRejected(): void
    {
        $team = $this->createTeam();

        $response = $this->request(
            'PATCH',
            '/api/v1/teams/' . $team['id'] . '/special-category',
            ['specialCategory' => 'Drużyna mieszana'],
            $this->bearer($this->stranger['id'], 'user')
        );
        $payload = $this->json($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('team_not_captain', $payload['error']['code']);
    }

    public function testAnUnconfiguredCategoryIsRejected(): void
    {
        $team = $this->createTeam();

        $response = $this->request(
            'PATCH',
            '/api/v1/teams/' . $team['id'] . '/special-category',
            ['specialCategory' => 'Nie na liście'],
            $this->bearer($this->captain['id'], 'user')
        );
        $payload = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('team_special_category_invalid', $payload['error']['code']);
    }

    public function testAnUnauthenticatedRequestIsRejected(): void
    {
        $team = $this->createTeam();

        $response = $this->request(
            'PATCH',
            '/api/v1/teams/' . $team['id'] . '/special-category',
            ['specialCategory' => 'Drużyna mieszana']
        );

        self::assertSame(401, $response->getStatusCode());
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Teams\TeamRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * E7.2 (issue 23 Answer §1, "link do zaproszenia dla pozostałych miejsc"):
 * GET /teams/{teamId} is the read side of a captain's shareable invitation
 * link (`/events/{eventSlug}/register?joinTeam={teamId}`) — public,
 * unauthenticated, returns the same shape as the sibling
 * GET /events/{eventId}/distances/{distanceId}/teams list entries (derived
 * completeness included) so the client can resolve a link straight to its
 * event/distance and show "team full"/"not found" before the invitee even
 * opens the registration form. The actual join is still fully re-validated
 * by RegistrationRepository::resolveTeamForRegistration() at submit time
 * (RegistrationTeamTest) — this endpoint duplicates none of that logic, it
 * only exposes TeamRepository::find().
 */
final class TeamInviteContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private TeamRepository $teams;
    private string $prefix;
    private array $organizer;
    private array $event;

    protected function setUp(): void
    {
        parent::setUp();

        \ZmierzymyCzas\Database\Migrator::applyAll($this->pdo);

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->teams = new TeamRepository($this->pdo);
        $this->prefix = 'http-invite-' . bin2hex(random_bytes(4));

        $this->organizer = $this->users->create([
            'name' => 'Team Invite Organizer',
            'email' => $this->prefix . '-org@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
        ]);

        $this->event = $this->events->create([
            'name' => 'PHPUnit Invite Event',
            'slug' => $this->prefix . '-event',
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'Team Invite Organizer',
            'status' => 'active',
            'registrationDeadline' => '2026-09-01',
            'featured' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $this->prefix . '-relay',
                'name' => 'Sztafeta 4x400m',
                'length' => 1.6,
                'unit' => 'km',
                'categories' => ['Open'],
                'ageCategories' => [],
                'specialCategories' => [],
                'limit' => 100,
                'registeredCount' => 0,
                'price' => 80,
                'routeFiles' => [],
                'startType' => 'relay',
                'relayMinTeamSize' => 2,
                'relayMaxTeamSize' => 4,
                'teamAggregationRule' => 'sum',
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

    public function testTeamInviteEndpointIsPublicAndReturnsCompletenessForAnIncompleteTeam(): void
    {
        $distanceId = (string) $this->event['distances'][0]['id'];
        $eventId = (string) $this->event['id'];
        $team = $this->teams->create([
            'eventId' => $eventId,
            'distanceId' => $distanceId,
            'name' => 'Drużyna Kapitana',
            'createdByUserId' => $this->organizer['id'],
        ]);

        $response = $this->request('GET', '/api/v1/teams/' . $team['id']);
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame($team['id'], $payload['data']['id']);
        self::assertSame($eventId, $payload['data']['eventId']);
        self::assertSame($distanceId, $payload['data']['distanceId']);
        self::assertSame(0, $payload['data']['memberCount']);
        self::assertSame(4, $payload['data']['maxSize']);
        self::assertFalse($payload['data']['complete']);
    }

    public function testTeamInviteEndpointReturns404ForAnUnknownTeamId(): void
    {
        $response = $this->request('GET', '/api/v1/teams/' . $this->prefix . '-does-not-exist');
        $payload = $this->json($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('team_not_found', $payload['error']['code']);
    }

    public function testTeamInviteEndpointReflectsAFullTeamAsComplete(): void
    {
        $distanceId = (string) $this->event['distances'][0]['id'];
        $eventId = (string) $this->event['id'];
        $team = $this->teams->create([
            'eventId' => $eventId,
            'distanceId' => $distanceId,
            'name' => 'Drużyna Pełna',
            'createdByUserId' => $this->organizer['id'],
        ]);

        for ($i = 0; $i < 4; $i++) {
            $id = $this->prefix . '-reg-' . bin2hex(random_bytes(4));
            $this->pdo->prepare(
                'INSERT INTO registrations (id, event_id, event_name, user_id, user_name, user_email, distance_id, distance_name, category, registered_at, status, team_id, team_name, qr_code)
                 VALUES (:id, :eventId, :eventName, :userId, :userName, :userEmail, :distanceId, :distanceName, :category, :registeredAt, :status, :teamId, :teamName, :qrCode)'
            )->execute([
                'id' => $id,
                'eventId' => $eventId,
                'eventName' => $this->event['name'],
                'userId' => $id . '-user',
                'userName' => 'Członek ' . $i,
                'userEmail' => $id . '@example.test',
                'distanceId' => $distanceId,
                'distanceName' => $this->event['distances'][0]['name'],
                'category' => 'Open',
                'registeredAt' => date(DATE_ATOM),
                'status' => 'confirmed',
                'teamId' => $team['id'],
                'teamName' => $team['name'],
                'qrCode' => 'phpinvite_' . bin2hex(random_bytes(16)),
            ]);
        }

        $response = $this->request('GET', '/api/v1/teams/' . $team['id']);
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(4, $payload['data']['memberCount']);
        self::assertTrue($payload['data']['complete']);
    }
}

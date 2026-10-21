<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * A Zapis joins an existing Drużyna only through the captain's
 * invitation token, or when the captain themself registers (captain consent).
 * Team ids are public (GET /events/{e}/distances/{d}/teams), so knowing one must
 * never be enough.
 */
final class TeamJoinContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private string $prefix;
    private array $captain;
    private array $stranger;
    private array $invitee;
    private array $organizer;
    private array $event;
    private string $relayDistanceId;
    private string $individualDistanceId;
    private int $userSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->prefix = 'http-teamjoin-' . bin2hex(random_bytes(4));

        $this->captain = $this->makeUser('captain', 'participant');
        $this->stranger = $this->makeUser('stranger', 'participant');
        $this->invitee = $this->makeUser('invitee', 'participant');
        $this->organizer = $this->makeUser('organizer', 'organizer');

        $slug = $this->prefix . '-event';
        $this->relayDistanceId = $slug . '-relay';
        $this->individualDistanceId = $slug . '-5k';
        $this->event = $this->events->create([
            'name' => 'PHPUnit TeamJoin Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+90 days')),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'TeamJoin Organizer',
            'status' => 'active',
            'registrationDeadline' => date('Y-m-d', strtotime('+60 days')),
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [
                [
                    'id' => $this->relayDistanceId,
                    'name' => 'Sztafeta 4x400m',
                    'length' => 1.6,
                    'unit' => 'km',
                    'categories' => ['Open'],
                    'ageCategories' => [],
                    'specialCategories' => [],
                    'limit' => 100,
                    'registeredCount' => 0,
                    'price' => 100,
                    'routeFiles' => [],
                    'startType' => 'relay',
                    'relayMinTeamSize' => 2,
                    'relayMaxTeamSize' => 4,
                    'teamPricingMode' => 'per_team',
                ],
                [
                    'id' => $this->individualDistanceId,
                    'name' => '5 km',
                    'length' => 5,
                    'unit' => 'km',
                    'categories' => ['Open'],
                    'ageCategories' => [],
                    'specialCategories' => [],
                    'limit' => 100,
                    'registeredCount' => 0,
                    'price' => 0,
                    'routeFiles' => [],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix, $this->event)) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
        $stmt->execute(['id' => $this->event['id']]);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $regId) {
            foreach (['registration_merchandise_orders', 'registration_payment_details', 'registration_settlement_items', 'registration_refunds', 'registration_gateway_transactions'] as $table) {
                $this->pdo->prepare("DELETE FROM {$table} WHERE registration_id = :id")->execute(['id' => $regId]);
            }
        }
        $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM teams WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare(
            'DELETE cr FROM care_relations cr JOIN users u ON u.id = cr.konto_id WHERE u.email LIKE :email'
        )->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare(
            'DELETE cr FROM care_relations cr JOIN competitors c ON c.id = cr.zawodnik_id WHERE c.last_name = :last'
        )->execute(['last' => $this->prefix]);
        $this->pdo->prepare(
            'DELETE c FROM competitors c JOIN users u ON u.id = c.user_id WHERE u.email LIKE :email'
        )->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM competitors WHERE last_name = :last')->execute(['last' => $this->prefix]);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testStrangerWithKnownTeamIdIsForbidden(): void
    {
        $team = $this->createTeamAs($this->captain, 'Kapitanowie');

        $response = $this->joinAs($this->stranger, $team['teamId'], null);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('registration_team_invite_required', $this->json($response)['error']['code']);
        self::assertSame(1, $this->countTeamMembers($team['teamId']));
        self::assertSame(0, $this->countRegistrationsOf($this->stranger['id']));
    }

    public function testStrangerWithWrongTokenIsForbidden(): void
    {
        $team = $this->createTeamAs($this->captain, 'Kapitanowie');

        $response = $this->joinAs($this->stranger, $team['teamId'], str_repeat('a', 48));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('registration_team_invite_required', $this->json($response)['error']['code']);
        self::assertSame(1, $this->countTeamMembers($team['teamId']));
        self::assertSame(0, $this->countRegistrationsOf($this->stranger['id']));
    }

    public function testTokenOfAnotherTeamIsForbidden(): void
    {
        $teamA = $this->createTeamAs($this->captain, 'Team A');
        $otherCaptain = $this->makeUser('captain2', 'participant');
        $teamB = $this->createTeamAs($otherCaptain, 'Team B');
        $tokenB = $this->tokenOf($otherCaptain, $teamB['teamId']);

        $response = $this->joinAs($this->stranger, $teamA['teamId'], $tokenB);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('registration_team_invite_required', $this->json($response)['error']['code']);
        self::assertSame(1, $this->countTeamMembers($teamA['teamId']));
    }

    public function testBodyActorUserIdDoesNotGrantCaptainConsent(): void
    {
        $team = $this->createTeamAs($this->captain, 'Kapitanowie');

        $response = $this->joinAs($this->stranger, $team['teamId'], null, ['actorUserId' => $this->captain['id']]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('registration_team_invite_required', $this->json($response)['error']['code']);
        self::assertSame(1, $this->countTeamMembers($team['teamId']));
    }

    public function testInviteeWithValidTokenJoinsAndOwesNothingOnPerTeam(): void
    {
        $team = $this->createTeamAs($this->captain, 'Kapitanowie');
        $token = $this->tokenOf($this->captain, $team['teamId']);

        $response = $this->joinAs($this->invitee, $team['teamId'], $token);
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($team['teamId'], $data['teamId']);
        self::assertSame('Kapitanowie', $data['teamName']);
        self::assertSame('confirmed', $data['status']);
        self::assertEquals(0.00, (float) $data['totalAmount']);
        self::assertSame(2, $this->countTeamMembers($team['teamId']));
    }

    public function testCaptainRegistersWardIntoOwnTeamWithoutToken(): void
    {
        $team = $this->createTeamAs($this->captain, 'Kapitanowie');
        $child = $this->json($this->request(
            'POST',
            '/api/v1/care',
            ['firstName' => 'Dziecko', 'lastName' => $this->prefix, 'birthDate' => '2015-05-05'],
            $this->bearer($this->captain['id'], 'participant', $this->captain['email'])
        ))['data']['competitor'];

        $response = $this->joinAs($this->captain, $team['teamId'], null, ['competitorId' => $child['id']]);
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($team['teamId'], $data['teamId']);
        self::assertSame($child['id'], $data['competitorId']);
        self::assertSame(2, $this->countTeamMembers($team['teamId']));
    }

    public function testCreateTeamCaptainPathUnchanged(): void
    {
        $data = $this->createTeamAs($this->captain, 'Kapitanowie');

        self::assertSame('pending', $data['status']);
        self::assertEquals(100.00, (float) $data['totalAmount']);
        $stmt = $this->pdo->prepare('SELECT invite_token, created_by_user_id FROM teams WHERE id = :id');
        $stmt->execute(['id' => $data['teamId']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/', (string) $row['invite_token']);
        self::assertSame($this->captain['id'], $row['created_by_user_id']);
    }

    public function testInviteEndpointAccessMatrix(): void
    {
        $team = $this->createTeamAs($this->captain, 'Kapitanowie');
        $path = '/api/v1/teams/' . $team['teamId'] . '/invite';

        self::assertSame(401, $this->request('GET', $path)->getStatusCode());

        $unknown = $this->as($this->captain, 'GET', '/api/v1/teams/does-not-exist/invite');
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame('team_not_found', $this->json($unknown)['error']['code']);

        $denied = $this->as($this->stranger, 'GET', $path);
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('team_not_captain', $this->json($denied)['error']['code']);

        $captainResponse = $this->as($this->captain, 'GET', $path);
        self::assertSame(200, $captainResponse->getStatusCode());
        $captainData = $this->json($captainResponse)['data'];
        self::assertSame($team['teamId'], $captainData['teamId']);
        self::assertSame($this->event['id'], $captainData['eventId']);
        self::assertSame($this->relayDistanceId, $captainData['distanceId']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $captainData['inviteToken']);

        $organizerResponse = $this->as($this->organizer, 'GET', $path);
        self::assertSame(200, $organizerResponse->getStatusCode());
        self::assertSame($captainData['inviteToken'], $this->json($organizerResponse)['data']['inviteToken']);
    }

    public function testInviteTokenIsLazilyMintedForLegacyTeam(): void
    {
        $team = $this->createTeamAs($this->captain, 'Kapitanowie');
        $this->pdo->prepare('UPDATE teams SET invite_token = NULL WHERE id = :id')->execute(['id' => $team['teamId']]);

        $first = $this->tokenOf($this->captain, $team['teamId']);
        $second = $this->tokenOf($this->captain, $team['teamId']);

        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $first);
        self::assertSame($first, $second);
        $response = $this->joinAs($this->invitee, $team['teamId'], $first);
        self::assertSame(201, $response->getStatusCode());
    }

    public function testPublicTeamReadsNeverExposeTheToken(): void
    {
        $team = $this->createTeamAs($this->captain, 'Kapitanowie');
        $token = $this->tokenOf($this->captain, $team['teamId']);

        $single = $this->request('GET', '/api/v1/teams/' . $team['teamId']);
        $list = $this->request(
            'GET',
            '/api/v1/events/' . $this->event['id'] . '/distances/' . $this->relayDistanceId . '/teams'
        );

        foreach ([$single, $list] as $response) {
            self::assertSame(200, $response->getStatusCode());
            $raw = (string) $response->getBody();
            self::assertStringNotContainsString($token, $raw);
            self::assertStringNotContainsString('inviteToken', $raw);
            self::assertStringNotContainsString('invite_token', $raw);
        }
    }

    public function testJoinErrorOrderingWithValidToken(): void
    {
        $unknown = $this->joinAs($this->stranger, 'does-not-exist', 'whatever');
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame('registration_team_not_found', $this->json($unknown)['error']['code']);

        $team = $this->createTeamAs($this->captain, 'Kapitanowie');
        $token = $this->tokenOf($this->captain, $team['teamId']);

        $mismatch = $this->joinAs($this->stranger, $team['teamId'], $token, ['distanceId' => $this->individualDistanceId]);
        self::assertSame(422, $mismatch->getStatusCode());
        self::assertSame('registration_team_distance_mismatch', $this->json($mismatch)['error']['code']);

        for ($i = 0; $i < 3; $i++) {
            $ok = $this->joinAs($this->makeUser('m' . $i, 'participant'), $team['teamId'], $token);
            self::assertSame(201, $ok->getStatusCode());
        }
        self::assertSame(4, $this->countTeamMembers($team['teamId']));

        $full = $this->joinAs($this->invitee, $team['teamId'], $token);
        self::assertSame(409, $full->getStatusCode());
        self::assertSame('registration_team_full', $this->json($full)['error']['code']);
    }

    public function testLegacyFreeTextTeamNameUnaffected(): void
    {
        $response = $this->post($this->stranger, $this->payload([
            'distanceId' => $this->individualDistanceId,
            'teamName' => 'Klub X',
        ]));
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertNull($data['teamId']);
        self::assertSame('Klub X', $data['teamName']);
    }

    /** @return array<string, mixed> the created Zapis (with its teamId) */
    private function createTeamAs(array $user, string $name): array
    {
        $response = $this->post($user, $this->payload(['createTeam' => true, 'teamName' => $name]));
        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertNotEmpty($data['teamId']);

        return $data;
    }

    private function tokenOf(array $user, string $teamId): string
    {
        $response = $this->as($user, 'GET', '/api/v1/teams/' . $teamId . '/invite');
        self::assertSame(200, $response->getStatusCode());

        return (string) $this->json($response)['data']['inviteToken'];
    }

    /** @param array<string, mixed> $extra */
    private function joinAs(array $user, string $teamId, ?string $token, array $extra = []): ResponseInterface
    {
        $overrides = ['teamId' => $teamId] + $extra;
        if ($token !== null) {
            $overrides['teamInviteToken'] = $token;
        }

        return $this->post($user, $this->payload($overrides));
    }

    private function countTeamMembers(string $teamId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM registrations WHERE team_id = :id');
        $stmt->execute(['id' => $teamId]);
        return (int) $stmt->fetchColumn();
    }

    private function countRegistrationsOf(string $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM registrations WHERE event_id = :event AND user_id = :u');
        $stmt->execute(['event' => $this->event['id'], 'u' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function makeUser(string $slug, string $role): array
    {
        return $this->users->create([
            'name' => 'TeamJoin ' . ucfirst($slug) . ' ' . (++$this->userSeq),
            'email' => $this->prefix . '-' . $slug . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ]);
    }

    private function as(array $user, string $method, string $path): ResponseInterface
    {
        return $this->request($method, $path, [], $this->bearer($user['id'], (string) $user['role'], $user['email']));
    }

    private function post(array $caller, array $payload): ResponseInterface
    {
        return $this->request('POST', '/api/v1/registrations', $payload, $this->bearer($caller['id'], (string) $caller['role'], $caller['email']));
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'eventId' => $this->event['id'],
            'distanceId' => $this->relayDistanceId,
            'category' => 'Open',
            'merchandiseOrders' => [],
        ], $overrides);
    }
}

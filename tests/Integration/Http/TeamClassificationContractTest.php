<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * GET /events/{eventId}/distances/{distanceId}/team-results
 * is the new live-computed team/relay classification endpoint - public/
 * unauthenticated, mirroring GET /events/{eventId}/results (see
 * ResultsContractTest::testEventResultsEndpointIsPublic()).
 */
final class TeamClassificationContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private ResultRepository $results;
    private string $prefix;
    private array $organizer;
    private array $event;

    protected function setUp(): void
    {
        parent::setUp();

        \ZmierzymyCzas\Database\Migrator::applyAll($this->pdo);

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->results = new ResultRepository($this->pdo);
        $this->prefix = 'http-team-' . bin2hex(random_bytes(4));

        $this->organizer = $this->users->create([
            'name' => 'Team Classification Organizer',
            'email' => $this->prefix . '-org@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
        ]);

        $this->event = $this->events->create([
            'name' => 'PHPUnit Team Event',
            'slug' => $this->prefix . '-event',
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'Team Classification Organizer',
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
        $this->pdo->prepare('DELETE FROM results WHERE event_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM event_distance_pricing_variants WHERE distance_id IN (SELECT id FROM event_distances WHERE event_id = :id)')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testTeamAggregationRuleRoundTripsThroughEventCreation(): void
    {
        self::assertSame('relay', $this->event['distances'][0]['startType']);
        self::assertSame('sum', $this->event['distances'][0]['teamAggregationRule']);
    }

    public function testTeamClassificationEndpointIsPublicAndRanksCompleteTeamsByAggregatedTime(): void
    {
        $distanceId = (string) $this->event['distances'][0]['id'];
        $eventId = (string) $this->event['id'];

        // `registrations.team_id` is now FK-constrained to a real
        // `teams` row — createTeamMember()/createRegistrationOnly() below
        // insert straight into `registrations` (bypassing
        // RegistrationRepository::create()'s own team validation, which this
        // contract test doesn't exercise), so the row it points at must be
        // created for real first via createTeam().
        $teamA = $this->createTeam($eventId, $distanceId, 'Drużyna A');
        $teamB = $this->createTeam($eventId, $distanceId, 'Drużyna B');

        $this->createTeamMember($eventId, $distanceId, $teamA, 'Drużyna A', '0:10:00', 600, 'finished');
        $this->createTeamMember($eventId, $distanceId, $teamA, 'Drużyna A', '0:11:00', 660, 'finished');
        $this->createTeamMember($eventId, $distanceId, $teamB, 'Drużyna B', '0:09:00', 540, 'finished');
        $this->createTeamMember($eventId, $distanceId, $teamB, 'Drużyna B', '0:09:30', 570, 'finished');

        $response = $this->request('GET', "/api/v1/events/{$eventId}/distances/{$distanceId}/team-results");
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertCount(2, $payload['data']);
        // Team B: 540 + 570 = 1110s, faster than Team A's 600 + 660 = 1260s.
        self::assertSame($teamB, $payload['data'][0]['teamId']);
        self::assertSame(1, $payload['data'][0]['position']);
        self::assertEqualsWithDelta(1110.0, $payload['data'][0]['aggregatedTimeInSeconds'], 0.001);
        self::assertSame($teamA, $payload['data'][1]['teamId']);
        self::assertSame(2, $payload['data'][1]['position']);
    }

    public function testPartialTeamIsExcludedUntilEveryMemberHasAFinishedResult(): void
    {
        $distanceId = (string) $this->event['distances'][0]['id'];
        $eventId = (string) $this->event['id'];

        // Team with one finished + one still without any result at all.
        $teamPartial = $this->createTeam($eventId, $distanceId, 'Drużyna Niekompletna');
        $this->createTeamMember($eventId, $distanceId, $teamPartial, 'Drużyna Niekompletna', '0:10:00', 600, 'finished');
        $this->createRegistrationOnly($eventId, $distanceId, $teamPartial, 'Drużyna Niekompletna');

        // Complete team, should still show up.
        $teamComplete = $this->createTeam($eventId, $distanceId, 'Drużyna Kompletna');
        $this->createTeamMember($eventId, $distanceId, $teamComplete, 'Drużyna Kompletna', '0:09:00', 540, 'finished');
        $this->createTeamMember($eventId, $distanceId, $teamComplete, 'Drużyna Kompletna', '0:09:30', 570, 'finished');

        $response = $this->request('GET', "/api/v1/events/{$eventId}/distances/{$distanceId}/team-results");
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $teamIds = array_column($payload['data'], 'teamId');
        self::assertContains($teamComplete, $teamIds);
        self::assertNotContains($teamPartial, $teamIds, 'A team missing a member result must be excluded, not shown partially.');
    }

    public function testTeamWithADnfMemberIsExcludedButTheMembersIndividualResultIsUnaffected(): void
    {
        $distanceId = (string) $this->event['distances'][0]['id'];
        $eventId = (string) $this->event['id'];

        $teamDnf = $this->createTeam($eventId, $distanceId, 'Drużyna DNF');
        $this->createTeamMember($eventId, $distanceId, $teamDnf, 'Drużyna DNF', '0:10:00', 600, 'finished');
        $dnfResult = $this->createTeamMember($eventId, $distanceId, $teamDnf, 'Drużyna DNF', '', 0, 'dnf');

        $response = $this->request('GET', "/api/v1/events/{$eventId}/distances/{$distanceId}/team-results");
        $payload = $this->json($response);

        $teamIds = array_column($payload['data'], 'teamId');
        self::assertNotContains($teamDnf, $teamIds, 'A dnf member must disqualify the team aggregate.');

        $individual = $this->results->find((string) $dnfResult['id']);
        self::assertSame('dnf', $individual['status'], "The member's own individual result must be untouched by team exclusion.");
    }

    /** Creates a real `teams` row, returning its server-generated id. */
    private function createTeam(string $eventId, string $distanceId, string $name): string
    {
        $id = $this->prefix . '-team-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO teams (id, event_id, distance_id, name, created_at) VALUES (:id, :eventId, :distanceId, :name, :createdAt)'
        )->execute([
            'id' => $id,
            'eventId' => $eventId,
            'distanceId' => $distanceId,
            'name' => $name,
            'createdAt' => date(DATE_ATOM),
        ]);

        return $id;
    }

    /** @return array<string, mixed> the created Result */
    private function createTeamMember(string $eventId, string $distanceId, string $teamId, string $teamName, string $time, float $timeInSeconds, string $status): array
    {
        $registrationId = $this->createRegistrationOnly($eventId, $distanceId, $teamId, $teamName);

        return $this->results->create([
            'eventId' => $eventId,
            'eventName' => $this->event['name'],
            'distanceId' => $distanceId,
            'distanceName' => $this->event['distances'][0]['name'],
            'registrationId' => $registrationId,
            'userId' => $registrationId,
            'userName' => $teamName . ' zawodnik',
            'bibNumber' => substr(bin2hex(random_bytes(3)), 0, 5),
            'category' => 'Open',
            'time' => $time,
            'timeInSeconds' => $timeInSeconds,
            'position' => 0,
            'categoryPosition' => 0,
            'status' => $status,
        ]);
    }

    private function createRegistrationOnly(string $eventId, string $distanceId, string $teamId, string $teamName): string
    {
        $id = $this->prefix . '-reg-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO registrations (id, event_id, event_name, user_id, user_name, user_email, distance_id, distance_name, category, registered_at, status, team_id, team_name, qr_code)
             VALUES (:id, :eventId, :eventName, :userId, :userName, :userEmail, :distanceId, :distanceName, :category, :registeredAt, :status, :teamId, :teamName, :qrCode)'
        )->execute([
            'id' => $id,
            'eventId' => $eventId,
            'eventName' => $this->event['name'],
            'userId' => $id . '-user',
            'userName' => $teamName . ' zawodnik',
            'userEmail' => $id . '@example.test',
            'distanceId' => $distanceId,
            'distanceName' => $this->event['distances'][0]['name'],
            'category' => 'Open',
            'registeredAt' => date(DATE_ATOM),
            'status' => 'confirmed',
            'teamId' => $teamId,
            'teamName' => $teamName,
            'qrCode' => 'phpteam_' . bin2hex(random_bytes(16)),
        ]);

        return $id;
    }
}

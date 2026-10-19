<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * G-04 (spec 23 Answer §2, spec 20 "Transfer", spec 11 §4): wymiana zawodnika w składzie
 * Drużyny sztafetowej = podmiana Zawodnika na istniejącym Zapisie. Cena (Rozliczenie) i numer
 * startowy zostają, kod startowy (`pqr_`) rotuje, kapitan wymienia do osobnego terminu, po nim
 * tylko organizator/Zespół Wydarzenia, a każda wymiana zostawia wpis w rejestrze aktywności.
 */
final class TeamMemberSwapContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private string $prefix;
    private array $captain;
    private array $member;
    private array $stranger;
    private array $organizer;
    private array $event;
    private string $relayDistanceId;
    private int $userSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->prefix = 'http-swap-' . bin2hex(random_bytes(4));

        $this->captain = $this->makeUser('captain', 'participant');
        $this->member = $this->makeUser('member', 'participant');
        $this->stranger = $this->makeUser('stranger', 'participant');
        $this->organizer = $this->makeUser('organizer', 'organizer');

        $slug = $this->prefix . '-event';
        $this->relayDistanceId = $slug . '-relay';
        $this->event = $this->events->create([
            'name' => 'PHPUnit Swap Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+30 days')),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'Swap Organizer',
            'status' => 'active',
            'registrationDeadline' => date('Y-m-d', strtotime('+20 days')),
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
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
                'teamPricingMode' => 'per_person',
                'relaySwapDeadline' => date('Y-m-d', strtotime('+10 days')),
            ]],
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
            foreach (['registration_merchandise_orders', 'registration_payment_details', 'registration_settlement_items', 'registration_refunds', 'registration_gateway_transactions', 'form_field_answers'] as $table) {
                $this->pdo->prepare("DELETE FROM {$table} WHERE registration_id = :id")->execute(['id' => $regId]);
            }
            $this->pdo->prepare('DELETE FROM activity_logs WHERE target_id = :id')->execute(['id' => $regId]);
        }
        $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM teams WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare(
            'DELETE cr FROM care_relations cr JOIN users u ON u.id = cr.konto_id WHERE u.email LIKE :email'
        )->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM competitors WHERE last_name = :last')->execute(['last' => $this->prefix]);
        $this->pdo->prepare(
            'DELETE c FROM competitors c JOIN users u ON u.id = c.user_id WHERE u.email LIKE :email'
        )->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testCaptainSwapsMemberForANewPersonKeepingPriceAndBibAndRotatingTheStartCode(): void
    {
        [$teamId, $memberRegId] = $this->teamWithMember();
        $this->pdo->prepare('UPDATE registrations SET bib_number = :bib WHERE id = :id')->execute(['bib' => '17', 'id' => $memberRegId]);
        $before = $this->registrationRow($memberRegId);
        $itemsBefore = $this->settlementSnapshot($memberRegId);

        $response = $this->swap($this->captain, $teamId, $memberRegId, ['newPerson' => [
            'firstName' => 'Nowy',
            'lastName' => $this->prefix,
            'birthDate' => '1990-04-04',
            'gender' => 'M',
        ]]);
        $data = $this->json($response)['data'] ?? [];

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $after = $this->registrationRow($memberRegId);
        self::assertSame($teamId, $after['team_id']);
        self::assertNotSame($before['competitor_id'], $after['competitor_id']);
        self::assertSame('Nowy ' . $this->prefix, $after['user_name']);
        self::assertSame($after['competitor_id'], $data['competitorId']);
        // Kod startowy rotuje, format BZ bez zmian.
        self::assertNotSame($before['qr_code'], $after['qr_code']);
        self::assertMatchesRegularExpression('/^pqr_[a-f0-9]{48}$/', (string) $after['qr_code']);
        // Numer startowy, status i Rozliczenie zostają przy Zapisie.
        self::assertSame('17', $after['bib_number']);
        self::assertSame($before['status'], $after['status']);
        self::assertSame($itemsBefore, $this->settlementSnapshot($memberRegId));
        // BZ: pull widzi zmianę dzięki znacznikowi updated_at (spec 12 LWW).
        self::assertNotNull($after['updated_at']);
    }

    public function testSwapLeavesAnActivityLogEntryWithWhoWhenFromAndTo(): void
    {
        [$teamId, $memberRegId] = $this->teamWithMember();
        $oldName = (string) $this->registrationRow($memberRegId)['user_name'];

        $this->swap($this->captain, $teamId, $memberRegId, ['newPerson' => ['firstName' => 'Nowy', 'lastName' => $this->prefix]]);

        $stmt = $this->pdo->prepare("SELECT user_id, action, metadata, timestamp FROM activity_logs WHERE target_id = :id AND action = 'team_member_swapped'");
        $stmt->execute(['id' => $memberRegId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame($this->captain['id'], $rows[0]['user_id']);
        $meta = json_decode((string) $rows[0]['metadata'], true);
        self::assertSame($oldName, $meta['from']['name']);
        self::assertSame('Nowy ' . $this->prefix, $meta['to']['name']);
        self::assertNotEmpty($meta['to']['competitorId']);
        self::assertNotEmpty($rows[0]['timestamp']);
    }

    public function testCaptainSwapsToAnExistingZawodnikUnderOwnCare(): void
    {
        [$teamId, $memberRegId] = $this->teamWithMember();
        $child = $this->json($this->request(
            'POST',
            '/api/v1/care',
            ['firstName' => 'Dziecko', 'lastName' => $this->prefix, 'birthDate' => '2012-05-05'],
            $this->bearer($this->captain['id'], 'participant', $this->captain['email'])
        ))['data']['competitor'];

        $response = $this->swap($this->captain, $teamId, $memberRegId, ['competitorId' => $child['id']]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($child['id'], $this->registrationRow($memberRegId)['competitor_id']);
    }

    public function testExistingZawodnikWithoutCareIsRejectedForTheCaptain(): void
    {
        [$teamId, $memberRegId] = $this->teamWithMember();
        $strangerCompetitor = $this->competitorOf($this->stranger);

        $response = $this->swap($this->captain, $teamId, $memberRegId, ['competitorId' => $strangerCompetitor]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('not_authorized_to_register', $this->json($response)['error']['code']);
    }

    public function testNonCaptainIsRejectedAndAnonymousIs401(): void
    {
        [$teamId, $memberRegId] = $this->teamWithMember();
        $body = ['newPerson' => ['firstName' => 'Nowy', 'lastName' => $this->prefix]];

        $denied = $this->swap($this->stranger, $teamId, $memberRegId, $body);
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('team_not_captain', $this->json($denied)['error']['code']);

        // Sam członek (nie kapitan) też nie wymienia.
        $member = $this->swap($this->member, $teamId, $memberRegId, $body);
        self::assertSame(403, $member->getStatusCode());

        $anon = $this->request('POST', $this->path($teamId, $memberRegId), $body);
        self::assertSame(401, $anon->getStatusCode());
    }

    public function testRegistrationOfAnotherTeamIs404(): void
    {
        [$teamId] = $this->teamWithMember();
        [, $otherRegId] = $this->teamWithMember('other');

        $response = $this->swap($this->captain, $teamId, $otherRegId, ['newPerson' => ['firstName' => 'Nowy', 'lastName' => $this->prefix]]);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testCaptainIsLockedOutAfterTheSwapDeadlineButOrganizerIsNot(): void
    {
        [$teamId, $memberRegId] = $this->teamWithMember();
        $this->pdo->prepare('UPDATE event_distances SET relay_swap_deadline = :d WHERE id = :id')
            ->execute(['d' => date('Y-m-d', strtotime('-1 day')), 'id' => $this->relayDistanceId]);
        $body = ['newPerson' => ['firstName' => 'Nowy', 'lastName' => $this->prefix]];

        $captain = $this->swap($this->captain, $teamId, $memberRegId, $body);
        self::assertSame(409, $captain->getStatusCode());
        self::assertSame('team_swap_deadline_passed', $this->json($captain)['error']['code']);

        $organizer = $this->swap($this->organizer, $teamId, $memberRegId, $body);
        self::assertSame(200, $organizer->getStatusCode(), (string) $organizer->getBody());
    }

    public function testWithoutAnExplicitDeadlineCaptainMaySwapUntilTheDayBeforeStart(): void
    {
        [$teamId, $memberRegId] = $this->teamWithMember();
        $this->pdo->prepare('UPDATE event_distances SET relay_swap_deadline = NULL WHERE id = :id')->execute(['id' => $this->relayDistanceId]);
        $body = ['newPerson' => ['firstName' => 'Nowy', 'lastName' => $this->prefix]];

        self::assertSame(200, $this->swap($this->captain, $teamId, $memberRegId, $body)->getStatusCode());

        $this->pdo->prepare('UPDATE events SET date = :d WHERE id = :id')->execute(['d' => date('Y-m-d'), 'id' => $this->event['id']]);
        $late = $this->swap($this->captain, $teamId, $memberRegId, ['newPerson' => ['firstName' => 'Drugi', 'lastName' => $this->prefix]]);
        self::assertSame(409, $late->getStatusCode());
        self::assertSame('team_swap_deadline_passed', $this->json($late)['error']['code']);
    }

    public function testCancelledRegistrationSamePersonAndDuplicateAreRejected(): void
    {
        [$teamId, $memberRegId] = $this->teamWithMember();
        $current = (string) $this->registrationRow($memberRegId)['competitor_id'];

        $same = $this->swap($this->captain, $teamId, $memberRegId, ['competitorId' => $current]);
        self::assertSame(422, $same->getStatusCode());
        self::assertSame('team_swap_same_person', $this->json($same)['error']['code']);

        $empty = $this->swap($this->captain, $teamId, $memberRegId, []);
        self::assertSame(422, $empty->getStatusCode());

        // Captain's own Zawodnik is already on the team => cannot be swapped in a second time.
        $captainCompetitor = $this->competitorOf($this->captain);
        $dup = $this->swap($this->captain, $teamId, $memberRegId, ['competitorId' => $captainCompetitor]);
        self::assertSame(409, $dup->getStatusCode());
        self::assertSame('team_swap_competitor_already_registered', $this->json($dup)['error']['code']);

        $this->pdo->prepare("UPDATE registrations SET status = 'cancelled' WHERE id = :id")->execute(['id' => $memberRegId]);
        $cancelled = $this->swap($this->captain, $teamId, $memberRegId, ['newPerson' => ['firstName' => 'Nowy', 'lastName' => $this->prefix]]);
        self::assertSame(409, $cancelled->getStatusCode());
        self::assertSame('team_swap_registration_inactive', $this->json($cancelled)['error']['code']);
    }

    public function testOrganizerOfAnotherEventCannotSwap(): void
    {
        [$teamId, $memberRegId] = $this->teamWithMember();
        $foreign = $this->makeUser('foreign', 'organizer');

        $response = $this->swap($foreign, $teamId, $memberRegId, ['newPerson' => ['firstName' => 'Nowy', 'lastName' => $this->prefix]]);

        self::assertSame(403, $response->getStatusCode());
    }

    /** @return array{0: string, 1: string} team id and the Zapis id of the (non-captain) member */
    private function teamWithMember(string $suffix = 'a'): array
    {
        $captain = $suffix === 'a' ? $this->captain : $this->makeUser('cap' . $suffix, 'participant');
        $member = $suffix === 'a' ? $this->member : $this->makeUser('mem' . $suffix, 'participant');
        $created = $this->json($this->post($captain, ['createTeam' => true, 'teamName' => 'Druzyna ' . $suffix]))['data'];
        $teamId = (string) $created['teamId'];
        $token = (string) $this->json($this->request('GET', '/api/v1/teams/' . $teamId . '/invite', [], $this->bearer($captain['id'], 'participant', $captain['email'])))['data']['inviteToken'];
        $joined = $this->post($member, ['teamId' => $teamId, 'teamInviteToken' => $token]);
        self::assertSame(201, $joined->getStatusCode(), (string) $joined->getBody());

        return [$teamId, (string) $this->json($joined)['data']['id']];
    }

    private function path(string $teamId, string $regId): string
    {
        return '/api/v1/teams/' . $teamId . '/members/' . $regId . '/swap';
    }

    /** @param array<string, mixed> $body */
    private function swap(array $actor, string $teamId, string $regId, array $body): ResponseInterface
    {
        return $this->request('POST', $this->path($teamId, $regId), $body, $this->bearer($actor['id'], (string) $actor['role'], $actor['email']));
    }

    private function post(array $caller, array $overrides): ResponseInterface
    {
        return $this->request('POST', '/api/v1/registrations', array_merge([
            'eventId' => $this->event['id'],
            'distanceId' => $this->relayDistanceId,
            'category' => 'Open',
            'merchandiseOrders' => [],
        ], $overrides), $this->bearer($caller['id'], (string) $caller['role'], $caller['email']));
    }

    /** @return array<string, mixed> */
    private function registrationRow(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function settlementSnapshot(string $registrationId): string
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registration_settlement_items WHERE registration_id = :id ORDER BY id');
        $stmt->execute(['id' => $registrationId]);

        return (string) json_encode($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function competitorOf(array $user): string
    {
        return (string) (new \ZmierzymyCzas\Domain\Competitors\CompetitorRepository($this->pdo))->ensureForUser($user)['id'];
    }

    /** @return array<string, mixed> */
    private function makeUser(string $slug, string $role): array
    {
        return $this->users->create([
            'name' => 'Swap ' . ucfirst($slug) . ' ' . (++$this->userSeq),
            'email' => $this->prefix . '-' . $slug . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ]);
    }
}

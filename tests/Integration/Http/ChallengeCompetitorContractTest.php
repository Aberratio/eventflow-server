<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Care\CareRepository;
use ZmierzymyCzas\Domain\Challenges\ChallengeRankingCalculator;
use ZmierzymyCzas\Domain\Challenges\ChallengeRepository;
use ZmierzymyCzas\Domain\Challenges\ChallengePointsSettingsRepository;
use ZmierzymyCzas\Domain\Challenges\ChallengePointThresholdScorer;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * R-06 (zakres P2): Uczestnictwo w Wyzwaniu należy do Zawodnika (`competitor_id`), nie do
 * Konta. Pokrywa backfill migracji 120, zapis podopiecznego przez opiekuna, postęp liczony
 * per Zawodnik, 403 dla cudzego Zawodnika oraz niezmieniony ranking dla danych sprzed zmiany
 * (wiersze bez `competitor_id`, czytane po `user_id`).
 */
final class ChallengeCompetitorContractTest extends HttpTestCase
{
    private UserRepository $users;
    private CompetitorRepository $competitors;
    private CareRepository $care;
    private string $challengeId;
    /** @var array<string, mixed> */
    private array $parent;
    /** @var array<string, mixed> */
    private array $stranger;
    /** @var array<string, mixed> */
    private array $ward;
    /** @var list<string> */
    private array $userIds = [];
    /** @var list<string> */
    private array $competitorIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->care = new CareRepository($this->pdo);
        $this->challengeId = 'r06-chal-' . bin2hex(random_bytes(4));

        $this->parent = $this->makeUser('Rodzic');
        $this->stranger = $this->makeUser('Obcy');
        $this->ward = $this->competitors->create(['firstName' => 'Zosia', 'lastName' => 'R06' . bin2hex(random_bytes(3)), 'birthDate' => '2015-05-05'], skipDuplicateCheck: true);
        $this->competitorIds[] = (string) $this->ward['id'];
        $this->care->grant((string) $this->parent['id'], (string) $this->ward['id']);

        $this->pdo->prepare(
            "INSERT INTO challenges (id, name, slug, description, min_completed, status, created_by)
             VALUES (:id, 'R06 Challenge', :slug, 'desc', 1, 'active', 'tester')"
        )->execute(['id' => $this->challengeId, 'slug' => $this->challengeId]);
        $this->pdo->prepare(
            'INSERT INTO challenge_events (challenge_id, event_id, sort_order) VALUES (:challengeId, :eventId, 0)'
        )->execute(['challengeId' => $this->challengeId, 'eventId' => 'r06-evt']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->challengeId)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM results WHERE event_id = :e')->execute(['e' => 'r06-evt']);
        $this->pdo->prepare("DELETE FROM registrations WHERE event_id = 'r06-evt'")->execute();
        $this->pdo->prepare('DELETE FROM challenge_entries WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenge_events WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenge_participations WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenges WHERE id = :id')->execute(['id' => $this->challengeId]);
        foreach ($this->userIds as $userId) {
            $this->pdo->prepare('DELETE FROM care_relations WHERE konto_id = :id')->execute(['id' => $userId]);
            $this->pdo->prepare('DELETE FROM competitors WHERE user_id = :id')->execute(['id' => $userId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        }
        foreach ($this->competitorIds as $competitorId) {
            $this->pdo->prepare('DELETE FROM care_relations WHERE zawodnik_id = :id')->execute(['id' => $competitorId]);
            $this->pdo->prepare('DELETE FROM competitors WHERE id = :id')->execute(['id' => $competitorId]);
        }
    }

    /** @return array<string, mixed> */
    private function makeUser(string $name): array
    {
        $user = $this->users->create([
            'name' => $name . ' ' . bin2hex(random_bytes(3)),
            'email' => 'r06-' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $this->userIds[] = (string) $user['id'];
        return $user;
    }

    /** @return array<string, string> */
    private function auth(array $user): array
    {
        return $this->bearer((string) $user['id'], 'participant', (string) $user['email']);
    }

    private function selfCompetitorId(array $user): string
    {
        return (string) $this->competitors->findByUserId((string) $user['id'])['id'];
    }

    /** A finished result of $eventId; with $registrationCompetitorId it hangs on a registration made for that Zawodnik. */
    private function plantResult(string $userId, ?string $registrationCompetitorId, int $position = 1): void
    {
        $registrationId = null;
        if ($registrationCompetitorId !== null) {
            $registrationId = 'r06-reg-' . bin2hex(random_bytes(4));
            $this->pdo->prepare(
                "INSERT INTO registrations (id, event_id, event_name, user_id, user_name, user_email, distance_id, distance_name, category, registered_at, qr_code, competitor_id)
                 VALUES (:id, 'r06-evt', 'R06', :userId, 'x', 'x@example.test', 'dist-1', '5km', 'open', :at, :qr, :competitorId)"
            )->execute([
                'id' => $registrationId,
                'userId' => $userId,
                'at' => date(DATE_ATOM),
                'qr' => 'pqr_' . bin2hex(random_bytes(24)),
                'competitorId' => $registrationCompetitorId,
            ]);
        }
        $this->pdo->prepare(
            "INSERT INTO results (id, event_id, event_name, distance_id, distance_name, user_id, user_name, registration_id, bib_number, category, time, time_in_seconds, position, category_position, status)
             VALUES (:id, 'r06-evt', 'R06', 'dist-1', '5km', :userId, 'x', :registrationId, '1', 'open', '00:30:00', 1800, :position, 1, 'finished')"
        )->execute(['id' => bin2hex(random_bytes(16)), 'userId' => $userId, 'registrationId' => $registrationId, 'position' => $position]);
    }

    private function join(array $actor, ?string $competitorId): array
    {
        $response = $this->request(
            'POST',
            '/api/v1/challenges/' . $this->challengeId . '/join',
            $competitorId !== null ? ['competitorId' => $competitorId] : [],
            $this->auth($actor)
        );
        return [$response->getStatusCode(), $this->json($response)];
    }

    public function testBackfillMapsOwnProfileAndLeavesUnmappableRowsNull(): void
    {
        $withProfile = $this->makeUser('Z profilem');
        $noProfileId = 'r06-noprofile-' . bin2hex(random_bytes(4));
        $insert = $this->pdo->prepare(
            "INSERT INTO challenge_participations (id, challenge_id, user_id, competitor_id, user_name, joined_at)
             VALUES (:id, :challengeId, :userId, NULL, 'x', :at)"
        );
        $insert->execute(['id' => 'r06-p1-' . bin2hex(random_bytes(3)), 'challengeId' => $this->challengeId, 'userId' => $withProfile['id'], 'at' => date(DATE_ATOM)]);
        $insert->execute(['id' => 'r06-p2-' . bin2hex(random_bytes(3)), 'challengeId' => $this->challengeId, 'userId' => $noProfileId, 'at' => date(DATE_ATOM)]);
        $this->pdo->prepare(
            "INSERT INTO challenge_entries (id, challenge_id, user_id, competitor_id, event_id, status, source)
             VALUES (:id, :challengeId, :userId, NULL, 'r06-evt', 'approved', 'manual')"
        )->execute(['id' => 'r06-e1-' . bin2hex(random_bytes(3)), 'challengeId' => $this->challengeId, 'userId' => $withProfile['id']]);

        $file = Migrator::migrationsDir() . '/120_r06_challenge_competitor.sql';
        Migrator::applyFile($this->pdo, $file);
        Migrator::applyFile($this->pdo, $file); // idempotent

        $own = $this->selfCompetitorId($withProfile);
        $mapped = $this->pdo->prepare('SELECT competitor_id FROM challenge_participations WHERE challenge_id = :c AND user_id = :u');
        $mapped->execute(['c' => $this->challengeId, 'u' => $withProfile['id']]);
        self::assertSame($own, $mapped->fetchColumn());
        $mapped->execute(['c' => $this->challengeId, 'u' => $noProfileId]);
        self::assertNull($mapped->fetchColumn(), 'a participation that cannot be mapped stays NULL');
        $entry = $this->pdo->prepare('SELECT competitor_id FROM challenge_entries WHERE challenge_id = :c AND user_id = :u');
        $entry->execute(['c' => $this->challengeId, 'u' => $withProfile['id']]);
        self::assertSame($own, $entry->fetchColumn());
    }

    public function testCaregiverJoinsWardAndSeesBothInMyChallenges(): void
    {
        [$status, $payload] = $this->join($this->parent, (string) $this->ward['id']);
        self::assertSame(201, $status);
        self::assertSame($this->ward['id'], $payload['data']['competitorId']);
        self::assertSame($this->parent['id'], $payload['data']['userId']);
        self::assertSame($this->ward['firstName'] . ' ' . $this->ward['lastName'], $payload['data']['competitorName']);

        [$ownStatus, $own] = $this->join($this->parent, null);
        self::assertSame(201, $ownStatus);
        self::assertSame($this->selfCompetitorId($this->parent), $own['data']['competitorId']);

        // joining again is idempotent per Zawodnik
        [, $again] = $this->join($this->parent, (string) $this->ward['id']);
        self::assertSame($payload['data']['id'], $again['data']['id']);

        $list = $this->request('GET', '/api/v1/challenge-participations?userId=' . $this->parent['id'], [], $this->auth($this->parent));
        $rows = $this->json($list)['data'];
        self::assertCount(2, $rows);
        self::assertEqualsCanonicalizing(
            [(string) $this->ward['id'], $this->selfCompetitorId($this->parent)],
            array_column($rows, 'competitorId')
        );

        // the per-challenge query (challenge details page) stays the caller's own row
        $single = $this->request('GET', '/api/v1/challenge-participations?challengeId=' . $this->challengeId . '&userId=' . $this->parent['id'], [], $this->auth($this->parent));
        $singleRows = $this->json($single)['data'];
        self::assertSame([$this->selfCompetitorId($this->parent)], array_column($singleRows, 'competitorId'));

        // a ward's participation joined by someone else is still visible to the caregiver
        $other = $this->makeUser('Drugi opiekun');
        $this->care->grant((string) $other['id'], (string) $this->ward['id']);
        $rowsOther = $this->json($this->request('GET', '/api/v1/challenge-participations?userId=' . $other['id'], [], $this->auth($other)))['data'];
        self::assertSame([(string) $this->ward['id']], array_column($rowsOther, 'competitorId'));
        [, $viaOther] = $this->join($other, (string) $this->ward['id']);
        self::assertSame($payload['data']['id'], $viaOther['data']['id'], 'same Zawodnik = one participation, whoever joins');
    }

    public function testJoiningSomeoneElsesCompetitorIsForbidden(): void
    {
        [$status, $payload] = $this->join($this->stranger, (string) $this->ward['id']);
        self::assertSame(403, $status);
        self::assertFalse($payload['success']);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM challenge_participations WHERE challenge_id = :c');
        $count->execute(['c' => $this->challengeId]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testProgressIsPerCompetitorNotPerAccount(): void
    {
        // Only the ward ran: the registration was made by the parent (results.user_id = parent) for the ward.
        $this->plantResult((string) $this->parent['id'], (string) $this->ward['id']);

        [, $ward] = $this->join($this->parent, (string) $this->ward['id']);
        [, $own] = $this->join($this->parent, null);

        self::assertSame(1, $ward['data']['completedCount']);
        self::assertTrue($ward['data']['isCompleted']);
        self::assertSame(0, $own['data']['completedCount'], 'the caregiver did not run - the ward\'s result is not hers');
        self::assertFalse($own['data']['isCompleted']);
        foreach ($ward['data']['entries'] as $entry) {
            self::assertSame($this->ward['id'], $entry['competitorId']);
        }
    }

    public function testRankingIsPerCompetitorAndMasksNothingExtra(): void
    {
        $this->plantResult((string) $this->parent['id'], (string) $this->ward['id']);
        $this->join($this->parent, (string) $this->ward['id']);
        $this->join($this->parent, null);

        $response = $this->request('GET', '/api/v1/challenges/' . $this->challengeId . '/ranking');
        $entries = $this->json($response)['data']['entries'];
        self::assertCount(2, $entries);
        $byCompetitor = array_column($entries, null, 'competitorId');
        self::assertTrue($byCompetitor[(string) $this->ward['id']]['isCompleted']);
        self::assertFalse($byCompetitor[$this->selfCompetitorId($this->parent)]['isCompleted']);
        self::assertNull($byCompetitor[(string) $this->ward['id']]['userId'], 'a ward must not expose the caregiver account');
        self::assertSame($this->parent['id'], $byCompetitor[$this->selfCompetitorId($this->parent)]['userId']);
    }

    public function testRowsWithoutCompetitorKeepWorkingByUserId(): void
    {
        // Pre-R-06 data: participation + entry without competitor_id, result keyed by user_id only.
        $user = $this->makeUser('Dawny uczestnik');
        $this->pdo->prepare(
            "INSERT INTO challenge_participations (id, challenge_id, user_id, competitor_id, user_name, joined_at)
             VALUES ('r06-legacy', :c, :u, NULL, 'Dawny', :at)"
        )->execute(['c' => $this->challengeId, 'u' => $user['id'], 'at' => date(DATE_ATOM)]);
        $this->plantResult((string) $user['id'], null);

        $challenges = new ChallengeRepository($this->pdo);
        $row = $challenges->participations($this->challengeId, (string) $user['id'])[0];
        self::assertNull($row['competitorId']);
        self::assertSame(1, $row['completedCount']);
        self::assertTrue($row['isCompleted']);

        $ranking = new ChallengeRankingCalculator($challenges, $this->competitors, new ChallengePointThresholdScorer($this->pdo, new ChallengePointsSettingsRepository($this->pdo)));
        $entries = $ranking->rank([$this->challengeId])['entries'];
        self::assertCount(1, $entries);
        self::assertSame((string) $user['id'], $entries[0]['userId']);
        self::assertTrue($entries[0]['isCompleted']);
    }

    public function testJoinWithoutCompetitorStillTargetsOwnProfile(): void
    {
        [$status, $payload] = $this->join($this->stranger, null);
        self::assertSame(201, $status);
        self::assertSame($this->selfCompetitorId($this->stranger), $payload['data']['competitorId']);
    }
}

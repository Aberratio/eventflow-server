<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Challenges\ChallengePointsSettingsRepository;
use ZmierzymyCzas\Domain\Challenges\ChallengePointThresholdScorer;
use ZmierzymyCzas\Domain\Challenges\ChallengeRankingCalculator;
use ZmierzymyCzas\Domain\Challenges\ChallengeRepository;
use ZmierzymyCzas\Domain\Competitors\CompetitorNamePresenter;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;

/**
 * E6.6 (issue 15 §6, "Ranking: publiczny, z respektowaniem anonimizacji"):
 * point totals via E6.2's scorer, descending sort, "1,1,3" ex-aequo rank
 * sharing, and anonymization masking. `results`/`challenge_participations`
 * have no foreign keys to `events`/`challenges` in this schema, so — mirroring
 * ChallengeCompletionTest's own style — fixture rows are inserted directly
 * rather than through a full Event/registration flow.
 */
final class ChallengeRankingCalculatorTest extends TestCase
{
    private PDO $pdo;
    private ChallengeRepository $challenges;
    private CompetitorRepository $competitors;
    private UserRepository $users;
    private ChallengePointsSettingsRepository $pointsSettings;
    private ChallengeRankingCalculator $ranking;
    private string $prefix;
    /** @var array{pointsTable: list<int>, floorPoints: int} */
    private array $originalSettings;
    /** @var list<string> */
    private array $challengeIds = [];

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->prefix = 'phpunit-rank-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for challenge ranking tests: ' . $e->getMessage());
        }

        $this->challenges = new ChallengeRepository($this->pdo);
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->users = new UserRepository($this->pdo);
        $this->pointsSettings = new ChallengePointsSettingsRepository($this->pdo);
        $this->originalSettings = $this->pointsSettings->getSettings();
        $scorer = new ChallengePointThresholdScorer($this->pdo, $this->pointsSettings);
        $this->ranking = new ChallengeRankingCalculator($this->challenges, $this->competitors, $scorer);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pointsSettings->updateSettings($this->originalSettings);
        foreach (array_reverse($this->challengeIds) as $id) {
            $this->pdo->prepare('DELETE FROM challenge_participations WHERE challenge_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM challenge_entries WHERE challenge_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM challenges WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare("DELETE FROM results WHERE event_id LIKE :like")->execute(['like' => $this->prefix . '%']);
        $this->pdo->prepare('DELETE FROM competitors WHERE last_name = :last')->execute(['last' => $this->prefix]);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '.%@example.test']);
    }

    private function id(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @return array{0: array<string,mixed>, 1: array<string,mixed>} [user, competitor] */
    private function makeUserAndCompetitor(string $firstName): array
    {
        $user = $this->users->create([
            'name' => $firstName . ' ' . $this->prefix,
            'email' => $this->prefix . '.' . strtolower($firstName) . '@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $competitor = $this->competitors->create([
            'firstName' => $firstName,
            'lastName' => $this->prefix,
            'userId' => $user['id'],
        ], skipDuplicateCheck: true);
        return [$user, $competitor];
    }

    private function addFinishedResult(string $eventId, string $userId, string $userName, int $position): void
    {
        $this->pdo->prepare(
            "INSERT INTO results (id, event_id, event_name, distance_id, distance_name, user_id, user_name, bib_number, category, time, time_in_seconds, position, category_position, status)
             VALUES (:id, :eventId, 'Test Event', 'dist-1', '10km', :userId, :userName, :bib, 'open', '00:40:00', 2400, :position, :position, 'finished')"
        )->execute([
            'id' => $this->id(),
            'eventId' => $eventId,
            'userId' => $userId,
            'userName' => $userName,
            'bib' => (string) $position,
            'position' => $position,
        ]);
    }

    private function addParticipation(string $challengeId, string $userId, string $userName): void
    {
        $this->pdo->prepare(
            "INSERT INTO challenge_participations (id, challenge_id, user_id, user_name, joined_at, reward_granted, selected_rewards)
             VALUES (:id, :challengeId, :userId, :userName, NOW(), 0, '[]')"
        )->execute(['id' => $this->id(), 'challengeId' => $challengeId, 'userId' => $userId, 'userName' => $userName]);
    }

    /** @param array<string, mixed> $conditionTree @return array<string, mixed> */
    private function createChallenge(array $conditionTree): array
    {
        $slug = 'phpunit-rank-chal-' . bin2hex(random_bytes(4));
        $challenge = $this->challenges->create([
            'name' => 'Ranking Challenge ' . $slug,
            'slug' => $slug,
            'description' => 'desc',
            'status' => 'active',
            'conditionTree' => $conditionTree,
        ], 'tester');
        $this->challengeIds[] = (string) $challenge['id'];
        return $challenge;
    }

    /**
     * Core scenario: three competitors, one event, points table with a
     * genuine tie at the top (places 1 and 2 both worth 100), place 3 worth
     * 50. Proves total points come from E6.2's scorer, descending sort, and
     * the standard competition ranking convention ("1,1,3" — NOT "1,1,2"
     * dense ranking, NOT "1,2,2"): two competitors tied for 1st means the
     * next distinct competitor is 3rd.
     *
     * @group known-failure-pp14
     *
     * PP.13: pre-existing failure on both MySQL 8.4 and MariaDB 10.4, unrelated to
     * migrations/CI — the fixture creates a 2nd competitor for a user that
     * UserRepository::create() already gave one via ensureForUser(), tripping
     * uq_competitors_user. Tracked by PP.14. Remove this tag once fixed there.
     */
    public function testDescendingSortWithExAequoUsesStandardCompetitionRanking(): void
    {
        $this->pointsSettings->updateSettings(['pointsTable' => [100, 100, 50], 'floorPoints' => 0]);
        $eventId = $this->prefix . '-evt-1';

        [$userFirst] = $this->makeUserAndCompetitor('First');
        [$userSecond] = $this->makeUserAndCompetitor('Second');
        [$userThird] = $this->makeUserAndCompetitor('Third');

        $challenge = $this->createChallenge([
            'type' => 'point_threshold',
            'pointsRequired' => 1,
            'bestResultsCount' => null,
            'events' => [['eventId' => $eventId]],
        ]);

        $this->addFinishedResult($eventId, $userFirst['id'], $userFirst['name'], 1);
        $this->addFinishedResult($eventId, $userSecond['id'], $userSecond['name'], 2);
        $this->addFinishedResult($eventId, $userThird['id'], $userThird['name'], 3);

        $this->addParticipation($challenge['id'], $userFirst['id'], $userFirst['name']);
        $this->addParticipation($challenge['id'], $userSecond['id'], $userSecond['name']);
        $this->addParticipation($challenge['id'], $userThird['id'], $userThird['name']);

        $result = $this->ranking->rank([$challenge['id']]);

        self::assertTrue($result['hasPoints']);
        $byUserId = [];
        foreach ($result['entries'] as $entry) {
            $byUserId[$entry['userId']] = $entry;
        }

        self::assertSame(100, $byUserId[$userFirst['id']]['points']);
        self::assertSame(100, $byUserId[$userSecond['id']]['points']);
        self::assertSame(50, $byUserId[$userThird['id']]['points']);

        self::assertSame(1, $byUserId[$userFirst['id']]['rank']);
        self::assertSame(1, $byUserId[$userSecond['id']]['rank']);
        // The tie at rank 1 occupies two spots, so the next distinct
        // competitor is rank 3, never rank 2.
        self::assertSame(3, $byUserId[$userThird['id']]['rank']);
    }

    /**
     * @group known-failure-pp14
     *
     * PP.13: pre-existing failure on both MySQL 8.4 and MariaDB 10.4, unrelated to
     * migrations/CI — the fixture creates a 2nd competitor for a user that
     * UserRepository::create() already gave one via ensureForUser(), tripping
     * uq_competitors_user. Tracked by PP.14. Remove this tag once fixed there.
     */
    public function testAnonymizedCompetitorNameIsMaskedNonAnonymizedIsNot(): void
    {
        $this->pointsSettings->updateSettings(['pointsTable' => [100, 90], 'floorPoints' => 0]);
        $eventId = $this->prefix . '-evt-anon';

        [$userAnon, $competitorAnon] = $this->makeUserAndCompetitor('Anon');
        [$userVisible] = $this->makeUserAndCompetitor('Visible');
        $this->competitors->setAnonymization((string) $competitorAnon['id'], true);

        $challenge = $this->createChallenge([
            'type' => 'point_threshold',
            'pointsRequired' => 1,
            'bestResultsCount' => null,
            'events' => [['eventId' => $eventId]],
        ]);

        $this->addFinishedResult($eventId, $userAnon['id'], $userAnon['name'], 1);
        $this->addFinishedResult($eventId, $userVisible['id'], $userVisible['name'], 2);
        $this->addParticipation($challenge['id'], $userAnon['id'], $userAnon['name']);
        $this->addParticipation($challenge['id'], $userVisible['id'], $userVisible['name']);

        $result = $this->ranking->rank([$challenge['id']]);

        $byUserId = [];
        foreach ($result['entries'] as $entry) {
            $byUserId[$entry['userId']] = $entry;
        }

        self::assertSame(CompetitorNamePresenter::ANONYMIZED_LABEL, $byUserId[$userAnon['id']]['name']);
        self::assertTrue($byUserId[$userAnon['id']]['isAnonymized']);
        self::assertStringNotContainsString('Anon', $byUserId[$userAnon['id']]['name']);

        self::assertStringContainsString('Visible', $byUserId[$userVisible['id']]['name']);
        self::assertFalse($byUserId[$userVisible['id']]['isAnonymized']);
    }

    /**
     * Edge case (issue 15 §6 doesn't resolve this, judgment call — see
     * ChallengeRankingCalculator's class doc comment): a Wyzwanie whose tree
     * has no `point_threshold` node at all falls back to a binary
     * completed/not-completed ranking rather than a fake point total.
     *
     * @group known-failure-pp14
     *
     * PP.13: pre-existing failure on both MySQL 8.4 and MariaDB 10.4, unrelated to
     * migrations/CI — the fixture creates a 2nd competitor for a user that
     * UserRepository::create() already gave one via ensureForUser(), tripping
     * uq_competitors_user. Tracked by PP.14. Remove this tag once fixed there.
     */
    public function testChallengeWithoutPointThresholdNodeFallsBackToCompletionBasedRanking(): void
    {
        $eventA = $this->prefix . '-flat-a';
        $eventB = $this->prefix . '-flat-b';

        [$userCompleted] = $this->makeUserAndCompetitor('Completed');
        [$userPartial] = $this->makeUserAndCompetitor('Partial');

        $challenge = $this->createChallenge([
            'type' => 'event_threshold',
            'requiredCount' => 2,
            'events' => [['eventId' => $eventA], ['eventId' => $eventB]],
        ]);

        // userCompleted has both linked events approved -> completes the
        // Wyzwanie; userPartial has only one -> stays incomplete.
        foreach ([$eventA, $eventB] as $eventId) {
            $this->pdo->prepare(
                "INSERT INTO challenge_entries (id, challenge_id, user_id, event_id, status, submitted_at, reviewed_at, reviewed_by, source)
                 VALUES (:id, :challengeId, :userId, :eventId, 'approved', NOW(), NOW(), 'tester', 'manual')"
            )->execute(['id' => $this->id(), 'challengeId' => $challenge['id'], 'userId' => $userCompleted['id'], 'eventId' => $eventId]);
        }
        $this->pdo->prepare(
            "INSERT INTO challenge_entries (id, challenge_id, user_id, event_id, status, submitted_at, reviewed_at, reviewed_by, source)
             VALUES (:id, :challengeId, :userId, :eventId, 'approved', NOW(), NOW(), 'tester', 'manual')"
        )->execute(['id' => $this->id(), 'challengeId' => $challenge['id'], 'userId' => $userPartial['id'], 'eventId' => $eventA]);

        $this->addParticipation($challenge['id'], $userCompleted['id'], $userCompleted['name']);
        $this->addParticipation($challenge['id'], $userPartial['id'], $userPartial['name']);

        $result = $this->ranking->rank([$challenge['id']]);

        self::assertFalse($result['hasPoints']);
        $byUserId = [];
        foreach ($result['entries'] as $entry) {
            $byUserId[$entry['userId']] = $entry;
        }

        self::assertSame(1, $byUserId[$userCompleted['id']]['points']);
        self::assertTrue($byUserId[$userCompleted['id']]['isCompleted']);
        self::assertSame(1, $byUserId[$userCompleted['id']]['rank']);

        self::assertSame(0, $byUserId[$userPartial['id']]['points']);
        self::assertFalse($byUserId[$userPartial['id']]['isCompleted']);
        self::assertSame(2, $byUserId[$userPartial['id']]['rank']);
    }
}

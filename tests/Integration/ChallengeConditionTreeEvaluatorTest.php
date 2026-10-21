<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Challenges\ChallengeConditionTreeEvaluator;
use ZmierzymyCzas\Domain\Challenges\ChallengePointsSettingsRepository;
use ZmierzymyCzas\Domain\Challenges\ChallengePointThresholdScorer;
use ZmierzymyCzas\Domain\Challenges\ChallengeRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;

/**
 * The full recursive condition-tree evaluator, plus its
 * wiring into ChallengeRepository::checkCompletion()/hydrateParticipation()
 * (isCompleted now real tree evaluation, not the legacy flat
 * `rule.minCompleted` count — see ChallengeRepository::isChallengeCompletedForUser()).
 */
final class ChallengeConditionTreeEvaluatorTest extends TestCase
{
    private PDO $pdo;
    private ChallengeRepository $challenges;
    private EventRepository $events;
    private ResultRepository $results;
    private UserRepository $users;
    private ChallengePointsSettingsRepository $pointsSettings;
    private ChallengeConditionTreeEvaluator $evaluator;
    private string $prefix;
    /** @var array{pointsTable: list<int>, floorPoints: int} */
    private array $originalSettings;
    /** @var list<string> */
    private array $challengeIds = [];

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->prefix = 'phpeval-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->deletePhpUnitData();
            $this->challenges = new ChallengeRepository($this->pdo);
            $this->events = new EventRepository($this->pdo);
            $this->results = new ResultRepository($this->pdo);
            $this->users = new UserRepository($this->pdo);
            $this->pointsSettings = new ChallengePointsSettingsRepository($this->pdo);
            $this->originalSettings = $this->pointsSettings->getSettings();
            $scorer = new ChallengePointThresholdScorer($this->pdo, $this->pointsSettings);
            $this->evaluator = new ChallengeConditionTreeEvaluator($this->pdo, $this->challenges, $scorer);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for challenge condition tree evaluator tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pointsSettings->updateSettings($this->originalSettings);
        foreach (array_reverse($this->challengeIds) as $id) {
            $this->pdo->prepare('DELETE FROM challenge_entries WHERE challenge_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM challenge_participations WHERE challenge_id = :id')->execute(['id' => $id]);
        }
        foreach (array_reverse($this->challengeIds) as $id) {
            $this->pdo->prepare('DELETE FROM challenges WHERE id = :id')->execute(['id' => $id]);
        }
        $this->deletePhpUnitData();
    }

    // --- fixtures --------------------------------------------------------

    /** @return array{0: array<string,mixed>, 1: array<string,mixed>} [event, distance] */
    private function makeEvent(string $suffix, string $date = '2026-06-15'): array
    {
        $event = $this->events->create([
            'name' => 'PHPUnit Eval Event ' . $suffix,
            'slug' => $this->prefix . '-event-' . $suffix,
            'discipline' => 'running',
            'date' => $date,
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>desc</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->prefix . '-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'active',
            'registrationDeadline' => '2026-06-01',
            'featured' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $this->prefix . '-' . $suffix . '-10k',
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
            ]],
        ]);
        return [$event, $event['distances'][0]];
    }

    private function createUser(string $name): array
    {
        return $this->users->create([
            'name' => $name,
            'email' => $this->prefix . '.' . strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(2)) . '@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
    }

    private function finishResult(array $event, array $distance, string $userId, string $userName, int $place = 1): void
    {
        $this->results->create([
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'distanceId' => $distance['id'],
            'distanceName' => $distance['name'],
            'userId' => $userId,
            'userName' => $userName,
            'bibNumber' => (string) random_int(1000, 9999),
            'category' => 'Open',
            'time' => '0:30:00',
            'timeInSeconds' => 1800,
            'position' => $place,
            'categoryPosition' => $place,
            'status' => 'finished',
        ]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function createChallenge(array $data): array
    {
        $slug = $data['slug'] ?? ($this->prefix . '-chal-' . bin2hex(random_bytes(4)));
        $challenge = $this->challenges->create([
            'name' => $data['name'] ?? ('Challenge ' . $slug),
            'slug' => $slug,
            'description' => 'desc',
            'status' => 'active',
            ...$data,
        ], 'tester');
        $this->challengeIds[] = (string) $challenge['id'];
        return $challenge;
    }

    private function approveEntry(string $challengeId, string $userId, string $eventId): void
    {
        $this->pdo->prepare(
            "INSERT INTO challenge_entries (id, challenge_id, user_id, event_id, status, submitted_at, reviewed_at, reviewed_by, source)
             VALUES (:id, :challengeId, :userId, :eventId, 'approved', NOW(), NOW(), 'tester', 'manual')"
        )->execute(['id' => bin2hex(random_bytes(16)), 'challengeId' => $challengeId, 'userId' => $userId, 'eventId' => $eventId]);
    }

    // --- event_threshold ---------------------------------------------------

    public function testEventThresholdSatisfiedWhenEnoughFinishedResults(): void
    {
        $competitor = $this->createUser('ET Satisfied');
        [$eventA, $distA] = $this->makeEvent('et-a');
        [$eventB, $distB] = $this->makeEvent('et-b');
        [$eventC] = $this->makeEvent('et-c'); // never finished by competitor

        $this->finishResult($eventA, $distA, $competitor['id'], $competitor['name']);
        $this->finishResult($eventB, $distB, $competitor['id'], $competitor['name']);

        $node = [
            'type' => 'event_threshold',
            'requiredCount' => 2,
            'events' => [['eventId' => $eventA['id']], ['eventId' => $eventB['id']], ['eventId' => $eventC['id']]],
        ];

        $memo = [];
        self::assertTrue($this->evaluator->evaluateNode($node, $competitor['id'], $memo));
    }

    public function testEventThresholdUnsatisfiedWhenNotEnough(): void
    {
        $competitor = $this->createUser('ET Unsatisfied');
        [$eventA, $distA] = $this->makeEvent('et-u-a');
        [$eventB] = $this->makeEvent('et-u-b');

        $this->finishResult($eventA, $distA, $competitor['id'], $competitor['name']);

        $node = [
            'type' => 'event_threshold',
            'requiredCount' => 2,
            'events' => [['eventId' => $eventA['id']], ['eventId' => $eventB['id']]],
        ];

        $memo = [];
        self::assertFalse($this->evaluator->evaluateNode($node, $competitor['id'], $memo));
    }

    public function testEventThresholdRespectsTimeWindow(): void
    {
        $competitor = $this->createUser('ET Window');
        [$eventInWindow, $distIn] = $this->makeEvent('et-w-in', '2026-06-01');
        [$eventOutOfWindow, $distOut] = $this->makeEvent('et-w-out', '2027-01-15');

        $this->finishResult($eventInWindow, $distIn, $competitor['id'], $competitor['name']);
        $this->finishResult($eventOutOfWindow, $distOut, $competitor['id'], $competitor['name']);

        $node = [
            'type' => 'event_threshold',
            'requiredCount' => 2,
            'windowStart' => '2026-01-01',
            'windowEnd' => '2026-12-31',
            'events' => [['eventId' => $eventInWindow['id']], ['eventId' => $eventOutOfWindow['id']]],
        ];

        $memo = [];
        // Only one of the two finished results falls inside the window -> unsatisfied for requiredCount=2.
        self::assertFalse($this->evaluator->evaluateNode($node, $competitor['id'], $memo));

        $node['requiredCount'] = 1;
        $memo = [];
        self::assertTrue($this->evaluator->evaluateNode($node, $competitor['id'], $memo));
    }

    /**
     * The "ścieżka manualna dla dowodu" -
     * a Moderator-approved `challenge_entries` row with `source = 'manual'`
     * for an EXTERNAL Event (no Dystans, never had any registrations, so it
     * can never have a `results` row) must still satisfy an `event_threshold`
     * node. Before this fix, evaluateEventThreshold() only ever queried
     * `results`, so an approved manual entry for an Event with zero results
     * rows would never count - the Wyzwanie could never complete via the
     * manual verification path the spec explicitly calls for.
     */
    public function testEventThresholdSatisfiedByApprovedManualEntryWithoutResultsRow(): void
    {
        $competitor = $this->createUser('ET Manual Proof');
        $externalEvent = $this->events->createExternal([
            'name' => 'Polmaraton Korony (przed platforma)',
            'date' => '2019-09-01',
            'location' => 'Rynek',
            'city' => 'Krakow',
        ], 'tester-moderator');
        self::assertTrue($externalEvent['isExternal']);

        // Approved manual entry, but deliberately NO `results` row - external
        // Events have no timing infrastructure at all.
        $challenge = $this->createChallenge(['name' => 'Manual Proof Challenge']);
        $this->approveEntry((string) $challenge['id'], $competitor['id'], $externalEvent['id']);

        $node = [
            'type' => 'event_threshold',
            'requiredCount' => 1,
            'events' => [['eventId' => $externalEvent['id']]],
        ];

        $memo = [];
        self::assertTrue(
            $this->evaluator->evaluateNode($node, $competitor['id'], $memo),
            'An approved manual challenge_entries row must satisfy event_threshold even with zero results rows.'
        );

        // Sanity: confirm there genuinely is no results row backing this.
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM results WHERE event_id = :id');
        $stmt->execute(['id' => $externalEvent['id']]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testEventThresholdManualEntryRespectsTimeWindowViaEventDate(): void
    {
        $competitor = $this->createUser('ET Manual Window');
        $externalEvent = $this->events->createExternal([
            'name' => 'Old Race Outside Window',
            'date' => '2015-05-01',
            'location' => 'Rynek',
            'city' => 'Krakow',
        ], 'tester-moderator');
        $challenge = $this->createChallenge(['name' => 'Manual Window Challenge']);
        $this->approveEntry((string) $challenge['id'], $competitor['id'], $externalEvent['id']);

        $node = [
            'type' => 'event_threshold',
            'requiredCount' => 1,
            'windowStart' => '2020-01-01',
            'windowEnd' => '2026-12-31',
            'events' => [['eventId' => $externalEvent['id']]],
        ];

        $memo = [];
        self::assertFalse($this->evaluator->evaluateNode($node, $competitor['id'], $memo), 'Manual entry for a 2015 Event must not satisfy a 2020-2026 window.');

        $node['windowStart'] = '2010-01-01';
        $memo = [];
        self::assertTrue($this->evaluator->evaluateNode($node, $competitor['id'], $memo));
    }

    public function testEventThresholdManualEntryScopedToOwningChallengeWhenChallengeIdGiven(): void
    {
        $competitor = $this->createUser('ET Manual Scope');
        $externalEvent = $this->events->createExternal([
            'name' => 'Scoped Race',
            'date' => '2021-05-01',
            'location' => 'Rynek',
            'city' => 'Krakow',
        ], 'tester-moderator');
        $challengeA = $this->createChallenge(['name' => 'Scope Challenge A']);
        $challengeB = $this->createChallenge(['name' => 'Scope Challenge B']);
        // Approved only under Challenge A.
        $this->approveEntry((string) $challengeA['id'], $competitor['id'], $externalEvent['id']);

        $node = [
            'type' => 'event_threshold',
            'requiredCount' => 1,
            'events' => [['eventId' => $externalEvent['id']]],
        ];

        $memoA = [];
        self::assertTrue(
            $this->evaluator->evaluateNode($node, $competitor['id'], $memoA, 0, (string) $challengeA['id']),
            'Entry approved under Challenge A must satisfy Challenge A\'s own event_threshold node.'
        );

        $memoB = [];
        self::assertFalse(
            $this->evaluator->evaluateNode($node, $competitor['id'], $memoB, 0, (string) $challengeB['id']),
            'An entry approved under Challenge A must not silently satisfy a different Challenge B that links the same Event.'
        );
    }

    public function testEventThresholdDoesNotDoubleCountWhenBothResultAndManualEntryExist(): void
    {
        $competitor = $this->createUser('ET Dedup');
        [$event, $dist] = $this->makeEvent('et-dedup');
        $challenge = $this->createChallenge(['name' => 'Dedup Challenge']);
        $this->finishResult($event, $dist, $competitor['id'], $competitor['name']);
        $this->approveEntry((string) $challenge['id'], $competitor['id'], $event['id']);

        $node = [
            'type' => 'event_threshold',
            'requiredCount' => 2, // if the Event were double-counted, this would wrongly pass
            'events' => [['eventId' => $event['id']]],
        ];

        $memo = [];
        self::assertFalse($this->evaluator->evaluateNode($node, $competitor['id'], $memo), 'One Event backed by both a result and a manual entry must still count once.');
    }

    // --- point_threshold (delegates to the points scorer) -----------------------

    public function testPointThresholdDelegatesToScorer(): void
    {
        $this->pointsSettings->updateSettings(['pointsTable' => [100, 90], 'floorPoints' => 0]);
        $competitor = $this->createUser('PT Delegate');
        [$event, $dist] = $this->makeEvent('pt');
        $this->finishResult($event, $dist, $competitor['id'], $competitor['name'], 1);

        $node = [
            'type' => 'point_threshold',
            'pointsRequired' => 100,
            'bestResultsCount' => 1,
            'events' => [['eventId' => $event['id']]],
        ];

        $memo = [];
        self::assertTrue($this->evaluator->evaluateNode($node, $competitor['id'], $memo));

        $node['pointsRequired'] = 101;
        $memo = [];
        self::assertFalse($this->evaluator->evaluateNode($node, $competitor['id'], $memo));
    }

    // --- group (AND / threshold) --------------------------------------------

    public function testGroupActsAsAndWhenRequiredCountEqualsChildCount(): void
    {
        $competitor = $this->createUser('Group AND');
        [$eventA, $distA] = $this->makeEvent('grp-and-a');
        [$eventB] = $this->makeEvent('grp-and-b'); // never finished

        $this->finishResult($eventA, $distA, $competitor['id'], $competitor['name']);

        $node = [
            'type' => 'group',
            'requiredCount' => 2,
            'children' => [
                ['type' => 'event_threshold', 'requiredCount' => 1, 'events' => [['eventId' => $eventA['id']]]],
                ['type' => 'event_threshold', 'requiredCount' => 1, 'events' => [['eventId' => $eventB['id']]]],
            ],
        ];

        $memo = [];
        self::assertFalse($this->evaluator->evaluateNode($node, $competitor['id'], $memo)); // only 1 of 2 children satisfied
    }

    public function testGroupActsAsThresholdWhenRequiredCountIsLessThanChildCount(): void
    {
        $competitor = $this->createUser('Group Threshold');
        [$eventA, $distA] = $this->makeEvent('grp-thr-a');
        [$eventB] = $this->makeEvent('grp-thr-b'); // never finished

        $this->finishResult($eventA, $distA, $competitor['id'], $competitor['name']);

        $node = [
            'type' => 'group',
            'requiredCount' => 1, // threshold: only 1 of 2 children needed
            'children' => [
                ['type' => 'event_threshold', 'requiredCount' => 1, 'events' => [['eventId' => $eventA['id']]]],
                ['type' => 'event_threshold', 'requiredCount' => 1, 'events' => [['eventId' => $eventB['id']]]],
            ],
        ];

        $memo = [];
        self::assertTrue($this->evaluator->evaluateNode($node, $competitor['id'], $memo));
    }

    // --- sub_challenge_threshold (recursive) --------------------------------

    public function testSubChallengeThresholdCountsCompletedSubChallenges(): void
    {
        $competitor = $this->createUser('Sub Threshold');

        [$eventBronze1, $distBronze1] = $this->makeEvent('sub-bronze-1');
        $bronze1 = $this->createChallenge([
            'name' => 'Bronze 1',
            'conditionTree' => ['type' => 'event_threshold', 'requiredCount' => 1, 'events' => [['eventId' => $eventBronze1['id']]]],
        ]);
        [$eventBronze2] = $this->makeEvent('sub-bronze-2');
        $bronze2 = $this->createChallenge([
            'name' => 'Bronze 2',
            'conditionTree' => ['type' => 'event_threshold', 'requiredCount' => 1, 'events' => [['eventId' => $eventBronze2['id']]]],
        ]);
        [$eventBronze3] = $this->makeEvent('sub-bronze-3');
        $bronze3 = $this->createChallenge([
            'name' => 'Bronze 3',
            'conditionTree' => ['type' => 'event_threshold', 'requiredCount' => 1, 'events' => [['eventId' => $eventBronze3['id']]]],
        ]);

        // Competitor only completes Bronze 1's event.
        $this->finishResult($eventBronze1, $distBronze1, $competitor['id'], $competitor['name']);

        $node = [
            'type' => 'sub_challenge_threshold',
            'requiredCount' => 2, // "3 brazowe, ukoncz 2 z 3" - simplified to 2-of-3 here
            'subChallenges' => [
                ['challengeId' => $bronze1['id']],
                ['challengeId' => $bronze2['id']],
                ['challengeId' => $bronze3['id']],
            ],
        ];

        $memo = [];
        self::assertFalse($this->evaluator->evaluateNode($node, $competitor['id'], $memo));

        // Now complete Bronze 2 as well -> 2 of 3 -> satisfied.
        [$eventBronze2Again, $distBronze2] = [$eventBronze2, $this->events->find($eventBronze2['id'])['distances'][0]];
        $this->finishResult($eventBronze2Again, $distBronze2, $competitor['id'], $competitor['name']);

        $memo = [];
        self::assertTrue($this->evaluator->evaluateNode($node, $competitor['id'], $memo));
    }

    public function testSubChallengeThresholdMemoizesRepeatedReferences(): void
    {
        // Same sub-challenge referenced from two sibling nodes inside one
        // group must only be walked once per evaluation pass (shared $memo).
        $competitor = $this->createUser('Sub Memo');
        [$event, $dist] = $this->makeEvent('sub-memo');
        $sub = $this->createChallenge([
            'name' => 'Shared Sub',
            'conditionTree' => ['type' => 'event_threshold', 'requiredCount' => 1, 'events' => [['eventId' => $event['id']]]],
        ]);
        $this->finishResult($event, $dist, $competitor['id'], $competitor['name']);

        $node = [
            'type' => 'group',
            'requiredCount' => 2,
            'children' => [
                ['type' => 'sub_challenge_threshold', 'requiredCount' => 1, 'subChallenges' => [['challengeId' => $sub['id']]]],
                ['type' => 'sub_challenge_threshold', 'requiredCount' => 1, 'subChallenges' => [['challengeId' => $sub['id']]]],
            ],
        ];

        $memo = [];
        self::assertTrue($this->evaluator->evaluateNode($node, $competitor['id'], $memo));
        self::assertArrayHasKey($sub['id'] . '|' . $competitor['id'], $memo);
    }

    public function testSubChallengeThresholdWindowUsesParticipationCompletionTimestamp(): void
    {
        $competitor = $this->createUser('Sub Window');
        [$event, $dist] = $this->makeEvent('sub-window');
        $sub = $this->createChallenge([
            'name' => 'Windowed Sub',
            'conditionTree' => ['type' => 'event_threshold', 'requiredCount' => 1, 'events' => [['eventId' => $event['id']]]],
        ]);
        $this->finishResult($event, $dist, $competitor['id'], $competitor['name']);

        // Create the participation and stamp completion_notified_at OUTSIDE the
        // node's window (2020) to prove the window is actually enforced.
        $this->pdo->prepare(
            "INSERT INTO challenge_participations (id, challenge_id, user_id, user_name, joined_at, reward_granted, selected_rewards, completion_notified_at)
             VALUES (:id, :challengeId, :userId, :userName, NOW(), 0, '[]', '2020-05-01T00:00:00+00:00')"
        )->execute([
            'id' => bin2hex(random_bytes(16)),
            'challengeId' => $sub['id'],
            'userId' => $competitor['id'],
            'userName' => $competitor['name'],
        ]);

        $node = [
            'type' => 'sub_challenge_threshold',
            'requiredCount' => 1,
            'windowStart' => '2026-01-01',
            'windowEnd' => '2026-12-31',
            'subChallenges' => [['challengeId' => $sub['id']]],
        ];

        $memo = [];
        self::assertFalse($this->evaluator->evaluateNode($node, $competitor['id'], $memo), 'Completion timestamp from 2020 is outside the 2026 window.');

        // Widen the window to include 2020 -> now satisfied.
        $node['windowStart'] = '2019-01-01';
        $memo = [];
        self::assertTrue($this->evaluator->evaluateNode($node, $competitor['id'], $memo));
    }

    public function testSubChallengeThresholdWindowFallsBackToApprovedEntryTimestampWithoutParticipation(): void
    {
        $competitor = $this->createUser('Sub Window Fallback');
        // A real Event row is required: event_threshold counts manual entries through an INNER JOIN on events.
        [$event] = $this->makeEvent('sub-fallback');
        $sub = $this->createChallenge([
            'name' => 'Fallback Sub',
            'conditionTree' => ['type' => 'event_threshold', 'requiredCount' => 1, 'events' => [['eventId' => $event['id']]]],
        ]);
        // No challenge_participations row at all for this competitor+sub -
        // only a manually-approved entry, whose reviewed_at is used as the
        // completion timestamp fallback.
        $this->approveEntry((string) $sub['id'], $competitor['id'], (string) $event['id']);

        $node = [
            'type' => 'sub_challenge_threshold',
            'requiredCount' => 1,
            // +/- 1 day around "now": reviewed_at is NOW() on the DB side; the margin absorbs any
            // DB/PHP timezone offset and avoids the Dec-31 trap of date('Y-12-31') (= 00:00).
            'windowStart' => date('Y-m-d', strtotime('-1 day')),
            'windowEnd' => date('Y-m-d', strtotime('+1 day')),
            'subChallenges' => [['challengeId' => $sub['id']]],
        ];

        $memo = [];
        self::assertTrue($this->evaluator->evaluateNode($node, $competitor['id'], $memo), 'Entry reviewed just now falls inside a window around today via the fallback timestamp.');
    }

    // --- multi-level tree end-to-end via ChallengeRepository ----------------

    public function testMultiLevelTreeEvaluatesEndToEndThroughChallengeRepository(): void
    {
        $competitor = $this->createUser('Multi Level');

        [$eventA, $distA] = $this->makeEvent('multi-a');
        [$eventB, $distB] = $this->makeEvent('multi-b');
        $this->finishResult($eventA, $distA, $competitor['id'], $competitor['name'], 1);
        $this->finishResult($eventB, $distB, $competitor['id'], $competitor['name'], 1);

        $this->pointsSettings->updateSettings(['pointsTable' => [100], 'floorPoints' => 0]);

        // Root: group requiring BOTH an event_threshold AND a point_threshold.
        $challenge = $this->createChallenge([
            'name' => 'Grand Multi',
            'conditionTree' => [
                'type' => 'group',
                'requiredCount' => 2,
                'children' => [
                    ['type' => 'event_threshold', 'requiredCount' => 2, 'events' => [['eventId' => $eventA['id']], ['eventId' => $eventB['id']]]],
                    ['type' => 'point_threshold', 'pointsRequired' => 100, 'bestResultsCount' => 1, 'events' => [['eventId' => $eventA['id']]]],
                ],
            ],
        ]);

        $joined = $this->challenges->join((string) $challenge['id'], ['id' => $competitor['id'], 'name' => $competitor['name']]);
        self::assertTrue($joined['isCompleted']);

        $completion = $this->challenges->checkCompletion((string) $challenge['id'], $competitor['id']);
        self::assertNotNull($completion);
        self::assertSame('Grand Multi', $completion['challengeName']);

        // Idempotent: a second call after the same state must not re-notify.
        self::assertNull($this->challenges->checkCompletion((string) $challenge['id'], $competitor['id']));
    }

    public function testMultiLevelTreeNotYetSatisfiedReportsNotCompleted(): void
    {
        $competitor = $this->createUser('Multi Level Incomplete');
        [$eventA, $distA] = $this->makeEvent('multi-inc-a');
        [$eventB] = $this->makeEvent('multi-inc-b'); // never finished

        $this->finishResult($eventA, $distA, $competitor['id'], $competitor['name']);

        $challenge = $this->createChallenge([
            'name' => 'Grand Multi Incomplete',
            'conditionTree' => [
                'type' => 'group',
                'requiredCount' => 2,
                'children' => [
                    ['type' => 'event_threshold', 'requiredCount' => 1, 'events' => [['eventId' => $eventA['id']]]],
                    ['type' => 'event_threshold', 'requiredCount' => 1, 'events' => [['eventId' => $eventB['id']]]],
                ],
            ],
        ]);

        $joined = $this->challenges->join((string) $challenge['id'], ['id' => $competitor['id'], 'name' => $competitor['name']]);
        self::assertFalse($joined['isCompleted']);
        self::assertNull($this->challenges->checkCompletion((string) $challenge['id'], $competitor['id']));
    }

    // --- tree-less legacy fallback + stale-tree warning path -----------------

    /**
     * A challenge row inserted directly via SQL (bypassing
     * ChallengeRepository::create(), which always seeds a default tree) has
     * NO row in challenge_condition_nodes at all - getConditionTree() returns
     * null. isChallengeCompletedForUser() must fall back to the legacy flat
     * `completedCount >= minCompleted` rule rather than treating "no tree" as
     * "never completed" - this is exactly the shape
     * tests/Integration/ChallengeCompletionTest.php's fixtures use, and this
     * test pins the same behaviour directly against the evaluator wiring.
     */
    public function testTreeLessChallengeFallsBackToFlatMinCompletedRule(): void
    {
        $id = $this->prefix . '-legacy-' . bin2hex(random_bytes(4));
        $this->challengeIds[] = $id;
        $this->pdo->prepare(
            "INSERT INTO challenges (id, name, slug, description, min_completed, status, created_by)
             VALUES (:id, 'Legacy No Tree', :slug, 'desc', 1, 'active', 'tester')"
        )->execute(['id' => $id, 'slug' => $id]);
        self::assertNull($this->challenges->getConditionTree($id));

        $competitor = $this->createUser('Legacy Flat');
        $this->pdo->prepare(
            "INSERT INTO challenge_participations (id, challenge_id, user_id, user_name, joined_at, reward_granted, selected_rewards)
             VALUES (:id, :challengeId, :userId, :userName, NOW(), 0, '[]')"
        )->execute(['id' => bin2hex(random_bytes(16)), 'challengeId' => $id, 'userId' => $competitor['id'], 'userName' => $competitor['name']]);
        $this->approveEntry($id, $competitor['id'], 'evt-legacy');

        $completion = $this->challenges->checkCompletion($id, $competitor['id']);
        self::assertNotNull($completion, 'Tree-less challenge must still complete via the flat minCompleted fallback.');
    }

    /**
     * A tree that is still shaped like the plain default (event_threshold,
     * no window) but has drifted from the flat `events` list
     * (conditionTreeMatchesEvents = false) must still be evaluated
     * automatically - the evaluator deliberately does not block completion on this
     * flag, it only logs a warning (see isChallengeCompletedForUser()'s doc
     * comment). This test proves completion still computes correctly against
     * the (possibly stale) tree rather than refusing to run.
     */
    public function testStaleDefaultTreeStillEvaluatesRatherThanBlocking(): void
    {
        $competitor = $this->createUser('Stale Tree');
        [$eventOld, $distOld] = $this->makeEvent('stale-old');
        [$eventNew] = $this->makeEvent('stale-new');

        $challenge = $this->createChallenge([
            'name' => 'Stale Tree Challenge',
            'events' => [$eventOld['id']],
        ]);
        self::assertTrue($challenge['conditionTreeMatchesEvents']);

        // Edit the flat events list WITHOUT touching conditionTree explicitly
        // and in a way that update()'s auto-resync guard does not cover
        // (adding an event on top of the existing one changes requiredCount
        // parity is fine, but simulate genuine drift by editing via raw SQL
        // as if an out-of-band process touched challenge_events directly).
        $this->pdo->prepare('DELETE FROM challenge_events WHERE challenge_id = :id')->execute(['id' => $challenge['id']]);
        $this->pdo->prepare('INSERT INTO challenge_events (challenge_id, event_id, sort_order) VALUES (:id, :eventId, 0)')
            ->execute(['id' => $challenge['id'], 'eventId' => $eventNew['id']]);

        $reread = $this->challenges->find((string) $challenge['id']);
        self::assertFalse($reread['conditionTreeMatchesEvents'], 'Sanity check: tree now diverges from the flat events list.');
        self::assertSame('event_threshold', $reread['conditionTree']['type']);

        // The (now stale) tree still points at $eventOld - competitor finishes it.
        $this->finishResult($eventOld, $distOld, $competitor['id'], $competitor['name']);

        $joined = $this->challenges->join((string) $challenge['id'], ['id' => $competitor['id'], 'name' => $competitor['name']]);
        self::assertTrue($joined['isCompleted'], 'Stale tree is still evaluated (with a warning), not blocked.');
    }

    // --- recursion depth guard ------------------------------------------------

    public function testDeeplyNestedGroupTripsRecursionDepthGuard(): void
    {
        // Build a pure in-memory chain of nested `group` nodes far beyond any
        // real tree (assertValidTree()'s cycle check is not involved here -
        // group nesting never touches challenge_condition_node_sub_challenges)
        // to prove the depth guard is real defense-in-depth, not vaporware.
        $node = ['type' => 'event_threshold', 'requiredCount' => 0, 'events' => []];
        for ($i = 0; $i < 40; $i++) {
            $node = ['type' => 'group', 'requiredCount' => 1, 'children' => [$node]];
        }

        $memo = [];
        $this->expectException(\RuntimeException::class);
        $this->evaluator->evaluateNode($node, 'irrelevant-user', $memo);
    }

    private function deletePhpUnitData(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => $this->prefix . '%']);
        foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $eventId) {
            $this->pdo->prepare('DELETE FROM results WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distance_pricing_variants WHERE distance_id IN (SELECT id FROM event_distances WHERE event_id = :id)')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '.%@example.test']);
    }
}

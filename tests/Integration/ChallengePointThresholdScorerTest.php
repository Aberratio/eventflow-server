<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Challenges\ChallengePointsSettingsRepository;
use ZmierzymyCzas\Domain\Challenges\ChallengePointThresholdScorer;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;

/**
 * E6.2 (issue 15 §2): scoring a `point_threshold` node for one competitor —
 * place-to-points lookup via the global table (ChallengePointsSettingsRepository)
 * plus "N best of the linked Events" selection.
 */
final class ChallengePointThresholdScorerTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private ResultRepository $results;
    private UserRepository $users;
    private ChallengePointsSettingsRepository $pointsSettings;
    private ChallengePointThresholdScorer $scorer;
    private string $prefix;
    /** @var array{pointsTable: list<int>, floorPoints: int} */
    private array $originalSettings;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->prefix = 'phpscore-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->deletePhpUnitData();
            $this->events = new EventRepository($this->pdo);
            $this->results = new ResultRepository($this->pdo);
            $this->users = new UserRepository($this->pdo);
            $this->pointsSettings = new ChallengePointsSettingsRepository($this->pdo);
            $this->originalSettings = $this->pointsSettings->getSettings();
            $this->scorer = new ChallengePointThresholdScorer($this->pdo, $this->pointsSettings);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for challenge points scorer tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pointsSettings->updateSettings($this->originalSettings);
        $this->deletePhpUnitData();
    }

    /** @return array{0: array<string,mixed>, 1: array<string,mixed>} [event, competitor] */
    private function makeEventAndCompetitor(string $suffix): array
    {
        $event = $this->events->create([
            'name' => 'PHPUnit Score Event ' . $suffix,
            'slug' => $this->prefix . '-event-' . $suffix,
            'discipline' => 'running',
            'date' => '2026-06-15',
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

    /** Creates a `finished` result for $userId at a controlled place, by seeding faster "filler" finishers ahead of them. */
    private function placeUserAt(array $event, array $distance, string $userId, string $userName, int $place): void
    {
        for ($i = 1; $i < $place; $i++) {
            $filler = $this->createUser('Filler ' . $distance['id'] . ' ' . $i);
            $this->results->create([
                'eventId' => $event['id'],
                'eventName' => $event['name'],
                'distanceId' => $distance['id'],
                'distanceName' => $distance['name'],
                'userId' => $filler['id'],
                'userName' => $filler['name'],
                'bibNumber' => (string) $i,
                'category' => 'Open',
                'time' => '0:28:00',
                'timeInSeconds' => 1700 + $i, // strictly faster than the target's fixed time below, ranks ahead of them
                'position' => 0,
                'categoryPosition' => 0,
                'status' => 'finished',
            ]);
        }
        $this->results->create([
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'distanceId' => $distance['id'],
            'distanceName' => $distance['name'],
            'userId' => $userId,
            'userName' => $userName,
            'bibNumber' => '999',
            'category' => 'Open',
            'time' => '0:30:00',
            'timeInSeconds' => 1800, // slower than all $place-1 fillers above -> lands at exactly $place
            'position' => 0,
            'categoryPosition' => 0,
            'status' => 'finished',
        ]);
    }

    public function testCompetitorWithMoreResultsThanNTakesOnlyTheTopN(): void
    {
        $this->pointsSettings->updateSettings(['pointsTable' => [100, 90, 80, 70, 60], 'floorPoints' => 0]);
        $competitor = $this->createUser('Best N Runner');

        $eventIds = [];
        $places = [1, 3, 5]; // -> 100, 80, 60 points
        foreach ($places as $i => $place) {
            [$event, $distance] = $this->makeEventAndCompetitor((string) $i);
            $this->placeUserAt($event, $distance, $competitor['id'], $competitor['name'], $place);
            $eventIds[] = $event['id'];
        }

        $node = [
            'type' => 'point_threshold',
            'pointsRequired' => 150,
            'bestResultsCount' => 2,
            'events' => array_map(static fn (string $id): array => ['eventId' => $id], $eventIds),
        ];

        $result = $this->scorer->score($node, $competitor['id']);

        // Best 2 of [100, 80, 60] = 100 + 80 = 180, not the sum of all three (240).
        self::assertSame(180, $result['totalPoints']);
        self::assertCount(2, $result['breakdown']);
        self::assertTrue($result['satisfied']);
    }

    public function testCompetitorWithFewerResultsThanNTakesWhatTheyHave(): void
    {
        $this->pointsSettings->updateSettings(['pointsTable' => [100, 90, 80], 'floorPoints' => 0]);
        $competitor = $this->createUser('Fewer Results Runner');

        [$event, $distance] = $this->makeEventAndCompetitor('only');
        $this->placeUserAt($event, $distance, $competitor['id'], $competitor['name'], 1);

        [$unplayedEvent] = $this->makeEventAndCompetitor('unplayed');

        $node = [
            'type' => 'point_threshold',
            'pointsRequired' => 50,
            'bestResultsCount' => 5, // asks for 5 best, competitor only has 1 qualifying result
            'events' => [['eventId' => $event['id']], ['eventId' => $unplayedEvent['id']]],
        ];

        $result = $this->scorer->score($node, $competitor['id']);

        self::assertSame(100, $result['totalPoints']);
        self::assertCount(1, $result['breakdown']);
        self::assertTrue($result['satisfied']);
    }

    public function testTiedPlacementPointsAreSummedLikeAnyOtherScore(): void
    {
        // A points table with a genuine tie (two places worth the same
        // points) - issue 15 §2's "remisy" note is about competitors tying
        // in the Wyzwanie's own points classification (out of this ticket's
        // scope, see ChallengePointThresholdScorer's class doc comment), but
        // this proves the scorer itself has no special-case bug when two of
        // a *single* competitor's own results happen to be worth equal
        // points - they are simply both counted.
        $this->pointsSettings->updateSettings(['pointsTable' => [100, 100, 50], 'floorPoints' => 0]);
        $competitor = $this->createUser('Tied Points Runner');

        [$eventA, $distanceA] = $this->makeEventAndCompetitor('tieA');
        [$eventB, $distanceB] = $this->makeEventAndCompetitor('tieB');
        $this->placeUserAt($eventA, $distanceA, $competitor['id'], $competitor['name'], 1);
        $this->placeUserAt($eventB, $distanceB, $competitor['id'], $competitor['name'], 2);

        $node = [
            'type' => 'point_threshold',
            'pointsRequired' => 199,
            'bestResultsCount' => 2,
            'events' => [['eventId' => $eventA['id']], ['eventId' => $eventB['id']]],
        ];

        $result = $this->scorer->score($node, $competitor['id']);

        self::assertSame(200, $result['totalPoints']); // 100 (place 1) + 100 (place 2, tied value)
        self::assertTrue($result['satisfied']);
    }

    public function testCompetitorWithZeroQualifyingResultsScoresZeroAndIsUnsatisfied(): void
    {
        $competitor = $this->createUser('No Results Runner');
        [$event] = $this->makeEventAndCompetitor('none');

        $node = [
            'type' => 'point_threshold',
            'pointsRequired' => 1,
            'bestResultsCount' => 3,
            'events' => [['eventId' => $event['id']]],
        ];

        $result = $this->scorer->score($node, $competitor['id']);

        self::assertSame(0, $result['totalPoints']);
        self::assertSame([], $result['breakdown']);
        self::assertFalse($result['satisfied']);
    }

    public function testDnfResultDoesNotCountTowardScore(): void
    {
        $this->pointsSettings->updateSettings(['pointsTable' => [100], 'floorPoints' => 0]);
        $competitor = $this->createUser('DNF Runner');
        [$event, $distance] = $this->makeEventAndCompetitor('dnf');

        $this->results->create([
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'distanceId' => $distance['id'],
            'distanceName' => $distance['name'],
            'userId' => $competitor['id'],
            'userName' => $competitor['name'],
            'bibNumber' => '1',
            'category' => 'Open',
            'time' => '',
            'timeInSeconds' => 0,
            'position' => 0,
            'categoryPosition' => 0,
            'status' => 'dnf',
        ]);

        $node = [
            'type' => 'point_threshold',
            'pointsRequired' => 1,
            'bestResultsCount' => 1,
            'events' => [['eventId' => $event['id']]],
        ];

        $result = $this->scorer->score($node, $competitor['id']);

        self::assertSame(0, $result['totalPoints']);
        self::assertFalse($result['satisfied']);
    }

    public function testNoBestResultsCountMeansSumOfAllLinkedResults(): void
    {
        $this->pointsSettings->updateSettings(['pointsTable' => [100, 90, 80], 'floorPoints' => 0]);
        $competitor = $this->createUser('No Cap Runner');

        $eventIds = [];
        foreach ([1, 2, 3] as $i => $place) {
            [$event, $distance] = $this->makeEventAndCompetitor('nocap' . $i);
            $this->placeUserAt($event, $distance, $competitor['id'], $competitor['name'], $place);
            $eventIds[] = $event['id'];
        }

        $node = [
            'type' => 'point_threshold',
            'pointsRequired' => 1,
            'bestResultsCount' => null,
            'events' => array_map(static fn (string $id): array => ['eventId' => $id], $eventIds),
        ];

        $result = $this->scorer->score($node, $competitor['id']);

        self::assertSame(270, $result['totalPoints']); // 100 + 90 + 80, all three count
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

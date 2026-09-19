<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Challenges\ChallengeRepository;
use ZmierzymyCzas\Support\Env;

/**
 * E6.1 (issue 15 §1): the condition tree — schema + admin CRUD only, no
 * scoring/verification (those are E6.2/E6.3, separate tickets).
 *
 * ChallengeRepository::create() always mints its own id (Repository::id(),
 * a UUID) regardless of what the caller's $data contains, so every test here
 * captures the *returned* id rather than assuming one it supplied is honoured.
 */
final class ChallengeConditionTreeTest extends TestCase
{
    private PDO $pdo;
    private ChallengeRepository $challenges;
    /** @var list<string> */
    private array $challengeIds = [];

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for challenge condition tree tests: ' . $e->getMessage());
        }

        $this->challenges = new ChallengeRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        foreach (array_reverse($this->challengeIds) as $id) {
            $this->pdo->prepare('DELETE FROM challenges WHERE id = :id')->execute(['id' => $id]);
        }
    }

    private function slug(string $prefix): string
    {
        return 'phpunit-' . $prefix . '-' . bin2hex(random_bytes(4));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function createChallenge(array $data): array
    {
        $slug = $data['slug'] ?? $this->slug('chal');
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

    public function testCreateWithoutConditionTreeBuildsDefaultEventThresholdRoot(): void
    {
        $challenge = $this->createChallenge([
            'name' => 'Flat challenge',
            'events' => ['evt-1', 'evt-2', 'evt-3'],
        ]);

        $tree = $challenge['conditionTree'];
        self::assertSame('event_threshold', $tree['type']);
        self::assertSame(3, $tree['requiredCount']);
        self::assertCount(3, $tree['events']);
        self::assertSame(['evt-1', 'evt-2', 'evt-3'], array_column($tree['events'], 'eventId'));
    }

    public function testCreateAndReadBackMultiLevelTree(): void
    {
        $challenge = $this->createChallenge([
            'name' => 'Tree challenge',
            'conditionTree' => [
                'type' => 'group',
                'requiredCount' => 2,
                'children' => [
                    [
                        'type' => 'event_threshold',
                        'requiredCount' => 2,
                        'windowStart' => '2026-01-01',
                        'windowEnd' => '2026-12-31',
                        'events' => [['eventId' => 'evt-spring-1'], ['eventId' => 'evt-spring-2'], ['eventId' => 'evt-spring-3']],
                    ],
                    [
                        'type' => 'point_threshold',
                        'pointsRequired' => 150,
                        'bestResultsCount' => 3,
                        'events' => [['eventId' => 'evt-a'], ['eventId' => 'evt-b']],
                    ],
                ],
            ],
        ]);

        $tree = $challenge['conditionTree'];
        self::assertSame('group', $tree['type']);
        self::assertSame(2, $tree['requiredCount']);
        self::assertCount(2, $tree['children']);

        $eventThreshold = $tree['children'][0];
        self::assertSame('event_threshold', $eventThreshold['type']);
        self::assertSame(2, $eventThreshold['requiredCount']);
        self::assertSame('2026-01-01', $eventThreshold['windowStart']);
        self::assertCount(3, $eventThreshold['events']);

        $pointThreshold = $tree['children'][1];
        self::assertSame('point_threshold', $pointThreshold['type']);
        self::assertSame(150, $pointThreshold['pointsRequired']);
        self::assertSame(3, $pointThreshold['bestResultsCount']);
        self::assertCount(2, $pointThreshold['events']);

        // Re-fetch through find() (not the create() return value) to prove
        // hydrate() embeds the same tree on a plain read.
        $reread = $this->challenges->find((string) $challenge['id']);
        self::assertSame($tree, $reread['conditionTree']);
    }

    public function testSubChallengeThresholdReferencesAnAlreadySavedChallenge(): void
    {
        $sub = $this->createChallenge(['name' => 'Sub']);

        $parent = $this->createChallenge([
            'name' => 'Parent',
            'conditionTree' => [
                'type' => 'sub_challenge_threshold',
                'requiredCount' => 1,
                'subChallenges' => [['challengeId' => $sub['id']]],
            ],
        ]);

        $tree = $parent['conditionTree'];
        self::assertSame('sub_challenge_threshold', $tree['type']);
        self::assertSame(1, $tree['requiredCount']);
        self::assertSame([$sub['id']], array_column($tree['subChallenges'], 'challengeId'));
    }

    public function testSubChallengeThresholdRejectsSelfReference(): void
    {
        $challenge = $this->createChallenge(['name' => 'Self referencer']);

        $this->expectException(\InvalidArgumentException::class);
        $this->challenges->saveConditionTree((string) $challenge['id'], [
            'type' => 'sub_challenge_threshold',
            'requiredCount' => 1,
            'subChallenges' => [['challengeId' => $challenge['id']]],
        ]);
    }

    public function testSubChallengeThresholdRejectsUnsavedChallengeReference(): void
    {
        $challenge = $this->createChallenge(['name' => 'Lone wolf']);

        $this->expectException(\InvalidArgumentException::class);
        $this->challenges->saveConditionTree((string) $challenge['id'], [
            'type' => 'sub_challenge_threshold',
            'requiredCount' => 1,
            'subChallenges' => [['challengeId' => 'does-not-exist-' . bin2hex(random_bytes(4))]],
        ]);
    }

    public function testCycleThroughSubChallengeTreeIsRejectedWithNoPartialWrite(): void
    {
        // A references B (down the tree — allowed, B already exists).
        $b = $this->createChallenge(['name' => 'B']);
        $a = $this->createChallenge([
            'name' => 'A',
            'conditionTree' => [
                'type' => 'sub_challenge_threshold',
                'requiredCount' => 1,
                'subChallenges' => [['challengeId' => $b['id']]],
            ],
        ]);

        // Now try to make B reference A back — a cycle (A -> B -> A).
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->challenges->saveConditionTree((string) $b['id'], [
                'type' => 'sub_challenge_threshold',
                'requiredCount' => 1,
                'subChallenges' => [['challengeId' => $a['id']]],
            ]);
        } finally {
            // B's tree must be untouched by the rejected write.
            $bTree = $this->challenges->getConditionTree((string) $b['id']);
            self::assertNotNull($bTree);
            self::assertSame('event_threshold', $bTree['type']); // still its own create()-time default, not the rejected write
        }
    }

    public function testUpdateReplacesConditionTreeWholesale(): void
    {
        $challenge = $this->createChallenge([
            'name' => 'Updatable',
            'conditionTree' => ['type' => 'event_threshold', 'requiredCount' => 1, 'events' => [['eventId' => 'evt-old']]],
        ]);

        $updated = $this->challenges->update((string) $challenge['id'], [
            'conditionTree' => ['type' => 'event_threshold', 'requiredCount' => 2, 'events' => [['eventId' => 'evt-new-1'], ['eventId' => 'evt-new-2']]],
        ]);

        self::assertSame(2, $updated['conditionTree']['requiredCount']);
        self::assertSame(['evt-new-1', 'evt-new-2'], array_column($updated['conditionTree']['events'], 'eventId'));
    }

    public function testGroupNodeRequiresAtLeastOneChild(): void
    {
        $challenge = $this->createChallenge(['name' => 'Empty group']);

        $this->expectException(\InvalidArgumentException::class);
        $this->challenges->saveConditionTree((string) $challenge['id'], ['type' => 'group', 'requiredCount' => 1, 'children' => []]);
    }

    /**
     * Migration 054's backfill: a challenge row that existed only in the flat
     * shape (challenge_events, no condition_nodes rows — exactly what a
     * pre-054 challenge looked like) gets a single root event_threshold node
     * mirroring its events, with N = M = count. Re-running the migration file
     * must not duplicate it (no ledger table; idempotency is what makes that
     * safe — see Migrator's class doc comment).
     */
    public function testBackfillMigrationGivesLegacyChallengeASingleNodeTreeMatchingItsEvents(): void
    {
        $id = 'phpunit-legacy-' . bin2hex(random_bytes(4));
        $this->challengeIds[] = $id;
        $this->pdo->prepare(
            "INSERT INTO challenges (id, name, slug, description, min_completed, status, created_by)
             VALUES (:id, 'Legacy challenge', :slug, 'desc', 2, 'active', 'tester')"
        )->execute(['id' => $id, 'slug' => $id]);
        foreach (['evt-legacy-1', 'evt-legacy-2'] as $index => $eventId) {
            $this->pdo->prepare(
                'INSERT INTO challenge_events (challenge_id, event_id, sort_order) VALUES (:challengeId, :eventId, :sortOrder)'
            )->execute(['challengeId' => $id, 'eventId' => $eventId, 'sortOrder' => $index]);
        }
        self::assertNull($this->challenges->getConditionTree($id), 'Sanity check: no tree yet, this row mimics pre-054 state.');

        $migrationFile = Migrator::migrationsDir() . '/054_e6_1_challenge_condition_tree.sql';
        Migrator::applyFile($this->pdo, $migrationFile);
        Migrator::applyFile($this->pdo, $migrationFile); // idempotency: applying twice must not duplicate rows

        $tree = $this->challenges->getConditionTree($id);
        self::assertNotNull($tree);
        self::assertSame('event_threshold', $tree['type']);
        self::assertSame(2, $tree['requiredCount']);
        self::assertSame(['evt-legacy-1', 'evt-legacy-2'], array_column($tree['events'], 'eventId'));

        $count = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM challenge_condition_nodes WHERE challenge_id = ' . $this->pdo->quote($id)
        )->fetchColumn();
        self::assertSame(1, $count, 'Re-running the backfill must not create a second root node.');
    }
}

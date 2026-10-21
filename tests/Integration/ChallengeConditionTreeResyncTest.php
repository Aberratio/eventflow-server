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
 * Resync-gap fix (a known gap flagged by the condition tree's own
 * verification): PATCH /challenges/{id} editing the legacy flat `events`
 * list used to leave the condition tree untouched unless the caller
 * also sent an explicit `conditionTree` — silently stale, and load-bearing
 * now that the tree drives real scoring decisions.
 *
 * See ChallengeRepository::update()'s doc comment for the chosen fix: only
 * the unambiguous "still the create()-time default tree" shape is
 * auto-resynced; anything an admin has customized is left alone but always
 * reported via `conditionTreeMatchesEvents`.
 */
final class ChallengeConditionTreeResyncTest extends TestCase
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
            self::markTestSkipped('Database is not available for challenge condition tree resync tests: ' . $e->getMessage());
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

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function createChallenge(array $data): array
    {
        $slug = $data['slug'] ?? ('phpunit-resync-' . bin2hex(random_bytes(4)));
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

    public function testEditingFlatEventsAutoResyncsTheDefaultTree(): void
    {
        $challenge = $this->createChallenge(['events' => ['evt-1', 'evt-2']]);
        self::assertSame(['evt-1', 'evt-2'], array_column($challenge['conditionTree']['events'], 'eventId'));
        self::assertTrue($challenge['conditionTreeMatchesEvents']);

        $updated = $this->challenges->update((string) $challenge['id'], ['events' => ['evt-3', 'evt-4', 'evt-5']]);

        self::assertSame('event_threshold', $updated['conditionTree']['type']);
        self::assertSame(['evt-3', 'evt-4', 'evt-5'], array_column($updated['conditionTree']['events'], 'eventId'));
        self::assertSame(3, $updated['conditionTree']['requiredCount']);
        self::assertTrue($updated['conditionTreeMatchesEvents']);
    }

    public function testCustomizedTreeIsNotOverwrittenByAFlatEventsEdit(): void
    {
        $challenge = $this->createChallenge([
            'events' => ['evt-1', 'evt-2'],
            'conditionTree' => [
                'type' => 'event_threshold',
                'requiredCount' => 1,
                // Deliberately a SUBSET of the flat events list — an admin
                // scoping the tree tighter than the flat list.
                'events' => [['eventId' => 'evt-1']],
            ],
        ]);
        self::assertFalse($challenge['conditionTreeMatchesEvents']);

        $updated = $this->challenges->update((string) $challenge['id'], ['events' => ['evt-1', 'evt-2', 'evt-3']]);

        // Flat list changed...
        self::assertSame(['evt-1', 'evt-2', 'evt-3'], array_column($updated['events'], 'eventId'));
        // ...but the customized tree must be left exactly as the admin set it.
        self::assertSame(1, $updated['conditionTree']['requiredCount']);
        self::assertSame(['evt-1'], array_column($updated['conditionTree']['events'], 'eventId'));
        self::assertFalse($updated['conditionTreeMatchesEvents']);
    }

    public function testGroupRootTreeIsNeverAutoResyncedByAFlatEventsEdit(): void
    {
        $challenge = $this->createChallenge([
            'events' => ['evt-1'],
            'conditionTree' => [
                'type' => 'group',
                'requiredCount' => 1,
                'children' => [
                    ['type' => 'event_threshold', 'requiredCount' => 1, 'events' => [['eventId' => 'evt-1']]],
                ],
            ],
        ]);
        self::assertFalse($challenge['conditionTreeMatchesEvents']);

        $updated = $this->challenges->update((string) $challenge['id'], ['events' => ['evt-1', 'evt-9']]);

        self::assertSame('group', $updated['conditionTree']['type']);
        self::assertFalse($updated['conditionTreeMatchesEvents']);
    }

    public function testExplicitConditionTreeInThePayloadStillTakesPrecedenceOverAutoResync(): void
    {
        $challenge = $this->createChallenge(['events' => ['evt-1', 'evt-2']]);

        $updated = $this->challenges->update((string) $challenge['id'], [
            'events' => ['evt-1', 'evt-2', 'evt-3'],
            'conditionTree' => ['type' => 'event_threshold', 'requiredCount' => 1, 'events' => [['eventId' => 'evt-1']]],
        ]);

        self::assertSame(1, $updated['conditionTree']['requiredCount']);
        self::assertSame(['evt-1'], array_column($updated['conditionTree']['events'], 'eventId'));
    }

    public function testUpdatingUnrelatedFieldsLeavesTheTreeAndFlagUntouched(): void
    {
        $challenge = $this->createChallenge(['events' => ['evt-1', 'evt-2']]);

        $updated = $this->challenges->update((string) $challenge['id'], ['description' => 'new desc']);

        self::assertSame($challenge['conditionTree'], $updated['conditionTree']);
        self::assertTrue($updated['conditionTreeMatchesEvents']);
    }
}

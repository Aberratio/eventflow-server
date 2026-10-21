<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Challenges\ChallengePointsSettingsRepository;
use ZmierzymyCzas\Support\Env;

/**
 * The global, admin-configurable points-per-place table.
 * Migration 055 seeds a fixed singleton row — these tests read/restore it so
 * they can run repeatedly without leaving global state mutated for other
 * suites (this settings row is shared, unlike per-test challenge/event rows).
 */
final class ChallengePointsSettingsRepositoryTest extends TestCase
{
    private PDO $pdo;
    private ChallengePointsSettingsRepository $repo;
    /** @var array{pointsTable: list<int>, floorPoints: int} */
    private array $original;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for challenge points settings tests: ' . $e->getMessage());
        }

        $this->repo = new ChallengePointsSettingsRepository($this->pdo);
        $this->original = $this->repo->getSettings();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->repo->updateSettings($this->original);
    }

    public function testDefaultSeedIsADescendingTableWithAFloor(): void
    {
        // Sanity check on the migration 055 seed shape — not the current
        // (possibly admin-edited) live values, since another test run could
        // have changed them; just confirm getSettings() always returns a
        // well-formed table + floor.
        $settings = $this->original;
        self::assertNotEmpty($settings['pointsTable']);
        self::assertGreaterThanOrEqual(0, $settings['floorPoints']);
        foreach ($settings['pointsTable'] as $index => $points) {
            self::assertGreaterThanOrEqual(0, $points);
            if ($index > 0) {
                self::assertLessThanOrEqual($settings['pointsTable'][$index - 1], $points, 'points-per-place table must be non-increasing');
            }
        }
    }

    public function testUpdateSettingsReplacesTheWholeTableAndFloor(): void
    {
        $updated = $this->repo->updateSettings(['pointsTable' => [50, 30, 10], 'floorPoints' => 2]);

        self::assertSame([50, 30, 10], $updated['pointsTable']);
        self::assertSame(2, $updated['floorPoints']);

        // Round-trips through a fresh read too.
        self::assertSame($updated, $this->repo->getSettings());
    }

    public function testUpdateSettingsIsPartialLikeInsuranceSettings(): void
    {
        $this->repo->updateSettings(['pointsTable' => [40, 20], 'floorPoints' => 5]);

        $updated = $this->repo->updateSettings(['floorPoints' => 9]);

        self::assertSame([40, 20], $updated['pointsTable'], 'omitted pointsTable must be preserved');
        self::assertSame(9, $updated['floorPoints']);
    }

    public function testUpdateSettingsCoercesAndFloorsNegativeValuesToZero(): void
    {
        $updated = $this->repo->updateSettings(['pointsTable' => [10, -5, '7'], 'floorPoints' => -3]);

        self::assertSame([10, 0, 7], $updated['pointsTable']);
        self::assertSame(0, $updated['floorPoints']);
    }

    public function testUpdateSettingsRejectsANonListPointsTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->updateSettings(['pointsTable' => 'not-a-list']);
    }

    public function testPointsForPlaceLooksUpTableThenFallsBackToFloor(): void
    {
        $this->repo->updateSettings(['pointsTable' => [100, 80, 60], 'floorPoints' => 5]);
        $settings = $this->repo->getSettings();

        self::assertSame(100, $this->repo->pointsForPlace(1, $settings));
        self::assertSame(80, $this->repo->pointsForPlace(2, $settings));
        self::assertSame(60, $this->repo->pointsForPlace(3, $settings));
        self::assertSame(5, $this->repo->pointsForPlace(4, $settings), 'place beyond table length falls back to floor');
        self::assertSame(5, $this->repo->pointsForPlace(100, $settings));
    }

    public function testPointsForPlaceRejectsAPlaceBelowOne(): void
    {
        $settings = $this->repo->getSettings();
        self::assertSame(0, $this->repo->pointsForPlace(0, $settings));
        self::assertSame(0, $this->repo->pointsForPlace(-1, $settings));
    }
}

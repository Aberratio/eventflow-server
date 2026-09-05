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
 * E1.10 mail #13 seam: ChallengeRepository::checkCompletion() materializes the
 * "just crossed minCompleted" moment (see its doc comment for why this is
 * needed — hydrateParticipation() only ever *computes* isCompleted on read).
 */
final class ChallengeCompletionTest extends TestCase
{
    private PDO $pdo;
    private ChallengeRepository $challenges;
    private string $challengeId;
    private string $userId;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for challenge completion tests: ' . $e->getMessage());
        }

        $this->challenges = new ChallengeRepository($this->pdo);
        $this->challengeId = 'phpunit-chal-' . bin2hex(random_bytes(4));
        $this->userId = 'phpunit-user-' . bin2hex(random_bytes(4));

        $this->pdo->prepare(
            "INSERT INTO challenges (id, name, slug, description, min_completed, status, created_by)
             VALUES (:id, 'Test Challenge', :slug, 'desc', 2, 'active', 'tester')"
        )->execute(['id' => $this->challengeId, 'slug' => $this->challengeId]);

        $this->pdo->prepare(
            "INSERT INTO challenge_participations (id, challenge_id, user_id, user_name, joined_at, reward_granted, selected_rewards)
             VALUES (:id, :challengeId, :userId, 'Tester', NOW(), 0, '[]')"
        )->execute(['id' => $this->id(), 'challengeId' => $this->challengeId, 'userId' => $this->userId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM challenge_entries WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenge_participations WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenges WHERE id = :id')->execute(['id' => $this->challengeId]);
    }

    private function id(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function addApprovedEntry(string $eventId): void
    {
        $this->pdo->prepare(
            "INSERT INTO challenge_entries (id, challenge_id, user_id, event_id, status, submitted_at, reviewed_at, reviewed_by, source)
             VALUES (:id, :challengeId, :userId, :eventId, 'approved', NOW(), NOW(), 'tester', 'manual')"
        )->execute(['id' => $this->id(), 'challengeId' => $this->challengeId, 'userId' => $this->userId, 'eventId' => $eventId]);
    }

    public function testReturnsNullBelowThreshold(): void
    {
        $this->addApprovedEntry('evt-1');

        self::assertNull($this->challenges->checkCompletion($this->challengeId, $this->userId));
    }

    public function testReturnsCompletionInfoOnceThresholdReached(): void
    {
        $this->addApprovedEntry('evt-1');
        $this->addApprovedEntry('evt-2');

        $result = $this->challenges->checkCompletion($this->challengeId, $this->userId);

        self::assertNotNull($result);
        self::assertSame($this->challengeId, $result['challengeId']);
        self::assertSame($this->userId, $result['userId']);
        self::assertSame('Test Challenge', $result['challengeName']);
    }

    public function testDoesNotReportCompletionTwice(): void
    {
        $this->addApprovedEntry('evt-1');
        $this->addApprovedEntry('evt-2');

        self::assertNotNull($this->challenges->checkCompletion($this->challengeId, $this->userId));
        self::assertNull($this->challenges->checkCompletion($this->challengeId, $this->userId));
    }
}

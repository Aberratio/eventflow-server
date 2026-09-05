<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * E1.10 fix-loop round 2: round 1's testJoinAutoApprovalCrossesThresholdAndCompletionDetectedOnce()
 * (tests/Integration/ChallengeCompletionTest.php) calls
 * ChallengeRepository::join() and checkCompletion() directly — it never goes
 * through src/Http/routes.php at all, so it would pass identically even if
 * the round-1 fix (the shared $notifyChallengeCompletion closure wired into
 * both the review AND join routes) were silently dropped from the join
 * route again. This test closes that gap: it drives the real
 * `POST /challenges/{id}/join` route through HttpTestCase and asserts the
 * side effect only that closure produces on this write path.
 *
 * Central Mailer itself is never configured in this repo's test environment
 * (CENTRAL_MAILER_API_KEY/BASE_URL are blank in .env — see
 * CentralMailerConfig's doc comment), so CriticalMailer::send() always
 * returns null here and no central_mailer_outbox row is ever written,
 * regardless of whether the join route is wired correctly. That is exactly
 * why ChallengeRepository::checkCompletion() marks `completion_notified_at`
 * unconditionally, before the mailer's own isConfigured() gate ever runs
 * (see its doc comment) — that column is the one observable, config-proof
 * fingerprint that $notifyChallengeCompletion actually ran the completion
 * check from inside this route. If the closure were dropped from the join
 * route (reverting to the pre-round-1 code, where only the review route
 * called checkCompletion()), `completion_notified_at` would stay NULL after
 * this join call and this test would fail.
 */
final class ChallengeJoinCompletionContractTest extends HttpTestCase
{
    private UserRepository $users;
    private string $challengeId;
    private string $joiningUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->challengeId = 'http-chal-' . bin2hex(random_bytes(4));
        $this->joiningUserId = '';

        // Mirrors the round-1 fixture (git show cdfc711 --
        // tests/Integration/ChallengeCompletionTest.php): a challenge with
        // min_completed = 2, two challenge_events, and a finished `results`
        // row per event for the joining user — join()'s internal
        // syncResultEntries() auto-approves both from these results alone,
        // no reviewEntry() call anywhere.
        $this->pdo->prepare(
            "INSERT INTO challenges (id, name, slug, description, min_completed, status, created_by)
             VALUES (:id, 'HTTP Auto Challenge', :slug, 'desc', 2, 'active', 'tester')"
        )->execute(['id' => $this->challengeId, 'slug' => $this->challengeId]);

        foreach (['http-evt-a', 'http-evt-b'] as $eventId) {
            $this->pdo->prepare(
                'INSERT INTO challenge_events (challenge_id, event_id, sort_order) VALUES (:challengeId, :eventId, 0)'
            )->execute(['challengeId' => $this->challengeId, 'eventId' => $eventId]);
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->challengeId)) {
            return;
        }
        if ($this->joiningUserId !== '') {
            $this->pdo->prepare('DELETE FROM results WHERE user_id = :userId')->execute(['userId' => $this->joiningUserId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->joiningUserId]);
        }
        $this->pdo->prepare('DELETE FROM challenge_entries WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenge_events WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenge_participations WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenges WHERE id = :id')->execute(['id' => $this->challengeId]);
    }

    public function testJoinRouteDetectsAutoApprovalCompletionAndMarksNotifiedOnce(): void
    {
        $user = $this->users->create([
            'name' => 'HTTP Joiner',
            'email' => 'http-joiner-' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $this->joiningUserId = $user['id'];

        foreach (['http-evt-a', 'http-evt-b'] as $eventId) {
            $this->pdo->prepare(
                "INSERT INTO results (id, event_id, event_name, distance_id, distance_name, user_id, user_name, bib_number, category, time, time_in_seconds, position, category_position, status)
                 VALUES (:id, :eventId, 'Test Event', 'dist-1', '10km', :userId, 'HTTP Joiner', '1', 'open', '00:45:00', 2700, 1, 1, 'finished')"
            )->execute(['id' => bin2hex(random_bytes(16)), 'eventId' => $eventId, 'userId' => $this->joiningUserId]);
        }

        // No reviewEntry()/`/challenge-entries/{id}/review` call anywhere in
        // this test: an actual HTTP POST to the join route is the only write
        // this test performs. If it crosses minCompleted only via
        // syncResultEntries()'s auto-approval AND the join route still wires
        // $notifyChallengeCompletion, completion_notified_at gets marked here.
        $response = $this->request(
            'POST',
            '/api/v1/challenges/' . $this->challengeId . '/join',
            [],
            $this->bearer($this->joiningUserId, 'participant', $user['email'])
        );
        $payload = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertTrue($payload['data']['isCompleted']);
        self::assertSame(2, $payload['data']['completedCount']);

        $stmt = $this->pdo->prepare(
            'SELECT completion_notified_at FROM challenge_participations WHERE challenge_id = :challengeId AND user_id = :userId'
        );
        $stmt->execute(['challengeId' => $this->challengeId, 'userId' => $this->joiningUserId]);
        $notifiedAt = $stmt->fetchColumn();

        // This is the fingerprint: only $notifyChallengeCompletion's call to
        // checkCompletion() ever writes this column, and only the join and
        // review routes ever call that closure. A dropped wiring in the join
        // route leaves this NULL even though the join response above still
        // correctly reports isCompleted = true (hydrateParticipation()
        // computes that independently on every read) — so only this
        // assertion, not the response shape, actually catches the regression.
        self::assertNotFalse($notifiedAt, 'challenge_participations row must exist after join()');
        self::assertNotNull($notifiedAt, 'join() route must call $notifyChallengeCompletion so checkCompletion() marks completion_notified_at');

        // Re-joining (idempotent no-op per join()'s own existing-participation
        // short-circuit) must not throw and must not re-notify.
        $secondResponse = $this->request(
            'POST',
            '/api/v1/challenges/' . $this->challengeId . '/join',
            [],
            $this->bearer($this->joiningUserId, 'participant', $user['email'])
        );
        self::assertSame(201, $secondResponse->getStatusCode());
    }
}

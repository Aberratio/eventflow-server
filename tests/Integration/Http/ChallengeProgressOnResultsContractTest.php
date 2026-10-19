<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * G-02 (spec 15 §3): a Wyzwanie's progress follows the results - it is recomputed when results are
 * published / corrected / imported, not only when the participant joins or reads the page. The
 * completion moment (mail #13 gate: completion_notified_at) is reached on the write path too.
 */
final class ChallengeProgressOnResultsContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private string $prefix;
    private array $organizer;
    private array $runner;
    private array $event;
    private string $challengeId;
    private string $distanceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->prefix = 'http-chalres-' . bin2hex(random_bytes(4));
        $this->organizer = $this->makeUser('org', 'organizer');
        $this->runner = $this->makeUser('run', 'participant');

        $slug = $this->prefix . '-event';
        $this->distanceId = $slug . '-d';
        $this->event = $this->events->create([
            'name' => 'PHPUnit ChalRes Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('-1 day')),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'ChalRes Org',
            'status' => 'active',
            'registrationDeadline' => date('Y-m-d', strtotime('-5 days')),
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $this->distanceId,
                'name' => '10 km',
                'length' => 10,
                'unit' => 'km',
                'categories' => ['Open'],
                'ageCategories' => [],
                'specialCategories' => [],
                'limit' => 100,
                'registeredCount' => 0,
                'price' => 0,
                'routeFiles' => [],
            ]],
        ]);

        $this->challengeId = $this->prefix . '-chal';
        $this->pdo->prepare(
            "INSERT INTO challenges (id, name, slug, description, min_completed, status, created_by)
             VALUES (:id, 'ChalRes Challenge', :slug, 'desc', 1, 'active', 'tester')"
        )->execute(['id' => $this->challengeId, 'slug' => $this->challengeId]);
        $this->pdo->prepare('INSERT INTO challenge_events (challenge_id, event_id, sort_order) VALUES (:c, :e, 0)')
            ->execute(['c' => $this->challengeId, 'e' => $this->event['id']]);
        // The participant joined BEFORE any result existed - nothing recomputes on join/read later.
        $this->pdo->prepare(
            "INSERT INTO challenge_participations (id, challenge_id, user_id, user_name, joined_at, reward_granted, selected_rewards)
             VALUES (:id, :c, :u, 'Runner', NOW(), 0, '[]')"
        )->execute(['id' => bin2hex(random_bytes(16)), 'c' => $this->challengeId, 'u' => $this->runner['id']]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix, $this->event)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM challenge_entries WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenge_participations WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenge_events WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenges WHERE id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM results WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE c FROM competitors c JOIN users u ON u.id = c.user_id WHERE u.email LIKE :email')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testPublishingAResultCompletesTheChallengeForThatRunner(): void
    {
        $response = $this->postResult('finished');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $this->approvedEntries());
        self::assertTrue($this->completionNotified());
    }

    public function testDnfResultDoesNotAdvanceTheChallenge(): void
    {
        self::assertSame(201, $this->postResult('dnf')->getStatusCode());

        self::assertSame(0, $this->approvedEntries());
        self::assertFalse($this->completionNotified());
    }

    public function testCorrectingAResultToFinishedAdvancesTheChallenge(): void
    {
        $id = (string) $this->json($this->postResult('dnf'))['data']['id'];

        $response = $this->request('PATCH', '/api/v1/results/' . $id, ['status' => 'finished'], $this->organizerAuth());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->approvedEntries());
        self::assertTrue($this->completionNotified());
    }

    public function testCorrectingAFinishedResultAwayFromFinishedRemovesTheDerivedEntry(): void
    {
        $id = (string) $this->json($this->postResult('finished'))['data']['id'];
        self::assertSame(1, $this->approvedEntries());

        $this->request('PATCH', '/api/v1/results/' . $id, ['status' => 'dsq'], $this->organizerAuth());

        self::assertSame(0, $this->approvedEntries());
    }

    public function testMarkingResultsOfficialRecomputesProgress(): void
    {
        $this->pdo->prepare(
            "INSERT INTO results (id, event_id, event_name, distance_id, distance_name, user_id, user_name, bib_number, category, time, time_in_seconds, position, category_position, status)
             VALUES (:id, :e, 'PHPUnit ChalRes Event', :d, '10 km', :u, 'Runner', '7', 'Open', '00:45:00', 2700, 1, 1, 'finished')"
        )->execute(['id' => bin2hex(random_bytes(16)), 'e' => $this->event['id'], 'd' => $this->distanceId, 'u' => $this->runner['id']]);
        self::assertSame(0, $this->approvedEntries());

        $response = $this->request(
            'POST',
            '/api/v1/events/' . $this->event['id'] . '/distances/' . $this->distanceId . '/results/publish-official',
            [],
            $this->organizerAuth()
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->approvedEntries());
        self::assertTrue($this->completionNotified());
    }

    private function postResult(string $status): ResponseInterface
    {
        return $this->request('POST', '/api/v1/results', [
            'eventId' => $this->event['id'],
            'eventName' => 'PHPUnit ChalRes Event',
            'distanceId' => $this->distanceId,
            'distanceName' => '10 km',
            'userId' => $this->runner['id'],
            'userName' => 'Runner',
            'bibNumber' => '7',
            'category' => 'Open',
            'time' => '00:45:00',
            'timeInSeconds' => 2700,
            'position' => 1,
            'categoryPosition' => 1,
            'status' => $status,
        ], $this->organizerAuth());
    }

    /** @return array<string, string> */
    private function organizerAuth(): array
    {
        return $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']);
    }

    private function approvedEntries(): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM challenge_entries WHERE challenge_id = :c AND user_id = :u AND status = 'approved'");
        $stmt->execute(['c' => $this->challengeId, 'u' => $this->runner['id']]);
        return (int) $stmt->fetchColumn();
    }

    private function completionNotified(): bool
    {
        $stmt = $this->pdo->prepare('SELECT completion_notified_at FROM challenge_participations WHERE challenge_id = :c AND user_id = :u');
        $stmt->execute(['c' => $this->challengeId, 'u' => $this->runner['id']]);
        return $stmt->fetchColumn() !== null;
    }

    private function makeUser(string $slug, string $role): array
    {
        return $this->users->create([
            'name' => 'ChalRes ' . ucfirst($slug),
            'email' => $this->prefix . '-' . $slug . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ]);
    }
}

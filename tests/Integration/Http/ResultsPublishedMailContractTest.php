<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * Publishing official results only records a mail request row (O(1)); no mail is sent or
 * queued inside the request, and the response shape is unchanged.
 */
final class ResultsPublishedMailContractTest extends HttpTestCase
{
    private string $prefix;
    /** @var array<string, mixed> */
    private array $organizer;
    /** @var array<string, mixed> */
    private array $participant;
    /** @var array<string, mixed> */
    private array $event;

    protected function setUp(): void
    {
        // A configured (but unusable) mailer: any inline send would leave a failed outbox row behind.
        Env::set('CENTRAL_MAILER_API_KEY', 'phpunit-key');
        Env::set('CENTRAL_MAILER_BASE_URL', 'unusable://mailer.invalid');
        Env::set('APP_URL', 'https://app.example.test');
        parent::setUp();

        $users = new UserRepository($this->pdo);
        $this->prefix = 'http-respub-' . bin2hex(random_bytes(4));
        $this->organizer = $users->create([
            'name' => 'Publish Organizer',
            'email' => $this->prefix . '-org@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
        ]);
        $this->participant = $users->create([
            'name' => 'Publish Participant',
            'email' => $this->prefix . '-part@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $slug = $this->prefix . '-event';
        $this->event = (new EventRepository($this->pdo))->create([
            'name' => 'PHPUnit Publish Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'Publish Organizer',
            'status' => 'active',
            'registrationDeadline' => '2026-09-01',
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $slug . '-10k', 'name' => '10 km', 'length' => 10, 'unit' => 'km', 'categories' => ['Open'],
                'ageCategories' => [], 'specialCategories' => [], 'limit' => 100, 'registeredCount' => 0,
                'price' => 80, 'routeFiles' => [],
            ], [
                'id' => $slug . '-5k', 'name' => '5 km', 'length' => 5, 'unit' => 'km', 'categories' => ['Open'],
                'ageCategories' => [], 'specialCategories' => [], 'limit' => 100, 'registeredCount' => 0,
                'price' => 50, 'routeFiles' => [],
            ]],
        ]);
    }

    protected function tearDown(): void
    {
        Env::restore('CENTRAL_MAILER_API_KEY');
        Env::restore('CENTRAL_MAILER_BASE_URL');
        Env::restore('APP_URL');
        if (!isset($this->pdo, $this->prefix, $this->event)) {
            return;
        }
        $eventId = (string) $this->event['id'];
        $this->pdo->prepare('DELETE FROM activity_logs WHERE target_type = :t AND target_id IN (SELECT id FROM results WHERE event_id = :id)')
            ->execute(['t' => 'result', 'id' => $eventId]);
        $this->pdo->prepare('DELETE FROM activity_logs WHERE target_type = :t AND target_id IN (SELECT id FROM event_distances WHERE event_id = :id)')
            ->execute(['t' => 'event_distance', 'id' => $eventId]);
        $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE context_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM results_publication_mail_requests WHERE event_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM results WHERE event_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testPublishOfficialQueuesRequestWithoutSendingMail(): void
    {
        $eventId = (string) $this->event['id'];
        $distanceId = (string) $this->event['distances'][0]['id'];
        $otherDistanceId = (string) $this->event['distances'][1]['id'];
        self::assertSame(201, $this->createResult($distanceId, 1)->getStatusCode());

        $response = $this->publish($distanceId);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['eventId' => $eventId, 'distanceId' => $distanceId, 'publicationStatus' => 'official', 'updatedCount' => 1],
            $this->json($response)['data']
        );
        $rows = $this->requestRows();
        self::assertCount(1, $rows);
        self::assertSame($distanceId, $rows[0]['distance_id']);
        self::assertNull($rows[0]['processed_at']);
        self::assertSame(0, $this->outboxCount(), 'no mail is written or sent inside the publish request');

        // A second publish that flips another preliminary result: still one request row (upsert), pending again.
        $this->pdo->prepare('UPDATE results_publication_mail_requests SET processed_at = :now, recipients_queued = 4 WHERE event_id = :id')
            ->execute(['now' => date(DATE_ATOM), 'id' => $eventId]);
        self::assertSame(201, $this->createResult($distanceId, 2, 'phpunit-second')->getStatusCode());
        self::assertSame(200, $this->publish($distanceId)->getStatusCode());
        $rows = $this->requestRows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['processed_at'], 'a re-publish makes the request pending again');
        self::assertSame(0, $this->outboxCount());

        // A distance without results publishes nothing and records no request.
        $empty = $this->publish($otherDistanceId);
        self::assertSame(200, $empty->getStatusCode());
        self::assertSame(0, $this->json($empty)['data']['updatedCount']);
        self::assertCount(1, $this->requestRows());
    }

    private function publish(string $distanceId): \Psr\Http\Message\ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/events/' . $this->event['id'] . '/distances/' . $distanceId . '/results/publish-official',
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
    }

    private function createResult(string $distanceId, int $position, string $bib = 'phpunit-first'): \Psr\Http\Message\ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/results',
            [
                'eventId' => $this->event['id'],
                'eventName' => $this->event['name'],
                'distanceId' => $distanceId,
                'distanceName' => '10 km',
                'userId' => $this->participant['id'],
                'userName' => $this->participant['name'],
                'bibNumber' => (string) $position,
                'category' => 'Open',
                'time' => '01:00:0' . $position,
                'timeInSeconds' => 3600 + $position,
                'position' => $position,
                'categoryPosition' => $position,
                'status' => 'finished',
            ],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
    }

    /** @return list<array<string, mixed>> */
    private function requestRows(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM results_publication_mail_requests WHERE event_id = :id');
        $stmt->execute(['id' => $this->event['id']]);

        return $stmt->fetchAll();
    }

    private function outboxCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM central_mailer_outbox WHERE context_id = :id');
        $stmt->execute(['id' => $this->event['id']]);

        return (int) $stmt->fetchColumn();
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Scheduler;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Bz\BzWebhookClient;
use ZmierzymyCzas\Domain\Bz\BzWebhookConfig;
use ZmierzymyCzas\Domain\Bz\BzWebhookOutboxRepository;
use ZmierzymyCzas\Domain\Bz\BzWebhookOutboxService;
use ZmierzymyCzas\Domain\Bz\Http\HttpResponse;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Scheduler\Jobs\BzOutboxRetryJob;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\FakeBzHttpClient;

/** PP.18: bz.outbox_retry (failed / stale-queued pushes, attempts, backoff). */
final class BzOutboxRetryTest extends TestCase
{
    private PDO $pdo;
    private FakeBzHttpClient $http;
    private BzWebhookOutboxRepository $repo;
    private BzWebhookOutboxService $service;
    private BzWebhookConfig $config;
    private string $slugPrefix;
    private string $eventId;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        $this->slugPrefix = 'phpbzretry-' . bin2hex(random_bytes(4));
        $this->pdo = Connection::fromEnv();
        // Outside any try/catch: a broken migration must fail, not skip.
        Migrator::applyAll($this->pdo);
        $this->deleteFixtures();
        $this->http = new FakeBzHttpClient();
        $this->repo = new BzWebhookOutboxRepository($this->pdo);
        $this->config = new BzWebhookConfig(baseUrl: 'https://bz.example.test');
        $this->service = new BzWebhookOutboxService(new BzWebhookClient($this->http, $this->config), $this->repo);
        $this->eventId = $this->createEvent(true);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->deleteFixtures();
        }
    }

    public function testFailedRowIsRetriedAfterBackoffWithSameKeyAndPayload(): void
    {
        $key = $this->slugPrefix . ':reg-1:cancellation';
        $this->http->queueResponse(new HttpResponse(500, 'boom'));
        try {
            $this->service->enqueue($this->message($key));
            self::fail('the first attempt must fail');
        } catch (\RuntimeException) {
            // expected: the immediate attempt failed, the row is `failed`
        }
        $row = $this->repo->findByIdempotencyKey($key);
        self::assertSame('failed', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertNotNull($row['nextAttemptAt']);

        $now = new \DateTimeImmutable();
        $job = $this->job();
        $callsBefore = count($this->http->calls);

        $early = $job->run($now->modify('+1 minute'));
        self::assertSame($callsBefore, count($this->http->calls), 'not retried before the backoff');
        self::assertSame(0, $early['sent']);

        $this->http->queueResponse(new HttpResponse(200, '{}'));
        $late = $job->run($now->modify('+6 minutes'));

        self::assertSame(1, $late['sent']);
        self::assertCount($callsBefore + 1, $this->http->calls);
        $retry = $this->http->calls[$callsBefore];
        $first = $this->http->calls[0];
        self::assertSame($first['body'], $retry['body'], 'the payload is resent verbatim');
        self::assertSame($first['headers']['Idempotency-Key'] ?? null, $retry['headers']['Idempotency-Key'] ?? null);
        self::assertSame($key, $retry['headers']['Idempotency-Key'] ?? null);
        $after = $this->repo->findByIdempotencyKey($key);
        self::assertSame('sent', $after['status']);
        self::assertSame(2, (int) $after['attempts']);
    }

    public function testStopsAfterMaxAttempts(): void
    {
        $key = $this->slugPrefix . ':reg-2:cancellation';
        $row = $this->repo->create($this->message($key));
        $this->pdo->prepare("UPDATE bz_webhook_outbox SET status = 'failed', attempts = :a, next_attempt_at = NULL WHERE id = :id")
            ->execute(['a' => BzWebhookOutboxService::MAX_ATTEMPTS - 1, 'id' => $row['id']]);
        $this->http->queueResponse(new HttpResponse(500, 'boom'));

        $first = $this->job()->run(new \DateTimeImmutable());
        self::assertSame(1, $first['failed']);
        $stored = $this->repo->find($row['id']);
        self::assertSame(BzWebhookOutboxService::MAX_ATTEMPTS, (int) $stored['attempts']);
        self::assertNull($stored['nextAttemptAt']);

        $calls = count($this->http->calls);
        $this->job()->run((new \DateTimeImmutable())->modify('+3 days'));
        self::assertCount($calls, $this->http->calls, 'no retry once the attempts are exhausted');
    }

    public function testAbandonsWhenIntegrationDisabled(): void
    {
        $disabled = $this->createEvent(false);
        $row = $this->repo->create($this->message($this->slugPrefix . ':reg-3:cancellation', $disabled));
        $this->pdo->prepare("UPDATE bz_webhook_outbox SET status = 'failed', attempts = 1, next_attempt_at = NULL WHERE id = :id")
            ->execute(['id' => $row['id']]);

        $result = $this->job()->run(new \DateTimeImmutable());

        self::assertSame(1, $result['abandoned']);
        self::assertCount(0, $this->http->calls);
        $stored = $this->repo->find($row['id']);
        self::assertSame(BzWebhookOutboxService::MAX_ATTEMPTS, (int) $stored['attempts']);
        self::assertSame('failed', $stored['status']);
    }

    public function testStaleQueuedRowIsRetriedButFreshOneIsNot(): void
    {
        $stale = $this->repo->create($this->message($this->slugPrefix . ':reg-4:cancellation'));
        $fresh = $this->repo->create($this->message($this->slugPrefix . ':reg-5:cancellation'));
        $this->pdo->prepare('UPDATE bz_webhook_outbox SET created_at = :c WHERE id = :id')
            ->execute(['c' => (new \DateTimeImmutable('-30 minutes'))->format(DATE_ATOM), 'id' => $stale['id']]);
        $this->http->queueResponse(new HttpResponse(200, '{}'));

        $result = $this->job()->run(new \DateTimeImmutable());

        self::assertSame(1, $result['sent']);
        self::assertSame('sent', $this->repo->find($stale['id'])['status']);
        self::assertSame('queued', $this->repo->find($fresh['id'])['status']);
    }

    public function testJobIsSkippedWhenBzIsNotConfigured(): void
    {
        $job = new BzOutboxRetryJob($this->pdo, null, new BzWebhookConfig(baseUrl: ''));

        self::assertSame(['skipped' => 1], $job->run(new \DateTimeImmutable()));
    }

    private function job(): BzOutboxRetryJob
    {
        return new BzOutboxRetryJob($this->pdo, $this->service, $this->config);
    }

    /** @return array<string, mixed> */
    private function message(string $key, ?string $eventId = null): array
    {
        return [
            'eventId' => $eventId ?? $this->eventId,
            'registrationId' => 'phpbzretry-reg',
            'changeType' => 'cancellation',
            'idempotencyKey' => $key,
            'organizationToken' => 'ORG-TOKEN',
            'payload' => ['eventId' => $eventId ?? $this->eventId, 'registrationId' => 'phpbzretry-reg', 'changeType' => 'cancellation', 'occurredAt' => '2026-10-05T10:00:00+00:00'],
        ];
    }

    private function createEvent(bool $bzEnabled): string
    {
        $slug = $this->slugPrefix . '-' . ($bzEnabled ? 'on' : 'off');
        $event = (new EventRepository($this->pdo))->create([
            'name' => 'PHPUnit BZ Retry',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-12-15',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'draft',
            'registrationDeadline' => '2026-12-01',
            'featured' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'bzIntegrationEnabled' => $bzEnabled,
            'bzOrganizationToken' => $bzEnabled ? 'ORG-TOKEN' : '',
            'distances' => [[
                'id' => $slug . '-10k', 'name' => '10 km', 'length' => 10, 'unit' => 'km',
                'categories' => ['Open'], 'ageCategories' => [], 'specialCategories' => [],
                'limit' => 100, 'registeredCount' => 0, 'price' => 80, 'routeFiles' => [],
            ]],
        ]);

        return (string) $event['id'];
    }

    private function deleteFixtures(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => 'phpbzretry-%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $eventId) {
            $this->pdo->prepare('DELETE FROM bz_webhook_outbox WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        }
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Bz\BzWebhookClient;
use ZmierzymyCzas\Domain\Bz\BzWebhookConfig;
use ZmierzymyCzas\Domain\Bz\BzWebhookOutboxRepository;
use ZmierzymyCzas\Domain\Bz\BzWebhookOutboxService;
use ZmierzymyCzas\Domain\Bz\Http\HttpResponse;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\FakeBzHttpClient;

/**
 * BzWebhookOutboxService against a real outbox table (migration
 * 049_e3_4_bz_webhook_outbox.sql) and a FakeBzHttpClient — exercises the outbox
 * pattern itself (mirroring CentralMailerOutboxServiceTest):
 * idempotency-key write-before-HTTP-call, no duplicate push on a repeated key,
 * and failure recording. The HTTP contract shape (headers, payload) is covered
 * separately in tests/Unit/Bz/BzWebhookClientTest.php.
 */
final class BzWebhookOutboxServiceTest extends TestCase
{
    private PDO $pdo;
    private FakeBzHttpClient $http;
    private BzWebhookOutboxRepository $outbox;
    private BzWebhookOutboxService $service;
    private string $prefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->prefix = 'phpbz-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyFile($this->pdo, __DIR__ . '/../../database/migrations/049_e3_4_bz_webhook_outbox.sql');
            $this->deletePhpUnitRows();

            $this->http = new FakeBzHttpClient();
            $config = new BzWebhookConfig(baseUrl: 'https://bz.example.test');
            $client = new BzWebhookClient($this->http, $config);
            $this->outbox = new BzWebhookOutboxRepository($this->pdo);
            $this->service = new BzWebhookOutboxService($client, $this->outbox);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for BZ webhook outbox tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->deletePhpUnitRows();
    }

    public function testEnqueueRecordsSentStatus(): void
    {
        $this->http->queueResponse(new HttpResponse(202, '{}'));

        $row = $this->service->enqueue($this->message());

        self::assertSame('sent', $row['status']);
        self::assertNotNull($row['sentAt']);
        self::assertCount(1, $this->http->calls);

        $stored = $this->outbox->find($row['id']);
        self::assertSame($this->prefix . '-key-1', $stored['idempotencyKey']);
        self::assertSame('cancellation', $stored['changeType']);
    }

    public function testEnqueueSendsOrganizationTokenAndIdempotencyKeyAsHeaders(): void
    {
        $this->http->queueResponse(new HttpResponse(202, '{}'));

        $this->service->enqueue($this->message());

        $call = $this->http->calls[0];
        self::assertSame('org-token-xyz', $call['headers']['X-BZ-Organization-Token']);
        self::assertSame($this->prefix . '-key-1', $call['headers']['Idempotency-Key']);
    }

    public function testEnqueueWithSameIdempotencyKeyDoesNotCallBzAgain(): void
    {
        $this->http->queueResponse(new HttpResponse(202, '{}'));

        $first = $this->service->enqueue($this->message());
        $second = $this->service->enqueue($this->message());

        self::assertSame($first['id'], $second['id']);
        // Only the first call ever reached the fake HTTP client — the second
        // enqueue() found the existing outbox row by idempotency key and
        // returned it without touching BZ at all.
        self::assertCount(1, $this->http->calls);
    }

    public function testEnqueueRecordsFailureWhenBzCallThrowsAndRethrows(): void
    {
        $this->http->queueResponse(new HttpResponse(500, 'server error'));

        try {
            $this->service->enqueue($this->message());
            self::fail('Expected enqueue() to rethrow the HTTP failure.');
        } catch (\RuntimeException) {
            // expected
        }

        $stored = $this->outbox->findByIdempotencyKey($this->prefix . '-key-1');
        self::assertNotNull($stored, 'Outbox row must exist even though the HTTP call failed — it was written before the call.');
        self::assertSame('failed', $stored['status']);
        self::assertNotNull($stored['lastError']);
        self::assertNull($stored['sentAt']);
    }

    /** @return array<string, mixed> */
    private function message(): array
    {
        return [
            'eventId' => $this->prefix . '-event',
            'registrationId' => $this->prefix . '-registration',
            'changeType' => 'cancellation',
            'idempotencyKey' => $this->prefix . '-key-1',
            'organizationToken' => 'org-token-xyz',
            'payload' => [
                'eventId' => $this->prefix . '-event',
                'registrationId' => $this->prefix . '-registration',
                'changeType' => 'cancellation',
                'occurredAt' => '2026-01-01T00:00:00+00:00',
                'qrCode' => null,
            ],
        ];
    }

    private function deletePhpUnitRows(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM bz_webhook_outbox WHERE idempotency_key LIKE :prefix');
        $stmt->execute(['prefix' => $this->prefix . '%']);
    }
}

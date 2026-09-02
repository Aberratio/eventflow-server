<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerClient;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxService;
use ZmierzymyCzas\Domain\Mailer\Http\HttpResponse;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\FakeCentralMailerHttpClient;

/**
 * E1.9: CentralMailerOutboxService against a real outbox table (migration
 * 037_e19_central_mailer_outbox.sql) and a FakeCentralMailerHttpClient — exercises
 * the outbox pattern itself (kontrakt-central-mailer.md §6.4): idempotency-key
 * write-before-HTTP-call, no duplicate send on a repeated key, failure recording,
 * and reconciliation. The HTTP contract shape (headers, payload) is covered
 * separately in tests/Unit/Mailer/CentralMailerClientTest.php.
 */
final class CentralMailerOutboxServiceTest extends TestCase
{
    private PDO $pdo;
    private FakeCentralMailerHttpClient $http;
    private CentralMailerOutboxRepository $outbox;
    private CentralMailerOutboxService $service;
    private string $prefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->prefix = 'phpcm-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyFile($this->pdo, __DIR__ . '/../../database/migrations/037_e19_central_mailer_outbox.sql');
            $this->deletePhpUnitRows();

            $this->http = new FakeCentralMailerHttpClient();
            $config = new CentralMailerConfig(apiKey: 'test-key', baseUrl: 'https://mailer.example.test');
            $client = new CentralMailerClient($this->http, $config);
            $this->outbox = new CentralMailerOutboxRepository($this->pdo);
            $this->service = new CentralMailerOutboxService($client, $this->outbox);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for Central Mailer outbox tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->deletePhpUnitRows();
    }

    public function testEnqueueRecordsAcceptedStatusAndCentralMailerEmailId(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-em-1', 'status' => 'pending'])));

        $row = $this->service->enqueue($this->message());

        self::assertSame('cm-em-1', $row['centralMailerEmailId']);
        self::assertSame('pending', $row['status']);
        self::assertNotNull($row['sentToMailerAt']);
        self::assertCount(1, $this->http->calls);

        $stored = $this->outbox->find($row['id']);
        self::assertSame($this->prefix . '-key-1', $stored['idempotencyKey']);
        self::assertSame('<p>hello</p>', $stored['html']);
    }

    public function testEnqueueWithSameIdempotencyKeyDoesNotCallCentralMailerAgain(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-em-1', 'status' => 'pending'])));

        $first = $this->service->enqueue($this->message());
        $second = $this->service->enqueue($this->message());

        self::assertSame($first['id'], $second['id']);
        // Only the first call ever reached the fake HTTP client — the second
        // enqueue() found the existing outbox row by idempotency key and
        // returned it without touching Central Mailer at all.
        self::assertCount(1, $this->http->calls);
    }

    public function testEnqueueRecordsFailureWhenCentralMailerCallThrows(): void
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
        self::assertNull($stored['centralMailerEmailId']);
    }

    public function testTechnicalPriorityIsAcceptedAsAFirstClassOption(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-em-2', 'status' => 'pending'])));

        $message = $this->message();
        $message['priority'] = 'technical';
        $message['idempotencyKey'] = $this->prefix . '-key-technical';

        $row = $this->service->enqueue($message);

        self::assertSame('technical', $row['priority']);
        $sent = json_decode((string) $this->http->calls[0]['body'], true);
        self::assertSame('technical', $sent['priority']);
        self::assertSame('transactional', $sent['category']);
    }

    public function testReconcileUpdatesStatusFromCentralMailerForAcceptedRows(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-em-3', 'status' => 'pending'])));
        $row = $this->service->enqueue($this->message());

        $this->http->queueResponse(new HttpResponse(200, json_encode(['id' => 'cm-em-3', 'status' => 'sent'])));

        $summary = $this->service->reconcile();

        self::assertGreaterThanOrEqual(1, $summary['checked']);
        self::assertGreaterThanOrEqual(1, $summary['updated']);

        $stored = $this->outbox->find($row['id']);
        self::assertSame('sent', $stored['status']);
        self::assertNotNull($stored['lastCheckedAt']);
    }

    public function testReconcileSkipsRowsWithoutACentralMailerEmailId(): void
    {
        // A row that failed before ever getting a central_mailer_email_id must
        // not show up in the reconciliation queue (there is nothing to poll).
        $this->http->queueResponse(new HttpResponse(500, 'server error'));
        try {
            $this->service->enqueue($this->message());
        } catch (\RuntimeException) {
        }

        $summary = $this->service->reconcile();

        self::assertSame(0, $summary['checked']);
    }

    /** @return array<string, mixed> */
    private function message(): array
    {
        return [
            'sourceEntityType' => 'phpunit-fixture',
            'sourceEntityId' => null,
            'idempotencyKey' => $this->prefix . '-key-1',
            'to' => 'runner@example.test',
            'subject' => 'Test',
            'html' => '<p>hello</p>',
            'contextId' => null,
            'metadata' => null,
        ];
    }

    private function deletePhpUnitRows(): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM central_mailer_outbox WHERE idempotency_key LIKE :prefix");
        $stmt->execute(['prefix' => $this->prefix . '%']);
    }
}

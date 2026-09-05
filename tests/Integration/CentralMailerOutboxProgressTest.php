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
 * E1.10 §8 mass-send progress: CentralMailerOutboxService::progressForContext()
 * (backed by CentralMailerOutboxRepository::countByContext()) gives an
 * organizer sending tickets to a full start list queued/sent/failed counts
 * instead of silence during Central Mailer's shared 100/15min budget.
 */
final class CentralMailerOutboxProgressTest extends TestCase
{
    private PDO $pdo;
    private FakeCentralMailerHttpClient $http;
    private CentralMailerOutboxService $service;
    private string $contextId;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->contextId = 'phpunit-ctx-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->http = new FakeCentralMailerHttpClient();
            $config = new CentralMailerConfig(apiKey: 'k', baseUrl: 'https://mailer.example.test');
            $this->service = new CentralMailerOutboxService(new CentralMailerClient($this->http, $config), new CentralMailerOutboxRepository($this->pdo));
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for outbox progress tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE context_id = :contextId')->execute(['contextId' => $this->contextId]);
    }

    public function testProgressCountsQueuedSentAndFailedSeparately(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-a', 'status' => 'sent'])));
        $this->service->enqueue($this->message('key-1', 'a@example.test'));

        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-b', 'status' => 'pending'])));
        $this->service->enqueue($this->message('key-2', 'b@example.test'));

        $this->http->queueResponse(new HttpResponse(500, 'boom'));
        try {
            $this->service->enqueue($this->message('key-3', 'c@example.test'));
        } catch (\RuntimeException) {
        }

        $progress = $this->service->progressForContext($this->contextId, 'payment_confirmed');

        self::assertSame(3, $progress['total']);
        self::assertSame(1, $progress['sent']);
        self::assertSame(1, $progress['other']); // 'pending' from Central Mailer, not yet terminal
        self::assertSame(1, $progress['failed']);
        self::assertSame(0, $progress['queued']);
    }

    /** @return array<string, mixed> */
    private function message(string $keySuffix, string $to): array
    {
        return [
            'sourceEntityType' => 'payment_confirmed',
            'sourceEntityId' => 'reg-' . $keySuffix,
            'idempotencyKey' => $this->contextId . '-' . $keySuffix,
            'to' => $to,
            'subject' => 'Test',
            'html' => '<p>hello</p>',
            'contextId' => $this->contextId,
        ];
    }
}

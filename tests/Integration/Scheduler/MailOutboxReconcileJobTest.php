<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Scheduler;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerClient;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxService;
use ZmierzymyCzas\Domain\Mailer\Http\HttpResponse;
use ZmierzymyCzas\Scheduler\Jobs\MailOutboxReconcileJob;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\FakeCentralMailerHttpClient;

/** mail.outbox_reconcile writes back Central Mailer's status for accepted rows; skipped when unconfigured. */
final class MailOutboxReconcileJobTest extends TestCase
{
    private PDO $pdo;
    private string $prefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        $this->prefix = 'phprecon-' . bin2hex(random_bytes(4));
        $this->pdo = Connection::fromEnv();
        Migrator::applyAll($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE idempotency_key LIKE :p')->execute(['p' => $this->prefix . '%']);
        }
    }

    public function testReconcilesWhenConfiguredSkipsOtherwise(): void
    {
        $http = new FakeCentralMailerHttpClient();
        $config = new CentralMailerConfig(apiKey: 'k', baseUrl: 'https://mailer.example.test');
        $repo = new CentralMailerOutboxRepository($this->pdo);
        $service = new CentralMailerOutboxService(new CentralMailerClient($http, $config), $repo);
        $http->queueResponse(new HttpResponse(201, (string) json_encode(['id' => 'cm-recon', 'status' => 'pending'])));
        $row = $service->enqueue([
            'sourceEntityType' => 'phpunit-fixture',
            'sourceEntityId' => null,
            'idempotencyKey' => $this->prefix . '-key',
            'to' => 'runner@example.test',
            'subject' => 'Test',
            'html' => '<p>hello</p>',
            'contextId' => null,
            'metadata' => null,
        ]);
        $http->queueResponse(new HttpResponse(200, (string) json_encode(['id' => 'cm-recon', 'status' => 'sent'])));
        // Other awaiting rows in the shared test DB would also poll; keep the queue generous.
        for ($i = 0; $i < 60; $i++) {
            $http->queueResponse(new HttpResponse(200, (string) json_encode(['status' => 'sent'])));
        }

        $result = (new MailOutboxReconcileJob($service, $config))->run(new \DateTimeImmutable());

        self::assertGreaterThanOrEqual(1, $result['checked']);
        self::assertGreaterThanOrEqual(1, $result['updated']);
        self::assertSame('sent', $repo->find($row['id'])['status']);

        $unconfigured = new CentralMailerConfig(apiKey: '', baseUrl: '');
        self::assertSame(['skipped' => 1], (new MailOutboxReconcileJob($service, $unconfigured))->run(new \DateTimeImmutable()));
    }
}

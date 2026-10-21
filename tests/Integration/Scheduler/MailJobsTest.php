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
use ZmierzymyCzas\Domain\Mailer\CriticalMails\CriticalMailer;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailTemplateRepository;
use ZmierzymyCzas\Domain\Mailer\ResultsPublication\ResultsPublicationMailRequests;
use ZmierzymyCzas\Domain\Mailer\ResultsPublication\ResultsPublicationNotifier;
use ZmierzymyCzas\Scheduler\Jobs\MailOutboxRetryJob;
use ZmierzymyCzas\Scheduler\Jobs\ResultsPublishedMailJob;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\FakeCentralMailerHttpClient;

/** The mail.outbox_retry job (skipped when unconfigured, dispatches deferred rows otherwise). */
final class MailJobsTest extends TestCase
{
    private PDO $pdo;
    private string $prefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        $this->prefix = 'phpmailjobs-' . bin2hex(random_bytes(4));
        $this->pdo = Connection::fromEnv();
        Migrator::applyAll($this->pdo);
        $this->clearUndeliveredRows();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->clearUndeliveredRows();
            $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE idempotency_key LIKE :p')->execute(['p' => $this->prefix . '%']);
        }
    }

    public function testRetryJobSkipsWhenUnconfiguredAndDispatchesOtherwise(): void
    {
        $http = new FakeCentralMailerHttpClient();
        $config = new CentralMailerConfig(apiKey: 'k', baseUrl: 'https://mailer.example.test');
        $service = new CentralMailerOutboxService(new CentralMailerClient($http, $config), new CentralMailerOutboxRepository($this->pdo));
        $row = $service->enqueueDeferred([
            'sourceEntityType' => 'phpunit-fixture',
            'sourceEntityId' => null,
            'idempotencyKey' => $this->prefix . '-key',
            'to' => 'runner@example.test',
            'subject' => 'Test',
            'html' => '<p>hello</p>',
            'contextId' => null,
            'metadata' => null,
        ]);
        $now = (new \DateTimeImmutable())->modify('+1 second');

        $unconfigured = new CentralMailerConfig(apiKey: '', baseUrl: '');
        self::assertSame(['skipped' => 1], (new MailOutboxRetryJob($service, $unconfigured))->run($now));
        self::assertCount(0, $http->calls);

        $http->queueResponse(new HttpResponse(201, (string) json_encode(['id' => 'cm-job', 'status' => 'pending'])));
        $counters = (new MailOutboxRetryJob($service, $config))->run($now);

        self::assertSame(['checked' => 1, 'sent' => 1, 'failed' => 0, 'abandoned' => 0, 'rateLimited' => 0], $counters);
        self::assertSame('cm-job', (new CentralMailerOutboxRepository($this->pdo))->find($row['id'])['centralMailerEmailId']);
        self::assertSame('mail.outbox_retry', (new MailOutboxRetryJob($service, $config))->name());
    }

    public function testResultsJobDrainsRequestsEvenWhenUnconfigured(): void
    {
        $unconfigured = new CentralMailerConfig(apiKey: '', baseUrl: '');
        $repo = new CentralMailerOutboxRepository($this->pdo);
        $service = new CentralMailerOutboxService(new CentralMailerClient(new FakeCentralMailerHttpClient(), $unconfigured), $repo);
        $requests = new ResultsPublicationMailRequests($this->pdo);
        $eventId = 'phpunit-nonexistent-event';
        $this->pdo->prepare('DELETE FROM results_publication_mail_requests WHERE event_id = :id')->execute(['id' => $eventId]);
        $requests->request($eventId, 'phpunit-nonexistent-dist', new \DateTimeImmutable());
        $notifier = new ResultsPublicationNotifier(
            $this->pdo,
            $requests,
            new CriticalMailer($unconfigured, $service, new MailTemplateRepository($this->pdo)),
            $repo,
            'https://app.example.test'
        );

        try {
            $job = new ResultsPublishedMailJob($notifier);
            $counters = $job->run(new \DateTimeImmutable());

            self::assertSame('mail.results_published', $job->name());
            self::assertGreaterThanOrEqual(1, $counters['requests']);
            $stmt = $this->pdo->prepare('SELECT processed_at, recipients_queued FROM results_publication_mail_requests WHERE event_id = :id');
            $stmt->execute(['id' => $eventId]);
            $row = $stmt->fetch();
            self::assertNotNull($row['processed_at'], 'the request is drained although Central Mailer is not configured');
            self::assertSame(0, (int) $row['recipients_queued']);
        } finally {
            $this->pdo->prepare('DELETE FROM results_publication_mail_requests WHERE event_id = :id')->execute(['id' => $eventId]);
        }
    }

    private function clearUndeliveredRows(): void
    {
        $this->pdo->exec('DELETE FROM central_mailer_outbox WHERE central_mailer_email_id IS NULL AND next_attempt_at IS NOT NULL');
    }
}

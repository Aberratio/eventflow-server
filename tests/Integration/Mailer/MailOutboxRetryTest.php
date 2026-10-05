<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Mailer;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
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
 * PP.19: outbox retry (backoff, max attempts, 429, permanent errors, lease, SQL due filter).
 * Time is injected into retryDue(); nothing sleeps. Payload comparisons decode JSON and never
 * look at stored JSON text (MySQL 8.4 normalises JSON key order).
 */
final class MailOutboxRetryTest extends TestCase
{
    private PDO $pdo;
    private FakeCentralMailerHttpClient $http;
    private CentralMailerOutboxRepository $repo;
    private CentralMailerOutboxService $service;
    private TestHandler $logs;
    private string $prefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        $this->prefix = 'phpretry-' . bin2hex(random_bytes(4));
        $this->pdo = Connection::fromEnv();
        // Outside any try/catch: a broken migration must fail, not skip.
        Migrator::applyAll($this->pdo);
        $this->clearUndeliveredRows();

        $this->http = new FakeCentralMailerHttpClient();
        $this->logs = new TestHandler();
        $this->repo = new CentralMailerOutboxRepository($this->pdo);
        $client = new CentralMailerClient($this->http, new CentralMailerConfig(apiKey: 'k', baseUrl: 'https://mailer.example.test'));
        $this->service = new CentralMailerOutboxService($client, $this->repo, new Logger('test', [$this->logs]));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->clearUndeliveredRows();
            $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE idempotency_key LIKE :p')->execute(['p' => $this->prefix . '%']);
        }
    }

    public function testFailedRowIsRetriedAfterBackoffWithIdenticalPayload(): void
    {
        $message = $this->message('a', [
            'metadata' => ['zeta' => 1, 'alpha' => ['y' => 2, 'x' => 1], 'mid' => 'm'],
            'attachments' => [['filename' => 'kod.png', 'contentBase64' => base64_encode("\x89PNG-test"), 'contentId' => 'qr', 'inline' => true]],
        ]);
        $this->http->queueResponse(new HttpResponse(500, 'boom'));
        $row = $this->service->enqueue($message);
        self::assertSame('failed', $row['status']);

        $now = new \DateTimeImmutable();
        $early = $this->service->retryDue($now->modify('+1 minute'), 50);
        self::assertSame(0, $early['checked']);
        self::assertCount(1, $this->http->calls);

        $this->http->queueResponse(new HttpResponse(201, (string) json_encode(['id' => 'cm-1', 'status' => 'pending'])));
        $late = $this->service->retryDue($now->modify('+3 minutes'), 50);

        self::assertSame(1, $late['sent']);
        self::assertCount(2, $this->http->calls);
        [$first, $second] = $this->http->calls;
        self::assertSame($first['headers']['Idempotency-Key'], $second['headers']['Idempotency-Key']);
        $b1 = json_decode((string) $first['body'], true);
        $b2 = json_decode((string) $second['body'], true);
        self::assertSame($b1['html'], $b2['html']);
        self::assertSame($b1['subject'], $b2['subject']);
        self::assertSame($b1['attachments'][0]['contentBase64'], $b2['attachments'][0]['contentBase64']);
        self::assertSame(array_keys($b1['metadata']), array_keys($b2['metadata']), 'metadata key order is identical');
        self::assertSame(['alpha', 'mid', 'zeta'], array_keys($b2['metadata']));
        self::assertSame(['x', 'y'], array_keys($b2['metadata']['alpha']));

        $stored = $this->repo->find($row['id']);
        self::assertSame('cm-1', $stored['centralMailerEmailId']);
        self::assertSame('pending', $stored['status']);
        self::assertSame(2, $stored['attempts']);
        self::assertNull($stored['nextAttemptAt']);
        self::assertNull($stored['lastError']);
    }

    public function testBackoffScheduleAndAbandonAfterMaxAttemptsWithAlert(): void
    {
        $this->http->queueResponse(new HttpResponse(500, 'boom for runner@example.test'));
        $row = $this->service->enqueue($this->message('b'));
        $expectedMinutes = [1 => 2, 2 => 10, 3 => 30, 4 => 120, 5 => 360];

        $stored = $this->repo->find($row['id']);
        self::assertSame(1, $stored['attempts']);
        $due = $this->utc((string) $stored['nextAttemptAt']);

        for ($attempt = 2; $attempt <= 6; $attempt++) {
            $this->http->queueResponse(new HttpResponse(500, 'boom'));
            $this->service->retryDue($due, 50);
            $stored = $this->repo->find($row['id']);
            self::assertSame($attempt, $stored['attempts'], 'attempt ' . $attempt);
            self::assertSame('failed', $stored['status']);
            if ($attempt < 6) {
                self::assertSame(
                    $due->modify('+' . $expectedMinutes[$attempt] . ' minutes')->format('Y-m-d H:i:s'),
                    $stored['nextAttemptAt'],
                    'next_attempt_at after attempt ' . $attempt
                );
                $due = $this->utc((string) $stored['nextAttemptAt']);
            }
        }
        self::assertNull($stored['nextAttemptAt'], 'exhausted rows are terminal');
        self::assertCount(6, $this->http->calls);

        $result = $this->service->retryDue($due->modify('+30 days'), 50);
        self::assertSame(0, $result['checked']);
        self::assertCount(6, $this->http->calls, 'no seventh POST');

        $errors = array_values(array_filter($this->logs->getRecords(), static fn ($r): bool => $r->level === Level::Error));
        self::assertCount(1, $errors);
        self::assertSame('mail.outbox_abandoned', $errors[0]->context['alert']);
        $dump = json_encode([$errors[0]->message, $errors[0]->context]);
        self::assertStringNotContainsString('@', (string) $dump);
        self::assertSame(\RuntimeException::class, $stored['lastError']);
    }

    public function testRateLimitHonoursRetryAfterDoesNotCountAndStopsBatch(): void
    {
        $ids = [];
        foreach (['r1', 'r2', 'r3'] as $name) {
            $ids[] = $this->service->enqueueDeferred($this->message($name))['id'];
        }
        $now = (new \DateTimeImmutable())->modify('+1 second');

        $this->http->queueResponse(new HttpResponse(429, '{}', ['Retry-After' => '120']));
        $result = $this->service->retryDue($now, 50);

        self::assertCount(1, $this->http->calls, 'the batch stops after the first 429');
        self::assertSame(1, $result['rateLimited']);
        $limited = [];
        $untouched = [];
        foreach ($ids as $id) {
            $row = $this->repo->find($id);
            self::assertSame(0, $row['attempts'], 'a 429 does not consume an attempt');
            if ($row['lastError'] === 'rate_limited') {
                $limited[] = $row;
            } else {
                $untouched[] = $row;
            }
        }
        self::assertCount(1, $limited);
        self::assertCount(2, $untouched);
        self::assertSame($now->modify('+120 seconds')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $limited[0]['nextAttemptAt']);
        foreach ($untouched as $row) {
            self::assertSame('queued', $row['status']);
        }

        foreach ($ids as $ignored) {
            $this->http->queueResponse(new HttpResponse(201, (string) json_encode(['id' => 'cm-' . bin2hex(random_bytes(3)), 'status' => 'pending'])));
        }
        $again = $this->service->retryDue($now->modify('+121 seconds'), 50);
        self::assertSame(3, $again['sent']);
        self::assertSame(0, $again['rateLimited']);
        self::assertCount(4, $this->http->calls);
    }

    public function testRetryAfterIsClampedAndMissingHeaderWaitsAMinute(): void
    {
        $now = (new \DateTimeImmutable())->modify('+1 second');
        $cases = [['5', 60], ['99999', 3600], [null, 60]];
        foreach ($cases as $i => [$header, $expected]) {
            $id = $this->service->enqueueDeferred($this->message('c' . $i))['id'];
            $this->http->queueResponse(new HttpResponse(429, '{}', $header === null ? [] : ['Retry-After' => $header]));
            $this->service->retryDue($now, 50);
            self::assertSame(
                $now->modify('+' . $expected . ' seconds')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $this->repo->find($id)['nextAttemptAt']
            );
            $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE id = :id')->execute(['id' => $id]);
        }
    }

    public function testPermanentErrorsAreTerminal(): void
    {
        $now = (new \DateTimeImmutable())->modify('+1 second');
        foreach ([409, 422, 400, 413] as $status) {
            $this->http->queueResponse(new HttpResponse($status, '{"error":"x"}'));
            $id = $this->service->enqueueDeferred($this->message('p' . $status))['id'];
            $this->service->retryDue($now, 50);

            $row = $this->repo->find($id);
            self::assertNull($row['nextAttemptAt'], 'HTTP ' . $status);
            self::assertSame('failed', $row['status']);
            self::assertSame(1, $row['attempts']);
        }
        $calls = count($this->http->calls);
        self::assertSame(0, $this->service->retryDue($now->modify('+30 days'), 50)['checked']);
        self::assertCount($calls, $this->http->calls);

        $warnings = array_filter($this->logs->getRecords(), static fn ($r): bool => $r->level === Level::Warning);
        self::assertCount(1, $warnings, '422 (suppressed) is a warning');
        self::assertArrayNotHasKey('alert', array_values($warnings)[0]->context);
        $errors = array_values(array_filter($this->logs->getRecords(), static fn ($r): bool => $r->level === Level::Error));
        self::assertCount(3, $errors, '409/400/413 alert');
        foreach ($errors as $record) {
            self::assertSame('mail.outbox_abandoned', $record->context['alert']);
        }
    }

    public function testDueFilterRunsInSqlBeforeLimit(): void
    {
        $now = new \DateTimeImmutable();
        $future = $now->modify('+1 hour')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $old = $now->modify('-5 hours')->format(DATE_ATOM);
        for ($i = 0; $i < 60; $i++) {
            $this->insertRow('nd' . $i, 'failed', 1, $future, $old);
        }
        $dueId = $this->service->enqueueDeferred($this->message('due'))['id'];
        $this->http->queueResponse(new HttpResponse(201, (string) json_encode(['id' => 'cm-due', 'status' => 'pending'])));

        $result = $this->service->retryDue($now->modify('+1 second'), 50);

        self::assertCount(1, $this->http->calls);
        self::assertSame(1, $result['checked']);
        self::assertSame('cm-due', $this->repo->find($dueId)['centralMailerEmailId']);
    }

    public function testTechnicalPriorityGoesFirst(): void
    {
        $this->service->enqueueDeferred($this->message('normal'));
        $this->service->enqueueDeferred($this->message('tech', ['priority' => 'technical']));
        $this->pdo->prepare('UPDATE central_mailer_outbox SET next_attempt_at = :t WHERE idempotency_key = :k')
            ->execute(['t' => '2000-01-01 00:00:00', 'k' => $this->prefix . '-normal']);
        foreach ([1, 2] as $ignored) {
            $this->http->queueResponse(new HttpResponse(201, (string) json_encode(['id' => 'cm-' . bin2hex(random_bytes(3)), 'status' => 'pending'])));
        }

        $this->service->retryDue((new \DateTimeImmutable())->modify('+1 second'), 50);

        self::assertCount(2, $this->http->calls);
        self::assertSame($this->prefix . '-tech', $this->http->calls[0]['headers']['Idempotency-Key']);
        self::assertSame($this->prefix . '-normal', $this->http->calls[1]['headers']['Idempotency-Key']);
    }

    public function testLegacyAndAcceptedRowsAreNeverDispatched(): void
    {
        $now = new \DateTimeImmutable();
        $past = $now->modify('-1 hour')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $this->insertRow('legacy-failed', 'failed', 1, null, $now->format(DATE_ATOM));
        $this->insertRow('legacy-queued', 'queued', 0, null, $now->format(DATE_ATOM));
        $this->insertRow('accepted', 'pending', 1, $past, $now->format(DATE_ATOM), 'cm-existing');
        $this->insertRow('sent', 'sent', 1, null, $now->format(DATE_ATOM), 'cm-sent');

        $result = $this->service->retryDue($now->modify('+30 days'), 50);

        self::assertSame(0, $result['checked']);
        self::assertCount(0, $this->http->calls);
    }

    public function testMaxAgeAbandons(): void
    {
        $now = new \DateTimeImmutable();
        $id = $this->insertRow('ancient', 'failed', 2, '2000-01-01 00:00:00', $now->modify('-49 hours')->format(DATE_ATOM));

        $result = $this->service->retryDue($now, 50);

        self::assertCount(0, $this->http->calls);
        self::assertSame(1, $result['abandoned']);
        $row = $this->repo->find($id);
        self::assertNull($row['nextAttemptAt']);
        self::assertSame('abandoned_max_age', $row['lastError']);
        $errors = array_values(array_filter($this->logs->getRecords(), static fn ($r): bool => $r->level === Level::Error));
        self::assertCount(1, $errors);
        self::assertSame('mail.outbox_abandoned', $errors[0]->context['alert']);
    }

    public function testStaleInFlightRowIsDueAfterLease(): void
    {
        $id = $this->service->enqueueDeferred($this->message('lease'))['id'];
        $now = new \DateTimeImmutable();
        $leaseUntil = $now->modify('+10 minutes')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        self::assertFalse($this->repo->claimDispatch($id, 5, $leaseUntil), 'a stale attempts value claims nothing');
        self::assertSame(0, $this->repo->find($id)['attempts']);
        self::assertTrue($this->repo->claimDispatch($id, 0, $leaseUntil), 'the process then "dies" mid-send');
        $claimed = $this->repo->find($id);
        self::assertSame(1, $claimed['attempts']);
        self::assertSame($leaseUntil, $claimed['nextAttemptAt']);

        self::assertSame(0, $this->service->retryDue($now->modify('+5 minutes'), 50)['checked']);
        $this->http->queueResponse(new HttpResponse(201, (string) json_encode(['id' => 'cm-lease', 'status' => 'pending'])));
        $result = $this->service->retryDue($now->modify('+11 minutes'), 50);

        self::assertSame(1, $result['sent']);
        self::assertSame(2, $this->repo->find($id)['attempts']);
    }

    public function testEnqueueOn429DoesNotThrowAndWaits(): void
    {
        $this->http->queueResponse(new HttpResponse(429, '{}'));

        $row = $this->service->enqueue($this->message('e429'));

        self::assertSame('failed', $row['status']);
        self::assertSame(0, $row['attempts']);
        $expected = (new \DateTimeImmutable('+60 seconds', new \DateTimeZone('UTC')))->getTimestamp();
        self::assertEqualsWithDelta($expected, $this->utc((string) $row['nextAttemptAt'])->getTimestamp(), 5);
    }

    public function testEnqueueDeferredMakesNoHttpCallAndIsIdempotent(): void
    {
        $first = $this->service->enqueueDeferred($this->message('def', ['attachments' => [['filename' => 'a.png', 'contentBase64' => 'QQ==', 'contentId' => 'a', 'inline' => true]]]));
        $second = $this->service->enqueueDeferred($this->message('def'));

        self::assertCount(0, $this->http->calls);
        self::assertSame($first['id'], $second['id']);
        self::assertSame('queued', $first['status']);
        self::assertSame(0, $first['attempts']);
        self::assertNull($first['centralMailerEmailId']);
        self::assertSame('a.png', $first['attachments'][0]['filename']);
    }

    /** @return array<string, mixed> */
    private function message(string $name, array $overrides = []): array
    {
        return $overrides + [
            'sourceEntityType' => 'phpunit-fixture',
            'sourceEntityId' => null,
            'idempotencyKey' => $this->prefix . '-' . $name,
            'to' => 'runner@example.test',
            'subject' => 'Temat ' . $name,
            'html' => '<p>Treść ' . $name . '</p>',
            'contextId' => null,
            'metadata' => null,
        ];
    }

    private function insertRow(string $name, string $status, int $attempts, ?string $nextAttemptAt, string $createdAt, ?string $cmId = null): string
    {
        $id = $this->prefix . '-' . $name;
        $this->pdo->prepare(
            "INSERT INTO central_mailer_outbox
               (id, source_entity_type, source_entity_id, idempotency_key, priority, recipient, subject, html, status,
                central_mailer_email_id, attempts, next_attempt_at, created_at)
             VALUES (:id, 'phpunit-fixture', NULL, :key, 'normal', 'runner@example.test', 's', '<p>x</p>', :status,
                :cm, :attempts, :next, :createdAt)"
        )->execute(['id' => substr($id, 0, 36), 'key' => $id, 'status' => $status, 'cm' => $cmId, 'attempts' => $attempts, 'next' => $nextAttemptAt, 'createdAt' => $createdAt]);

        return substr($id, 0, 36);
    }

    private function utc(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /** Rows other tests left behind must not be dispatched by (or distort) these runs. */
    private function clearUndeliveredRows(): void
    {
        $this->pdo->exec('DELETE FROM central_mailer_outbox WHERE central_mailer_email_id IS NULL AND next_attempt_at IS NOT NULL');
    }
}

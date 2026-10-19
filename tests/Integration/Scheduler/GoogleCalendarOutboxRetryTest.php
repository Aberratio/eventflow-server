<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Scheduler;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Calendar\GoogleCalendarClientInterface;
use ZmierzymyCzas\Domain\Calendar\GoogleCalendarSettingsRepository;
use ZmierzymyCzas\Domain\Calendar\GoogleCalendarSyncService;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Scheduler\Jobs\GoogleCalendarOutboxRetryJob;
use ZmierzymyCzas\Support\Env;

/** R-17: calendar.outbox_retry - sends queued entries, backs off on failure, updates instead of duplicating. */
final class GoogleCalendarOutboxRetryTest extends TestCase
{
    private PDO $pdo;
    private GoogleCalendarSettingsRepository $settings;
    private string $slug;
    private string $eventId;
    private object $client;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        $this->pdo = Connection::fromEnv();
        Migrator::applyAll($this->pdo);
        $this->slug = 'phpgcal-' . bin2hex(random_bytes(4));
        $this->settings = new GoogleCalendarSettingsRepository($this->pdo);
        $this->settings->update(['enabled' => true, 'calendarId' => 'cal@example.test', 'timeZone' => 'Europe/Warsaw']);
        $this->client = new class implements GoogleCalendarClientInterface {
            /** @var list<array{string, string, array<string, mixed>}> */
            public array $calls = [];
            public bool $fail = false;

            public function createEvent(string $calendarId, array $event): string
            {
                $this->calls[] = ['create', $calendarId, $event];
                if ($this->fail) {
                    throw new \RuntimeException('google down');
                }

                return 'g-1';
            }

            public function deleteEvent(string $calendarId, string $googleEventId): void
            {
                $this->calls[] = ['delete', $googleEventId, []];
            }

            public function updateEvent(string $calendarId, string $googleEventId, array $event): void
            {
                $this->calls[] = ['update', $googleEventId, $event];
            }
        };
        $this->eventId = (new EventRepository($this->pdo))->create([
            'name' => 'Gcal Job Event', 'slug' => $this->slug, 'discipline' => 'running', 'date' => '2027-09-20',
            'location' => 'Stadion', 'city' => 'Lodz', 'description' => '<p>x</p>', 'imageUrl' => 'https://example.test/e.jpg',
            'organizerId' => 'org-' . $this->slug, 'organizerName' => 'Org', 'status' => 'draft', 'registrationDeadline' => '2027-09-01',
            'featured' => false, 'isPrivate' => false, 'paymentConfig' => ['type' => 'admin_gateway'], 'distances' => [],
        ])['id'];
        $this->pdo->prepare("UPDATE events SET publication_status = 'published' WHERE id = :id")->execute(['id' => $this->eventId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->settings->update(['enabled' => false, 'calendarId' => '']);
            $this->pdo->prepare('DELETE FROM google_calendar_outbox WHERE event_id = :id')->execute(['id' => $this->eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $this->eventId]);
        }
    }

    public function testSkippedWhenIntegrationIsOffOrKeyMissing(): void
    {
        $now = new \DateTimeImmutable('2026-06-01 10:00:00');
        $sync = $this->service(null);
        $sync->enqueue($this->eventId);
        self::assertSame(['skipped' => 1], (new GoogleCalendarOutboxRetryJob($sync))->run($now));

        $this->settings->update(['enabled' => false]);
        self::assertSame(['skipped' => 1], (new GoogleCalendarOutboxRetryJob($this->service($this->client)))->run($now));
        self::assertSame([], $this->client->calls);
    }

    public function testSendsQueuedEntryStoresGoogleIdAndSecondSendUpdates(): void
    {
        $now = new \DateTimeImmutable('2026-06-01 10:00:00');
        $sync = $this->service($this->client);
        $sync->enqueue($this->eventId);
        self::assertSame($sync->enqueue($this->eventId), $sync->enqueue($this->eventId), 'enqueue is idempotent while pending');

        $result = (new GoogleCalendarOutboxRetryJob($sync))->run($now);
        self::assertSame(1, $result['sent']);
        self::assertSame('create', $this->client->calls[0][0]);
        self::assertSame('Gcal Job Event', $this->client->calls[0][2]['summary']);
        self::assertSame('g-1', $this->pdo->query("SELECT google_calendar_event_id FROM events WHERE id = '{$this->eventId}'")->fetchColumn());

        $sync->sendNow($this->eventId, $now);
        self::assertSame(['update', 'g-1'], [$this->client->calls[1][0], $this->client->calls[1][1]]);
    }

    public function testFailureBacksOffThenRetriesAndIsAbandonedAfterMaxAttempts(): void
    {
        $this->client->fail = true;
        $sync = $this->service($this->client);
        $job = new GoogleCalendarOutboxRetryJob($sync);
        $sync->enqueue($this->eventId);
        $t0 = new \DateTimeImmutable('2026-06-01 10:00:00');

        self::assertSame(1, $job->run($t0)['failed']);
        self::assertSame(0, $job->run($t0->modify('+1 minute'))['checked'], 'not due before the backoff');
        self::assertSame(1, $job->run($t0->modify('+6 minutes'))['failed']);

        $now = $t0->modify('+6 minutes');
        for ($i = 0; $i < GoogleCalendarSyncService::MAX_ATTEMPTS; ++$i) {
            $now = $now->modify('+2 days');
            $job->run($now);
        }
        $status = $this->pdo->query("SELECT status FROM google_calendar_outbox WHERE event_id = '{$this->eventId}'")->fetchColumn();
        self::assertSame('abandoned', $status);
    }

    private function service(?GoogleCalendarClientInterface $client): GoogleCalendarSyncService
    {
        return new GoogleCalendarSyncService($this->pdo, $this->settings, new EventRepository($this->pdo), $client, 'https://app.example.test');
    }
}

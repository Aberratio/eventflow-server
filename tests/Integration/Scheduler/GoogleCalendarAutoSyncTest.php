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

/**
 * R-17 follow-up: a changed date/time/place/title of an approved Event queues an update, a cancelled / deleted
 * Event queues a delete (only when the integration is active and the entry exists); the worker performs it.
 */
final class GoogleCalendarAutoSyncTest extends TestCase
{
    private PDO $pdo;
    private GoogleCalendarSettingsRepository $settings;
    private EventRepository $events;
    private string $eventId;
    private object $client;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        $this->pdo = Connection::fromEnv();
        Migrator::applyAll($this->pdo);
        $this->settings = new GoogleCalendarSettingsRepository($this->pdo);
        $this->settings->update(['enabled' => true, 'calendarId' => 'cal@example.test', 'timeZone' => 'Europe/Warsaw']);
        $this->events = new EventRepository($this->pdo);
        $this->client = new class implements GoogleCalendarClientInterface {
            /** @var list<array{string, string}> */
            public array $calls = [];
            public ?\Throwable $deleteError = null;

            public function createEvent(string $calendarId, array $event): string
            {
                $this->calls[] = ['create', ''];

                return 'g-new';
            }

            public function updateEvent(string $calendarId, string $googleEventId, array $event): void
            {
                $this->calls[] = ['update', $googleEventId];
            }

            public function deleteEvent(string $calendarId, string $googleEventId): void
            {
                $this->calls[] = ['delete', $googleEventId];
                if ($this->deleteError !== null) {
                    throw $this->deleteError;
                }
            }
        };
        $slug = 'phpgcal-auto-' . bin2hex(random_bytes(4));
        $this->eventId = $this->events->create([
            'name' => 'Auto Sync Event', 'slug' => $slug, 'discipline' => 'running', 'date' => '2027-09-20',
            'location' => 'Stadion', 'city' => 'Lodz', 'description' => '<p>x</p>', 'imageUrl' => 'https://example.test/e.jpg',
            'organizerId' => 'org-' . $slug, 'organizerName' => 'Org', 'status' => 'draft', 'registrationDeadline' => '2027-09-01',
            'featured' => false, 'isPrivate' => false, 'paymentConfig' => ['type' => 'admin_gateway'], 'distances' => [],
        ])['id'];
        $this->pdo->prepare("UPDATE events SET publication_status = 'published', google_calendar_event_id = 'g-1' WHERE id = :id")
            ->execute(['id' => $this->eventId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->settings->update(['enabled' => false, 'calendarId' => '']);
            $this->pdo->prepare('DELETE FROM google_calendar_outbox WHERE event_id = :id')->execute(['id' => $this->eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $this->eventId]);
        }
    }

    public function testDateChangeQueuesOneUpdateAndStaysIdempotent(): void
    {
        $sync = $this->service();
        $before = $this->events->find($this->eventId);
        $after = $this->events->update($this->eventId, ['date' => '2027-10-01']);

        $id = $sync->enqueueUpdateOnChange($before, $after);
        self::assertNotNull($id);
        self::assertSame($id, $sync->enqueueUpdateOnChange($before, $after), 'no duplicate task');
        self::assertSame([['upsert', 'queued']], $this->outbox());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function relevantChanges(): array
    {
        return [
            'date' => [['date' => '2027-10-01']],
            'title' => [['name' => 'Nowa nazwa']],
            'location' => [['location' => 'Inny stadion']],
            'city' => [['city' => 'Krakow']],
        ];
    }

    /**
     * @dataProvider relevantChanges
     * @param array<string, mixed> $change
     */
    public function testRelevantFieldChangeQueuesUpdate(array $change): void
    {
        $before = $this->events->find($this->eventId);
        $after = $this->events->update($this->eventId, $change);

        self::assertNotNull($this->service()->enqueueUpdateOnChange($before, $after));
    }

    public function testInternalDescriptionChangeQueuesNothing(): void
    {
        $before = $this->events->find($this->eventId);
        $after = $this->events->update($this->eventId, ['description' => '<p>zmieniony opis wewnetrzny</p>']);

        self::assertNull($this->service()->enqueueUpdateOnChange($before, $after));
        self::assertSame([], $this->outbox());
    }

    public function testDisabledIntegrationQueuesNothing(): void
    {
        $this->settings->update(['enabled' => false]);
        $sync = $this->service();
        $before = $this->events->find($this->eventId);
        $after = $this->events->update($this->eventId, ['date' => '2027-10-01']);

        self::assertNull($sync->enqueueUpdateOnChange($before, $after));
        self::assertNull($sync->enqueueDeleteForEvent($this->eventId));
        self::assertSame([], $this->outbox());
    }

    public function testNothingQueuedWhenEntryNeverCreatedOrEventNotPublished(): void
    {
        $sync = $this->service();
        $before = $this->events->find($this->eventId);
        $after = $this->events->update($this->eventId, ['date' => '2027-10-01']);

        $this->pdo->prepare('UPDATE events SET google_calendar_event_id = NULL WHERE id = :id')->execute(['id' => $this->eventId]);
        $after = $this->events->find($this->eventId);
        self::assertNull($sync->enqueueUpdateOnChange($before, $after), 'no entry in Google yet');
        self::assertNull($sync->enqueueDeleteForEvent($this->eventId), 'no entry in Google yet');

        $this->pdo->prepare("UPDATE events SET google_calendar_event_id = 'g-1', publication_status = 'draft' WHERE id = :id")->execute(['id' => $this->eventId]);
        $draft = $this->events->find($this->eventId);
        self::assertNull($sync->enqueueUpdateOnChange($before, $draft), 'not published');
        self::assertSame([], $this->outbox());
    }

    public function testCancellationQueuesDeleteOnceAndSupersedesPendingUpdate(): void
    {
        $sync = $this->service();
        $before = $this->events->find($this->eventId);
        $after = $this->events->update($this->eventId, ['date' => '2027-10-01']);
        $sync->enqueueUpdateOnChange($before, $after);

        $this->events->cancel($this->eventId);
        $id = $sync->enqueueDeleteForEvent($this->eventId);
        self::assertNotNull($id);
        self::assertSame($id, $sync->enqueueDeleteForEvent($this->eventId), 'no duplicate task');
        self::assertEqualsCanonicalizing([['upsert', 'abandoned'], ['delete', 'queued']], $this->outbox());
    }

    public function testSoftDeletedEventStillQueuesDelete(): void
    {
        $this->events->delete($this->eventId);

        self::assertNotNull($this->service()->enqueueDeleteForEvent($this->eventId));
        self::assertSame([['delete', 'queued']], $this->outbox());
    }

    public function testWorkerDeleteRemovesCalendarEntryAndClearsStoredId(): void
    {
        $sync = $this->service();
        $this->events->cancel($this->eventId);
        $sync->enqueueDeleteForEvent($this->eventId);

        $result = (new GoogleCalendarOutboxRetryJob($sync))->run(new \DateTimeImmutable('2026-06-01 10:00:00'));

        self::assertSame(1, $result['sent']);
        self::assertSame([['delete', 'g-1']], $this->client->calls);
        self::assertNull($this->pdo->query("SELECT google_calendar_event_id FROM events WHERE id = '{$this->eventId}'")->fetchColumn());
        self::assertSame([['delete', 'sent']], $this->outbox());
    }

    public function testWorkerDeleteFailureBacksOffAndKeepsStoredId(): void
    {
        $this->client->deleteError = new \RuntimeException('google down');
        $sync = $this->service();
        $this->events->cancel($this->eventId);
        $sync->enqueueDeleteForEvent($this->eventId);

        $result = (new GoogleCalendarOutboxRetryJob($sync))->run(new \DateTimeImmutable('2026-06-01 10:00:00'));

        self::assertSame(1, $result['failed']);
        self::assertSame('g-1', $this->pdo->query("SELECT google_calendar_event_id FROM events WHERE id = '{$this->eventId}'")->fetchColumn());
        self::assertSame([['delete', 'failed']], $this->outbox());
    }

    public function testWorkerUpdateTaskUpdatesExistingEntry(): void
    {
        $sync = $this->service();
        $before = $this->events->find($this->eventId);
        $after = $this->events->update($this->eventId, ['date' => '2027-10-01']);
        $sync->enqueueUpdateOnChange($before, $after);

        (new GoogleCalendarOutboxRetryJob($sync))->run(new \DateTimeImmutable('2026-06-01 10:00:00'));

        self::assertSame([['update', 'g-1']], $this->client->calls);
    }

    /** @return list<array{string, string}> [action, status] */
    private function outbox(): array
    {
        $stmt = $this->pdo->prepare('SELECT action, status FROM google_calendar_outbox WHERE event_id = :id ORDER BY created_at, action');
        $stmt->execute(['id' => $this->eventId]);

        return array_map(static fn (array $r): array => [(string) $r['action'], (string) $r['status']], $stmt->fetchAll());
    }

    private function service(): GoogleCalendarSyncService
    {
        return new GoogleCalendarSyncService($this->pdo, $this->settings, $this->events, $this->client, 'https://app.example.test');
    }
}

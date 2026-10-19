<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Calendar;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ZmierzymyCzas\Domain\Bz\Http\CurlHttpClient;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Support\Env;

/**
 * R-17 (§5.9 / §4.4.13): puts an approved Event into the configured Google calendar.
 *
 * Approval never talks to Google: it only enqueues a row (enqueueForApprovedEvent) and the scheduler job
 * `calendar.outbox_retry` sends it, with backoff and a bounded number of attempts, so a Google outage can
 * never block moderation. The integration is active only when the admin enabled it, a calendar id is set
 * AND the service-account key is configured on the server; otherwise nothing is queued and nothing is sent.
 * The Google event id is stored on the Event (`events.google_calendar_event_id`): a later send for the same
 * Event updates that calendar entry instead of duplicating it (manual "send to calendar").
 * Not done (documented): automatic update on date change and delete on cancellation.
 */
final class GoogleCalendarSyncService
{
    public const MAX_ATTEMPTS = 8;
    private const BASE_BACKOFF_MINUTES = 5;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly PDO $pdo,
        private readonly GoogleCalendarSettingsRepository $settings,
        private readonly EventRepository $events,
        private readonly ?GoogleCalendarClientInterface $client,
        private readonly string $appUrl,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /** Wiring shared by routes.php and the scheduler. The client is null (integration inactive) without a key. */
    public static function fromEnv(PDO $pdo, ?LoggerInterface $logger = null): self
    {
        $config = GoogleCalendarConfig::fromEnv();

        return new self(
            $pdo,
            new GoogleCalendarSettingsRepository($pdo),
            new EventRepository($pdo),
            $config !== null ? new GoogleCalendarClient(new CurlHttpClient(), $config) : null,
            (string) Env::get('APP_URL', ''),
            $logger
        );
    }

    public function credentialsConfigured(): bool
    {
        return $this->client !== null;
    }

    /** Settings + key + calendar id all present: the one gate for enqueueing and sending. */
    public function isActive(): bool
    {
        $s = $this->settings->get();

        return $this->client !== null && $s['enabled'] && $s['calendarId'] !== '';
    }

    /** Never throws: approval must not depend on this. Returns the outbox id, or null when inactive. */
    public function enqueueForApprovedEvent(string $eventId): ?string
    {
        try {
            if (!$this->isActive()) {
                return null;
            }

            return $this->enqueue($eventId);
        } catch (\Throwable $e) {
            $this->logger->warning('Google Calendar enqueue failed', ['eventId' => $eventId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function enqueue(string $eventId): string
    {
        $stmt = $this->pdo->prepare("SELECT id FROM google_calendar_outbox WHERE event_id = :e AND status IN ('queued','failed') LIMIT 1");
        $stmt->execute(['e' => $eventId]);
        $existing = $stmt->fetchColumn();
        if (is_string($existing)) {
            return $existing;
        }
        $id = bin2hex(random_bytes(16));
        $id = substr($id, 0, 8) . '-' . substr($id, 8, 4) . '-4' . substr($id, 13, 3) . '-a' . substr($id, 17, 3) . '-' . substr($id, 20, 12);
        $this->pdo->prepare('INSERT INTO google_calendar_outbox (id, event_id, status, created_at) VALUES (:id, :e, \'queued\', :now)')
            ->execute(['id' => $id, 'e' => $eventId, 'now' => date('Y-m-d H:i:s')]);

        return $id;
    }

    /**
     * Immediate send for the admin's manual action. Throws \RuntimeException on a Google failure
     * (the row stays queued/failed for the job to retry).
     */
    public function sendNow(string $eventId, \DateTimeImmutable $now): void
    {
        $id = $this->enqueue($eventId);
        $stmt = $this->pdo->prepare('SELECT * FROM google_calendar_outbox WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new \RuntimeException('google_calendar_outbox_missing');
        }
        $error = $this->attempt($row, $now);
        if ($error !== null) {
            throw new \RuntimeException($error);
        }
    }

    /** @return array{checked: int, sent: int, failed: int, abandoned: int} */
    public function processDue(\DateTimeImmutable $now, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM google_calendar_outbox WHERE status IN ('queued','failed') "
            . 'AND (next_attempt_at IS NULL OR next_attempt_at <= :now) ORDER BY created_at ASC LIMIT ' . (int) $limit
        );
        $stmt->execute(['now' => $now->format('Y-m-d H:i:s')]);
        $out = ['checked' => 0, 'sent' => 0, 'failed' => 0, 'abandoned' => 0];
        foreach ($stmt->fetchAll() as $row) {
            ++$out['checked'];
            $error = $this->attempt($row, $now);
            $status = (string) $this->pdo->query("SELECT status FROM google_calendar_outbox WHERE id = '" . $row['id'] . "'")->fetchColumn();
            if ($status === 'sent') {
                ++$out['sent'];
            } elseif ($status === 'abandoned') {
                ++$out['abandoned'];
            } elseif ($error !== null) {
                ++$out['failed'];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return string|null the error message, null on success
     */
    private function attempt(array $row, \DateTimeImmutable $now): ?string
    {
        $id = (string) $row['id'];
        $s = $this->settings->get();
        if ($this->client === null || !$s['enabled'] || $s['calendarId'] === '') {
            return 'google_calendar_inactive';
        }
        $event = $this->events->find((string) $row['event_id']);
        if ($event === null || ($event['publicationStatus'] ?? '') !== 'published') {
            $this->finish($id, 'abandoned', 'event_not_published', null, (int) $row['attempts']);

            return null;
        }
        $body = GoogleCalendarEventMapper::map($event, $s['timeZone'], $this->appUrl);
        if ($body === null) {
            $this->finish($id, 'abandoned', 'event_has_no_date', null, (int) $row['attempts']);

            return null;
        }

        $attempts = (int) $row['attempts'] + 1;
        try {
            $googleId = (string) ($event['googleCalendarEventId'] ?? '');
            if ($googleId !== '') {
                $this->client->updateEvent($s['calendarId'], $googleId, $body);
            } else {
                $googleId = $this->client->createEvent($s['calendarId'], $body);
                $this->pdo->prepare('UPDATE events SET google_calendar_event_id = :g WHERE id = :id')
                    ->execute(['g' => $googleId, 'id' => $event['id']]);
            }
        } catch (\Throwable $e) {
            $message = substr($e->getMessage(), 0, 480);
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->finish($id, 'abandoned', $message, null, $attempts);
            } else {
                $next = $now->modify('+' . (self::BASE_BACKOFF_MINUTES * (2 ** ($attempts - 1))) . ' minutes');
                $this->finish($id, 'failed', $message, $next, $attempts);
            }
            $this->logger->warning('Google Calendar send failed', ['outboxId' => $id, 'attempts' => $attempts]);

            return $message;
        }
        $this->finish($id, 'sent', null, null, $attempts, $now);
        $this->settings->touchLastSync($now);

        return null;
    }

    private function finish(string $id, string $status, ?string $error, ?\DateTimeImmutable $next, int $attempts, ?\DateTimeImmutable $sentAt = null): void
    {
        $this->pdo->prepare(
            'UPDATE google_calendar_outbox SET status = :s, last_error = :err, next_attempt_at = :n, attempts = :a, sent_at = :sent WHERE id = :id'
        )->execute([
            's' => $status,
            'err' => $error,
            'n' => $next?->format('Y-m-d H:i:s'),
            'a' => $attempts,
            'sent' => $sentAt?->format('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }
}

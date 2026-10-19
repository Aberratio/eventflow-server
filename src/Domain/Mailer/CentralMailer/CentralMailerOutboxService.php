<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CentralMailer;

use Psr\Log\LoggerInterface;

/**
 * The single entry point E1.10 (and anything else that needs to send mail
 * through Central Mailer) should call — ties CentralMailerOutboxRepository and
 * CentralMailerClientInterface together into the outbox pattern recommended by
 * kontrakt-central-mailer.md §6.4: write the outbox row (with its deterministic
 * idempotency key) before the HTTP call, never after.
 *
 * What this class deliberately does NOT do: decide what a message says. Every
 * argument to enqueue() — subject, rendered HTML, idempotency key, priority — is
 * the caller's responsibility (E1.10's job); this class only guarantees the
 * outbox/HTTP-call ordering and records what happened.
 */
final class CentralMailerOutboxService
{
    /** POSTs that count towards the limit (429s do not). */
    public const MAX_ATTEMPTS = 6;
    /** A row created longer ago than this is never dispatched (abandoned with an alert). */
    public const MAX_AGE_HOURS = 48;

    /** Minutes to wait after failed attempt N. */
    private const BACKOFF_MINUTES = [1 => 2, 2 => 10, 3 => 30, 4 => 120, 5 => 360];
    /** A claimed or just-created row becomes due again after this (dead process). */
    private const IN_FLIGHT_LEASE_MINUTES = 10;
    private const RATE_LIMIT_DEFAULT_SECONDS = 60;
    private const RATE_LIMIT_MIN_SECONDS = 60;
    private const RATE_LIMIT_MAX_SECONDS = 3600;

    public function __construct(
        private readonly CentralMailerClientInterface $client,
        private readonly CentralMailerOutboxRepository $outbox,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Writes the outbox row and sends it right away. Central Mailer failures (5xx, network,
     * 429, rejections) never throw: the durable row is retried by retryDue() (mail.outbox_retry),
     * so a mailer outage cannot turn a business request into a 500. Database errors still throw.
     *
     * @param array{
     *   sourceEntityType: string,
     *   sourceEntityId?: ?string,
     *   idempotencyKey: string,
     *   priority?: string,
     *   to: string,
     *   subject: string,
     *   html: string,
     *   text?: ?string,
     *   contextId?: ?string,
     *   metadata?: ?array<string, mixed>,
     *   attachments?: ?list<array<string, mixed>>,
     * } $message
     * @return array<string, mixed> the outbox row (existing, if this idempotency
     *         key was already recorded — nothing is sent twice)
     */
    public function enqueue(array $message): array
    {
        // The idempotency key is the true "have we done this before" check, against our own
        // durable record, before Central Mailer is ever called.
        $existing = $this->outbox->findByIdempotencyKey($message['idempotencyKey']);
        if ($existing !== null) {
            return $existing;
        }

        $now = new \DateTimeImmutable();
        $row = $this->createRow($message, 1, self::utc($now->modify('+' . self::IN_FLIGHT_LEASE_MINUTES . ' minutes')));
        $this->dispatch($row, $now, 1);

        return $this->outbox->find($row['id']) ?? $row;
    }

    /**
     * Writes the outbox row without any HTTP call; mail.outbox_retry dispatches it (mass mails such as
     * "Publikacja wyników" are paced by the scheduler instead of hitting Central Mailer inline).
     *
     * @param array<string, mixed> $message same shape as enqueue()
     * @return array<string, mixed>
     */
    public function enqueueDeferred(array $message): array
    {
        $existing = $this->outbox->findByIdempotencyKey((string) $message['idempotencyKey']);
        if ($existing !== null) {
            return $existing;
        }

        return $this->createRow($message, 0, self::utc(new \DateTimeImmutable()));
    }

    /**
     * Dispatches due rows Central Mailer never accepted (deferred, failed, stale in-flight).
     * A 429 stops the batch: every further POST in this window would be refused too.
     *
     * @return array{checked: int, sent: int, failed: int, abandoned: int, rateLimited: int}
     */
    public function retryDue(\DateTimeImmutable $now, int $limit): array
    {
        $rows = $this->outbox->listDueForDispatch(self::utc($now), $limit);
        $counters = ['checked' => count($rows), 'sent' => 0, 'failed' => 0, 'abandoned' => 0, 'rateLimited' => 0];

        foreach ($rows as $row) {
            $id = (string) $row['id'];
            if (new \DateTimeImmutable((string) $row['createdAt']) < $now->modify('-' . self::MAX_AGE_HOURS . ' hours')) {
                $this->outbox->markTerminal($id, 'abandoned_max_age');
                $this->logAbandoned('Central Mailer outbox row abandoned: too old', $row, ['maxAgeHours' => self::MAX_AGE_HOURS]);
                $counters['abandoned']++;
                continue;
            }

            $attempts = (int) $row['attempts'];
            $leaseUntil = self::utc($now->modify('+' . self::IN_FLIGHT_LEASE_MINUTES . ' minutes'));
            if (!$this->outbox->claimDispatch($id, $attempts, $leaseUntil)) {
                continue;
            }

            $outcome = $this->dispatch($row, $now, $attempts + 1);
            if ($outcome === 'accepted') {
                $counters['sent']++;
            } elseif ($outcome === 'retry') {
                $counters['failed']++;
            } elseif ($outcome === 'terminal') {
                $counters['abandoned']++;
            } else {
                $counters['rateLimited'] = 1;
                break;
            }
        }

        return $counters;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function createRow(array $message, int $attempts, string $nextAttemptAtUtc): array
    {
        return $this->outbox->create([
            'sourceEntityType' => $message['sourceEntityType'],
            'sourceEntityId' => $message['sourceEntityId'] ?? null,
            'idempotencyKey' => $message['idempotencyKey'],
            'priority' => $message['priority'] ?? 'normal',
            'recipient' => $message['to'],
            'subject' => $message['subject'],
            'html' => $message['html'],
            'contextId' => $message['contextId'] ?? null,
            // Canonicalised before it is stored AND before every send: MySQL 8.4 JSON columns
            // normalise key order, and Central Mailer hashes json_encode(metadata).
            'metadata' => self::canonical($message['metadata'] ?? null),
            'attachments' => $message['attachments'] ?? null,
            'attempts' => $attempts,
            'nextAttemptAt' => $nextAttemptAtUtc,
        ]);
    }

    /**
     * One POST for a stored row; the payload comes only from the row so a retry resends identical content.
     *
     * @param array<string, mixed> $row
     * @return 'accepted'|'rate_limited'|'retry'|'terminal'
     */
    private function dispatch(array $row, \DateTimeImmutable $now, int $attemptNumber): string
    {
        $id = (string) $row['id'];
        $payload = [
            'to' => (string) $row['recipient'],
            'subject' => (string) $row['subject'],
            'html' => (string) $row['html'],
            'text' => null,
            'priority' => (string) $row['priority'],
            'contextId' => $row['contextId'] ?? null,
            'metadata' => self::canonical($row['metadata'] ?? null),
        ];
        if (is_array($row['attachments'] ?? null) && $row['attachments'] !== []) {
            $payload['attachments'] = $row['attachments'];
        }

        try {
            $result = $this->client->enqueue($payload, (string) $row['idempotencyKey']);
        } catch (RateLimitedException $e) {
            $wait = max(self::RATE_LIMIT_MIN_SECONDS, min(self::RATE_LIMIT_MAX_SECONDS, $e->retryAfterSeconds ?? self::RATE_LIMIT_DEFAULT_SECONDS));
            $this->outbox->releaseRateLimited($id, $attemptNumber - 1, self::utc($now->modify('+' . $wait . ' seconds')));
            $this->logger?->info('Central Mailer rate limited; outbox row rescheduled', ['outboxId' => $id, 'retryAfterSeconds' => $wait]);

            return 'rate_limited';
        } catch (RecipientSuppressedException $e) {
            $this->outbox->markTerminal($id, $e::class);
            $this->logger?->warning('Central Mailer outbox row rejected permanently', $this->rowContext($row, $e));

            return 'terminal';
        } catch (IdempotencyConflictException | CentralMailerRejectedException | \InvalidArgumentException $e) {
            $this->outbox->markTerminal($id, $e::class);
            $this->logger?->error('Central Mailer outbox row rejected permanently', $this->rowContext($row, $e) + ['alert' => 'mail.outbox_abandoned']);

            return 'terminal';
        } catch (\Throwable $e) {
            $exhausted = $attemptNumber >= self::MAX_ATTEMPTS;
            $next = $exhausted ? null : self::utc($now->modify('+' . self::BACKOFF_MINUTES[$attemptNumber] . ' minutes'));
            $this->outbox->markRetryFailed($id, $e::class, $next);
            if ($exhausted) {
                $this->logger?->error('Central Mailer outbox row abandoned after max attempts', [
                    'outboxId' => $id,
                    'mailType' => (string) $row['sourceEntityType'],
                    'attempts' => $attemptNumber,
                    'alert' => 'mail.outbox_abandoned',
                ]);
            }

            return 'retry';
        }

        $this->outbox->markAccepted($id, $result['id'], $result['status']);

        return 'accepted';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rowContext(array $row, \Throwable $e): array
    {
        return ['outboxId' => (string) $row['id'], 'mailType' => (string) $row['sourceEntityType'], 'errorClass' => $e::class];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $extra
     */
    private function logAbandoned(string $message, array $row, array $extra = []): void
    {
        $this->logger?->error($message, [
            'outboxId' => (string) $row['id'],
            'mailType' => (string) $row['sourceEntityType'],
            'alert' => 'mail.outbox_abandoned',
        ] + $extra);
    }

    /** next_attempt_at is always UTC 'Y-m-d H:i:s' so SQL can compare it lexically. */
    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Recursive ksort: a stable key order for metadata, however the storage layer returns it.
     *
     * @param array<mixed>|null $value
     * @return array<mixed>|null
     */
    private static function canonical(?array $value): ?array
    {
        if ($value === null) {
            return null;
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * E1.10 §8 mass-send progress: queued/sent/failed counts for one mail type
     * within one Wydarzenie (its `contextId`), so an organizer sending tickets
     * to 500+ recipients sees a progress readout instead of silence during the
     * ~75-90 minutes the shared 100/15min budget takes (kontrakt-central-mailer.md
     * §7). Read-only; the scheduler's
     * mail.outbox_retry job does the dispatching.
     *
     * @return array{total: int, queued: int, sent: int, failed: int, other: int}
     */
    public function progressForContext(string $contextId, string $sourceEntityType): array
    {
        return $this->outbox->countByContext($contextId, $sourceEntityType);
    }

    /**
     * B-03 mail log (read-only): see CentralMailerOutboxRepository::listForLog().
     *
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, pageSize: int}
     */
    public function logPage(array $filters, bool $includeError): array
    {
        return $this->outbox->listForLog($filters, $includeError);
    }

    /**
     * Polls Central Mailer for every outbox row still awaiting a terminal
     * status and writes back whatever it reports (§6.4 point 3 — Central Mailer
     * has no webhooks, only polling). PP.18: the scheduler's
     * mail.outbox_reconcile job calls this every tick; it can also be called from
     * an admin/status endpoint.
     *
     * @return array{checked: int, updated: int}
     */
    public function reconcile(int $limit = 100): array
    {
        $rows = $this->outbox->listAwaitingReconciliation($limit);
        $updated = 0;

        foreach ($rows as $row) {
            $emailId = (string) $row['centralMailerEmailId'];
            try {
                $status = $this->client->getStatus($emailId);
            } catch (\Throwable) {
                // A single unreachable/erroring row must not abort the rest of
                // the batch; it simply keeps its last known status and will be
                // retried on the next call.
                continue;
            }

            $newStatus = (string) ($status['status'] ?? '');
            if ($newStatus === '') {
                continue;
            }

            $lastError = $status['lastError'] ?? null;
            $this->outbox->applyReconciledStatus($row['id'], $newStatus, $lastError !== null ? (string) $lastError : null);
            $updated++;
        }

        return ['checked' => count($rows), 'updated' => $updated];
    }
}

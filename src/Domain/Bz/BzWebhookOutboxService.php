<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Bz;

use Psr\Log\LoggerInterface;

/**
 * E3.4's entry point — ties BzWebhookOutboxRepository and
 * BzWebhookClientInterface together into the exact outbox pattern
 * CentralMailerOutboxService already established for E1.9: write the outbox
 * row (with its deterministic idempotency key) before the HTTP call, never
 * after, and never re-send once a key has already been recorded.
 *
 * What this class deliberately does NOT decide: whether to push at all for a
 * given Event (that is RegistrationRepository's job — it only constructs this
 * service's caller with a non-null instance, and only calls enqueue(), when
 * both BZ_WEBHOOK_BASE_URL is configured for this deployment AND the specific
 * Event has bz_integration_enabled + a bz_organization_token — mirrors
 * CriticalMailer's split between "is Central Mailer configured at all" and
 * "does this specific send have somewhere to go"). It also never decides *what*
 * the payload contains beyond passing it through untouched — the money-leakage
 * discipline (issue 12 §5, "bez kwot") is the caller's responsibility, same as
 * CentralMailerOutboxService trusts its caller for message content.
 */
final class BzWebhookOutboxService
{
    /** PP.18: total attempts (the immediate one plus retries) before a push is given up on. */
    public const MAX_ATTEMPTS = 8;
    /** Minutes to wait after attempt N failed, before attempt N+1. */
    private const BACKOFF_MINUTES = [1 => 5, 2 => 15, 3 => 60, 4 => 180, 5 => 360, 6 => 720, 7 => 1440];
    private const STALE_QUEUED_MINUTES = 10;

    public function __construct(
        private readonly BzWebhookClientInterface $client,
        private readonly BzWebhookOutboxRepository $outbox,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array{
     *   eventId: string,
     *   registrationId: string,
     *   changeType: string,
     *   idempotencyKey: string,
     *   organizationToken: string,
     *   payload: array<string, mixed>,
     * } $message
     * @return array<string, mixed> the outbox row (existing, if this
     *         idempotency key was already recorded — BZ is never notified
     *         twice for the same transition)
     */
    public function enqueue(array $message): array
    {
        $idempotencyKey = $message['idempotencyKey'];

        // The idempotency key is the true "have we already told BZ about this
        // exact transition" check — checked here, against our own durable
        // record, before BZ's webhook is ever called. This also covers the
        // case where a previous attempt died after writing the outbox row but
        // before the HTTP response came back (see
        // BzWebhookOutboxRepository::create()'s doc comment).
        $existing = $this->outbox->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $row = $this->outbox->create([
            'eventId' => $message['eventId'],
            'registrationId' => $message['registrationId'],
            'changeType' => $message['changeType'],
            'idempotencyKey' => $idempotencyKey,
            'payload' => $message['payload'],
        ]);

        try {
            $this->client->push($message['payload'], $message['organizationToken'], $idempotencyKey);
        } catch (\Throwable $e) {
            $this->outbox->markFailed($row['id'], $e->getMessage());
            throw $e;
        }

        $this->outbox->markSent($row['id']);

        return $this->outbox->find($row['id']);
    }

    /**
     * PP.18 (scheduler job bz.outbox_retry): retries `failed` pushes after their backoff and `queued`
     * ones older than STALE_QUEUED_MINUTES (the sending process died). The payload (with its original
     * `occurredAt`) and the Idempotency-Key are resent (the same JSON content; the JSON column may reorder keys); BZ dedups on the key. Never logs the
     * token or the payload.
     *
     * @param callable(string): ?string $organizationTokenFor null = BZ integration off / no token for that Event
     * @return array{checked: int, sent: int, failed: int, abandoned: int}
     */
    public function retryDue(\DateTimeImmutable $now, int $limit, callable $organizationTokenFor): array
    {
        $rows = $this->outbox->listRetryCandidates($limit, self::MAX_ATTEMPTS);
        $result = ['checked' => count($rows), 'sent' => 0, 'failed' => 0, 'abandoned' => 0];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if ($status === 'failed') {
                $next = $row['nextAttemptAt'] ?? null;
                if ($next !== null && $next !== '' && new \DateTimeImmutable((string) $next) > $now) {
                    continue;
                }
            } elseif (new \DateTimeImmutable((string) $row['createdAt']) > $now->modify('-' . self::STALE_QUEUED_MINUTES . ' minutes')) {
                continue;
            }

            $token = $organizationTokenFor((string) $row['eventId']);
            if ($token === null || $token === '') {
                $this->outbox->markAbandoned((string) $row['id'], 'bz_integration_disabled', self::MAX_ATTEMPTS);
                $result['abandoned']++;
                continue;
            }

            $seenAttempts = (int) $row['attempts'];
            if (!$this->outbox->claimAttempt((string) $row['id'], $status, $seenAttempts)) {
                continue;
            }
            $attempts = $seenAttempts + 1;

            try {
                $this->client->push($row['payload'], $token, (string) $row['idempotencyKey']);
            } catch (\Throwable $e) {
                $exhausted = $attempts >= self::MAX_ATTEMPTS;
                $this->outbox->markFailedWithBackoff(
                    (string) $row['id'],
                    $e::class,
                    $exhausted ? null : $now->modify('+' . (self::BACKOFF_MINUTES[$attempts] ?? 1440) . ' minutes')
                );
                $result['failed']++;
                if ($exhausted) {
                    $this->logger?->warning('BZ push abandoned after max attempts', [
                        'outboxId' => (string) $row['id'],
                        'eventId' => (string) $row['eventId'],
                        'registrationId' => (string) $row['registrationId'],
                        'changeType' => (string) $row['changeType'],
                    ]);
                }
                continue;
            }

            $this->outbox->markSent((string) $row['id']);
            $result['sent']++;
        }

        return $result;
    }
}

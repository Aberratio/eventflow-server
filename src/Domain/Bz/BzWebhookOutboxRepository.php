<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Bz;

use ZmierzymyCzas\Domain\Repository;

/**
 * CRUD on `bz_webhook_outbox` (see the migration's doc comment). Pure
 * persistence: every decision about *when* to write which row, and what the
 * payload contains, lives in BzWebhookOutboxService, not here — mirrors
 * CentralMailerOutboxRepository's own split exactly.
 */
final class BzWebhookOutboxRepository extends Repository
{
    private const COLUMNS = 'id, event_id AS eventId, registration_id AS registrationId, '
        . 'change_type AS changeType, idempotency_key AS idempotencyKey, payload, status, '
        . 'last_error AS lastError, attempts, next_attempt_at AS nextAttemptAt, created_at AS createdAt, sent_at AS sentAt';

    /**
     * Writes the outbox row — including its deterministic `idempotency_key` —
     * before any HTTP call to BZ is made. Same ordering rationale as
     * CentralMailerOutboxRepository::create(): if the process dies right after
     * this call and before the webhook's response comes back, the row is still
     * there with the same key for a retry to find via findByIdempotencyKey()
     * rather than minting a new one (and sending BZ a duplicate notification).
     *
     * @param array{
     *   eventId: string,
     *   registrationId: string,
     *   changeType: string,
     *   idempotencyKey: string,
     *   payload: array<string, mixed>,
     * } $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $id = $this->id();
        $this->insert('bz_webhook_outbox', $this->encode([
            'id' => $id,
            'event_id' => $data['eventId'],
            'registration_id' => $data['registrationId'],
            'change_type' => $data['changeType'],
            'idempotency_key' => $data['idempotencyKey'],
            'payload' => $data['payload'],
            'status' => 'queued',
            // The immediate attempt BzWebhookOutboxService::enqueue() makes right after this.
            'attempts' => 1,
            'created_at' => date(DATE_ATOM),
        ], ['payload']));

        return $this->find($id);
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM bz_webhook_outbox WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $this->decode($stmt->fetch(), ['payload']);
    }

    /**
     * The scope the enqueue path checks before ever calling BZ: this table
     * holds only this platform's own outbound pushes, so the idempotency key
     * alone (already scoped to registrationId:changeType) is enough of a check.
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM bz_webhook_outbox WHERE idempotency_key = :key');
        $stmt->execute(['key' => $idempotencyKey]);
        return $this->decode($stmt->fetch(), ['payload']);
    }

    /** BZ's webhook accepted the push (a 2xx response). */
    public function markSent(string $id): void
    {
        $this->updateById('bz_webhook_outbox', $id, [
            'status' => 'sent',
            'sent_at' => date(DATE_ATOM),
        ]);
    }

    /** The push itself failed (network error, non-2xx, thrown exception). */
    public function markFailed(string $id, string $error): void
    {
        $this->updateById('bz_webhook_outbox', $id, [
            'status' => 'failed',
            'last_error' => mb_substr($error, 0, 1024),
            // First retry 5 minutes after the immediate attempt failed.
            'next_attempt_at' => (new \DateTimeImmutable('+5 minutes'))->format(DATE_ATOM),
        ]);
    }

    /**
     * Rows the scheduler may retry — `failed` with attempts left, or `queued` (the process
     * died between writing the row and recording the result). The backoff / staleness checks are
     * done offset-aware in PHP by the caller; this is only the prefilter.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRetryCandidates(int $limit, int $maxAttempts): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT " . self::COLUMNS . " FROM bz_webhook_outbox
             WHERE (status = 'failed' AND attempts < :maxAttempts) OR status = 'queued'
             ORDER BY created_at ASC LIMIT :limit"
        );
        $stmt->bindValue('maxAttempts', $maxAttempts, \PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->decode($row, ['payload']), $stmt->fetchAll());
    }

    /** Takes one retry attempt; false when another run (or a status change) got there first. */
    public function claimAttempt(string $id, string $seenStatus, int $seenAttempts): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE bz_webhook_outbox SET attempts = attempts + 1
             WHERE id = :id AND status = :seenStatus AND attempts = :seenAttempts'
        );
        $stmt->execute(['id' => $id, 'seenStatus' => $seenStatus, 'seenAttempts' => $seenAttempts]);

        return $stmt->rowCount() === 1;
    }

    /** A retry failed. Stores only the exception class, never a message (it can carry a response body). */
    public function markFailedWithBackoff(string $id, string $errorClass, ?\DateTimeImmutable $nextAttemptAt): void
    {
        $this->updateById('bz_webhook_outbox', $id, [
            'status' => 'failed',
            'last_error' => mb_substr($errorClass, 0, 1024),
            'next_attempt_at' => $nextAttemptAt?->format(DATE_ATOM),
        ]);
    }

    /** Never retried again (integration switched off for the Event). */
    public function markAbandoned(string $id, string $reason, int $maxAttempts): void
    {
        $this->updateById('bz_webhook_outbox', $id, [
            'status' => 'failed',
            'last_error' => mb_substr($reason, 0, 1024),
            'attempts' => $maxAttempts,
            'next_attempt_at' => null,
        ]);
    }
}

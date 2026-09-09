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
        . 'last_error AS lastError, created_at AS createdAt, sent_at AS sentAt';

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
        ]);
    }
}

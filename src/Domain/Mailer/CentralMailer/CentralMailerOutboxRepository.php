<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CentralMailer;

use ZmierzymyCzas\Domain\Repository;

/**
 * CRUD on `central_mailer_outbox` (see the migration's doc comment for why this
 * table exists — kontrakt-central-mailer.md §6.4). Pure persistence: every
 * decision about *when* to write which row lives in CentralMailerOutboxService,
 * not here.
 */
final class CentralMailerOutboxRepository extends Repository
{
    private const COLUMNS = 'id, source_entity_type AS sourceEntityType, source_entity_id AS sourceEntityId, '
        . 'idempotency_key AS idempotencyKey, priority, recipient, subject, html, context_id AS contextId, '
        . 'metadata, central_mailer_email_id AS centralMailerEmailId, status, last_error AS lastError, '
        . 'created_at AS createdAt, sent_to_mailer_at AS sentToMailerAt, last_checked_at AS lastCheckedAt';

    /**
     * Writes the outbox row — including its deterministic `idempotency_key` —
     * before any HTTP call to Central Mailer is made. This ordering is the whole
     * point of the outbox (§6.4 point 2): if the process dies right after this
     * call and before the `POST /emails` response comes back, the row is still
     * there with the same key for a retry to find via findByIdempotencyKey()
     * rather than minting a new one.
     *
     * @param array{
     *   sourceEntityType: string,
     *   sourceEntityId?: ?string,
     *   idempotencyKey: string,
     *   priority: string,
     *   recipient: string,
     *   subject: string,
     *   html: string,
     *   contextId?: ?string,
     *   metadata?: ?array<string, mixed>,
     * } $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $id = $this->id();
        $this->insert('central_mailer_outbox', $this->encode([
            'id' => $id,
            'source_entity_type' => $data['sourceEntityType'],
            'source_entity_id' => $data['sourceEntityId'] ?? null,
            'idempotency_key' => $data['idempotencyKey'],
            'priority' => $data['priority'],
            'recipient' => $data['recipient'],
            'subject' => $data['subject'],
            'html' => $data['html'],
            'context_id' => $data['contextId'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'status' => 'queued',
            'created_at' => date(DATE_ATOM),
        ], ['metadata']));

        return $this->find($id);
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM central_mailer_outbox WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $this->decode($stmt->fetch(), ['metadata']);
    }

    /**
     * The scope the enqueue path checks before ever calling Central Mailer:
     * (sourceApp, Idempotency-Key) is the pair Central Mailer itself dedupes on
     * (§6.1), and sourceApp here is implicitly "this whole table", since it only
     * ever holds this platform's own outbound mail.
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM central_mailer_outbox WHERE idempotency_key = :key');
        $stmt->execute(['key' => $idempotencyKey]);
        return $this->decode($stmt->fetch(), ['metadata']);
    }

    /** Central Mailer accepted the message: record its id and the status it returned. */
    public function markAccepted(string $id, string $centralMailerEmailId, string $status): void
    {
        $this->updateById('central_mailer_outbox', $id, [
            'central_mailer_email_id' => $centralMailerEmailId,
            'status' => $status !== '' ? $status : 'pending',
            'sent_to_mailer_at' => date(DATE_ATOM),
        ]);
    }

    /** The `POST /emails` call itself failed (network error, non-2xx, thrown exception). */
    public function markFailed(string $id, string $error): void
    {
        $this->updateById('central_mailer_outbox', $id, [
            'status' => 'failed',
            'last_error' => mb_substr($error, 0, 1024),
        ]);
    }

    /** Reconciliation writes back whatever status Central Mailer reports for an already-accepted row. */
    public function applyReconciledStatus(string $id, string $status, ?string $lastError = null): void
    {
        $this->updateById('central_mailer_outbox', $id, [
            'status' => $status,
            'last_error' => $lastError !== null ? mb_substr($lastError, 0, 1024) : null,
            'last_checked_at' => date(DATE_ATOM),
        ]);
    }

    /**
     * E1.10 §8 ("Przepustowość: komunikat, nie cisza") — the read this
     * platform's mass-send progress bar needs: how many outbox rows for one
     * mail type in one Wydarzenie (`contextId`) are still `queued`, how many
     * ended up `sent`, and how many `failed`, plus a catch-all `other` bucket
     * for every other status Central Mailer might report (`pending`,
     * `processing`, `retry`, `unknown`, §6.3). No new outbox-wide feature here
     * — just a GROUP BY on the same table CentralMailerOutboxService already
     * writes to.
     *
     * @return array{total: int, queued: int, sent: int, failed: int, other: int}
     */
    public function countByContext(string $contextId, string $sourceEntityType): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS count FROM central_mailer_outbox '
            . 'WHERE context_id = :contextId AND source_entity_type = :sourceEntityType GROUP BY status'
        );
        $stmt->execute(['contextId' => $contextId, 'sourceEntityType' => $sourceEntityType]);

        $counts = ['total' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0, 'other' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $status = (string) $row['status'];
            $count = (int) $row['count'];
            $counts['total'] += $count;
            $counts[in_array($status, ['queued', 'sent', 'failed'], true) ? $status : 'other'] += $count;
        }

        return $counts;
    }

    /**
     * Rows accepted by Central Mailer (so they have a `central_mailer_email_id`
     * to poll) whose last known status is not terminal — the reconciliation
     * queue. `sent`/`failed` are terminal from this table's point of view; a
     * message quarantined `unknown` on Central Mailer's side (§6.3) is
     * deliberately kept in this list, since a human resolving it via
     * `POST /admin/emails/{id}/requeue` will eventually flip it to something
     * terminal and this table should pick that up.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAwaitingReconciliation(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM central_mailer_outbox '
            . "WHERE central_mailer_email_id IS NOT NULL AND status NOT IN ('sent', 'failed') "
            . 'ORDER BY sent_to_mailer_at ASC LIMIT :limit'
        );
        $stmt->bindValue('limit', max(1, min(1000, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->decode($row, ['metadata']), $stmt->fetchAll());
    }
}

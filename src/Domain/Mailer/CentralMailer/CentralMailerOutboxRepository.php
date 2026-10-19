<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CentralMailer;

use ZmierzymyCzas\Domain\Mailer\MailRecipientMask;
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
        . 'metadata, attachments, central_mailer_email_id AS centralMailerEmailId, status, attempts, '
        . 'next_attempt_at AS nextAttemptAt, last_error AS lastError, '
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
     *   attachments?: ?list<array<string, mixed>>,
     *   attempts?: int,
     *   nextAttemptAt?: ?string,
     * } $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $id = $this->id();
        $attachments = $data['attachments'] ?? null;
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
            // LONGTEXT on purpose: stored byte-for-byte so a retry resends identical attachments.
            'attachments' => $attachments !== null && $attachments !== []
                ? json_encode($attachments, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : null,
            'status' => 'queued',
            'attempts' => $data['attempts'] ?? 0,
            'next_attempt_at' => $data['nextAttemptAt'] ?? null,
            'created_at' => date(DATE_ATOM),
        ], ['metadata']));

        return $this->find($id);
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM central_mailer_outbox WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $this->decodeRow($stmt->fetch());
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
        return $this->decodeRow($stmt->fetch());
    }

    /** Central Mailer accepted the message: record its id and the status it returned. */
    public function markAccepted(string $id, string $centralMailerEmailId, string $status): void
    {
        $this->updateById('central_mailer_outbox', $id, [
            'central_mailer_email_id' => $centralMailerEmailId,
            'status' => $status !== '' ? $status : 'pending',
            'sent_to_mailer_at' => date(DATE_ATOM),
            'next_attempt_at' => null,
            'last_error' => null,
        ]);
    }

    /**
     * Due rows Central Mailer never accepted. The due filter and ordering run in SQL before
     * LIMIT (PP.18 D3 lesson): a PHP-side filter after LIMIT would starve due rows behind
     * not-yet-due ones. Technical-priority mail (auth links) goes first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDueForDispatch(string $nowUtc, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM central_mailer_outbox '
            . "WHERE central_mailer_email_id IS NULL AND status IN ('queued', 'failed') "
            . 'AND next_attempt_at IS NOT NULL AND next_attempt_at <= :nowUtc '
            . "ORDER BY (priority = 'technical') DESC, next_attempt_at ASC, id ASC LIMIT :limit"
        );
        $stmt->bindValue('nowUtc', $nowUtc);
        $stmt->bindValue('limit', max(1, min(500, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => (array) $this->decodeRow($row), $stmt->fetchAll());
    }

    /**
     * Optimistic claim: counts the attempt and leases the row until $leaseUntilUtc, so a process
     * that dies mid-send leaves it due again after the lease. False when another worker (or a
     * newer state) got there first.
     */
    public function claimDispatch(string $id, int $seenAttempts, string $leaseUntilUtc): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE central_mailer_outbox SET attempts = attempts + 1, next_attempt_at = :leaseUntil '
            . "WHERE id = :id AND attempts = :seenAttempts AND central_mailer_email_id IS NULL "
            . "AND status IN ('queued', 'failed') AND next_attempt_at IS NOT NULL"
        );
        $stmt->execute(['leaseUntil' => $leaseUntilUtc, 'id' => $id, 'seenAttempts' => $seenAttempts]);

        return $stmt->rowCount() === 1;
    }

    /** HTTP 429: the attempt is given back and the row waits for the Retry-After window. */
    public function releaseRateLimited(string $id, int $attempts, string $nextAttemptAtUtc): void
    {
        $this->updateById('central_mailer_outbox', $id, [
            'status' => 'failed',
            'attempts' => max(0, $attempts),
            'next_attempt_at' => $nextAttemptAtUtc,
            'last_error' => 'rate_limited',
        ]);
    }

    /** Transient failure: exception class only (CM error bodies can echo the address). NULL next = exhausted. */
    public function markRetryFailed(string $id, string $errorClass, ?string $nextAttemptAtUtc): void
    {
        $this->updateById('central_mailer_outbox', $id, [
            'status' => 'failed',
            'next_attempt_at' => $nextAttemptAtUtc,
            'last_error' => mb_substr($errorClass, 0, 1024),
        ]);
    }

    /** Permanent failure: never dispatched again (a manual requeue is a runbook SQL statement). */
    public function markTerminal(string $id, string $reason): void
    {
        $this->updateById('central_mailer_outbox', $id, [
            'status' => 'failed',
            'next_attempt_at' => null,
            'last_error' => mb_substr($reason, 0, 1024),
        ]);
    }

    public function existsForSource(string $sourceEntityType, string $sourceEntityId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM central_mailer_outbox WHERE source_entity_type = :type AND source_entity_id = :id LIMIT 1'
        );
        $stmt->execute(['type' => $sourceEntityType, 'id' => $sourceEntityId]);

        return $stmt->fetchColumn() !== false;
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
     * B-03 mail log: a read-only, paginated, newest-first view of the outbox for admins (all rows)
     * or one Wydarzenie's organizer (`contextId` forced by the caller). Never selects `html`,
     * `attachments` or `idempotency_key`; the recipient is masked (MailRecipientMask) and
     * `last_error` is returned only when `$includeError` (admin).
     *
     * Filters: contextId, mailType (exact), status (exact), search (admin only: recipient contains),
     * page, pageSize (default 25, max 100 — Repository::pagination()).
     *
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, pageSize: int}
     */
    public function listForLog(array $filters, bool $includeError): array
    {
        $where = [];
        $params = [];
        foreach (['contextId' => 'context_id', 'mailType' => 'source_entity_type', 'status' => 'status'] as $key => $column) {
            if (isset($filters[$key]) && is_string($filters[$key]) && $filters[$key] !== '') {
                $where[] = "{$column} = :{$key}";
                $params[$key] = $filters[$key];
            }
        }
        if (isset($filters['search']) && is_string($filters['search']) && $filters['search'] !== '') {
            $where[] = 'recipient LIKE :search';
            $params['search'] = '%' . addcslashes($filters['search'], '%_\\') . '%';
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $pagination = $this->pagination($filters);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM central_mailer_outbox{$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT id, source_entity_type AS mailType, priority, recipient, subject, context_id AS eventId, status, attempts, '
            . 'next_attempt_at AS nextAttemptAt, last_error AS lastError, created_at AS createdAt, '
            . 'sent_to_mailer_at AS sentToMailerAt, last_checked_at AS lastCheckedAt '
            . "FROM central_mailer_outbox{$whereSql} ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $pagination['pageSize'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $pagination['offset'], \PDO::PARAM_INT);
        $stmt->execute();

        $data = [];
        foreach ($stmt->fetchAll() as $row) {
            $entry = [
                'id' => (string) $row['id'],
                'mailType' => (string) $row['mailType'],
                'priority' => (string) $row['priority'],
                'recipient' => MailRecipientMask::mask((string) $row['recipient']),
                'subject' => (string) $row['subject'],
                'eventId' => $row['eventId'],
                'status' => (string) $row['status'],
                'attempts' => (int) $row['attempts'],
                'nextAttemptAt' => $row['nextAttemptAt'],
                'createdAt' => (string) $row['createdAt'],
                'sentToMailerAt' => $row['sentToMailerAt'],
                'lastCheckedAt' => $row['lastCheckedAt'],
            ];
            if ($includeError) {
                $entry['lastError'] = $row['lastError'];
            }
            $data[] = $entry;
        }

        return ['data' => $data, 'total' => $total, 'page' => $pagination['page'], 'pageSize' => $pagination['pageSize']];
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

        return array_map(fn (array $row): array => (array) $this->decodeRow($row), $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed>|false|null $row
     * @return array<string, mixed>|null
     */
    private function decodeRow(array|false|null $row): ?array
    {
        $decoded = $this->decode($row, ['metadata', 'attachments']);
        if ($decoded !== null) {
            $decoded['attempts'] = (int) $decoded['attempts'];
            if (!is_array($decoded['attachments'])) {
                $decoded['attachments'] = null;
            }
        }

        return $decoded;
    }
}

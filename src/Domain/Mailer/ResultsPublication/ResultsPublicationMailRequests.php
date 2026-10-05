<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\ResultsPublication;

use ZmierzymyCzas\Domain\Repository;

/**
 * PP.19: one row per (event, distance) saying "official results were published, mail the
 * participants". The publish request only writes this row (O(1)); the scheduler's
 * mail.results_published job fans out to the recipients.
 */
final class ResultsPublicationMailRequests extends Repository
{
    /** Marks the pair as pending again, also when a previous request was already processed. */
    public function request(string $eventId, string $distanceId, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO results_publication_mail_requests (event_id, distance_id, requested_at, processed_at, recipients_queued) '
            . 'VALUES (:eventId, :distanceId, :requestedAt, NULL, 0) '
            . 'ON DUPLICATE KEY UPDATE requested_at = :requestedAtAgain, processed_at = NULL'
        )->execute([
            'eventId' => $eventId,
            'distanceId' => $distanceId,
            'requestedAt' => $now->format(DATE_ATOM),
            'requestedAtAgain' => $now->format(DATE_ATOM),
        ]);
    }

    /** @return list<array{eventId: string, distanceId: string, requestedAt: string}> */
    public function listPending(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT event_id AS eventId, distance_id AS distanceId, requested_at AS requestedAt '
            . 'FROM results_publication_mail_requests WHERE processed_at IS NULL '
            . 'ORDER BY requested_at ASC, event_id ASC, distance_id ASC LIMIT :limit'
        );
        $stmt->bindValue('limit', max(1, min(200, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(static fn (array $row): array => [
            'eventId' => (string) $row['eventId'],
            'distanceId' => (string) $row['distanceId'],
            'requestedAt' => (string) $row['requestedAt'],
        ], $stmt->fetchAll()));
    }

    /**
     * Only when the request was not re-issued meanwhile: a re-publish during processing changes
     * requested_at, so the row stays pending for the next tick.
     */
    public function markProcessed(string $eventId, string $distanceId, string $seenRequestedAt, int $queued, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'UPDATE results_publication_mail_requests SET processed_at = :processedAt, recipients_queued = :queued '
            . 'WHERE event_id = :eventId AND distance_id = :distanceId AND requested_at = :seenRequestedAt'
        )->execute([
            'processedAt' => $now->format(DATE_ATOM),
            'queued' => $queued,
            'eventId' => $eventId,
            'distanceId' => $distanceId,
            'seenRequestedAt' => $seenRequestedAt,
        ]);
    }
}

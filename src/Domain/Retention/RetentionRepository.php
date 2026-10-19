<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Retention;

use ZmierzymyCzas\Domain\Repository;

/**
 * R-05 (specs 09, 10): data kept only for a bounded time. Run by the scheduler job
 * 'retention.purge', never as a side effect of a read.
 */
final class RetentionRepository extends Repository
{
    public const RETENTION_MONTHS = 12;

    /**
     * Deletes the answers to confidential ("poufne") form fields once their Event ended more than
     * RETENTION_MONTHS ago (end_date, falling back to the start date). Other answers are untouched.
     * Idempotent.
     */
    public function purgeConfidentialAnswers(\DateTimeImmutable $now): int
    {
        $cutoff = $now->modify('-' . self::RETENTION_MONTHS . ' months')->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            "DELETE a FROM form_field_answers a
               JOIN form_fields f ON f.id = a.form_field_id
               JOIN events e ON e.id = f.event_id
              WHERE f.is_confidential = 1
                AND COALESCE(NULLIF(e.end_date, ''), e.date) < :cutoff"
        );
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }
}

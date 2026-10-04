<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler;

use PDO;
use Psr\Log\LoggerInterface;

/**
 * PP.18: per-job last-run state in scheduler_job_runs. Never throws (a missing table, i.e. the
 * migration not applied yet, must not stop the jobs) and stores only the exception CLASS, never
 * a message (messages can carry third-party response bodies).
 */
class SchedulerRunLog
{
    public function __construct(private readonly ?PDO $pdo, private readonly ?LoggerInterface $logger = null)
    {
    }

    public function started(string $job): void
    {
        $this->guard(function () use ($job): void {
            $now = date(DATE_ATOM);
            $this->pdo?->prepare(
                "INSERT INTO scheduler_job_runs (job, last_started_at, last_status)
                 VALUES (:job, :startedInsert, 'running')
                 ON DUPLICATE KEY UPDATE last_started_at = :startedUpdate, last_status = 'running'"
            )->execute(['job' => $job, 'startedInsert' => $now, 'startedUpdate' => $now]);
        });
    }

    /** @param array<string, int>|null $counters */
    public function finished(string $job, string $status, ?string $errorClass, ?array $counters): void
    {
        $this->guard(function () use ($job, $status, $errorClass, $counters): void {
            $this->pdo?->prepare(
                'UPDATE scheduler_job_runs
                 SET last_finished_at = :finishedAt, last_status = :status,
                     last_error_class = :errorClass, last_counters = :counters
                 WHERE job = :job'
            )->execute([
                'finishedAt' => date(DATE_ATOM),
                'status' => $status,
                'errorClass' => $errorClass,
                'counters' => $counters === null ? null : json_encode($counters),
                'job' => $job,
            ]);
        });
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            return $this->pdo->query(
                'SELECT job, last_started_at, last_finished_at, last_status, last_error_class
                 FROM scheduler_job_runs ORDER BY job'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }

    private function guard(callable $write): void
    {
        try {
            $write();
        } catch (\Throwable $e) {
            $this->logger?->warning('Scheduler run log unavailable', ['exception' => $e]);
        }
    }
}

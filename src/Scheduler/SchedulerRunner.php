<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler;

use PDO;
use Psr\Log\LoggerInterface;

/** Lock -> jobs in order -> per-job isolation -> run log -> exit code. */
final class SchedulerRunner
{
    /**
     * @param list<ScheduledJob>       $jobs
     * @param null|callable(string): void $out one-line-per-job output for manual runs
     */
    public function __construct(
        private readonly SchedulerLock $lock,
        private readonly SchedulerRunLog $runLog,
        private readonly LoggerInterface $logger,
        private readonly array $jobs,
        private readonly ?PDO $pdo = null,
        private readonly mixed $out = null,
    ) {
    }

    /** @return int 0 = all ok/skipped (or lock held), 1 = at least one job failed */
    public function run(?string $only = null): int
    {
        if (!$this->lock->acquire()) {
            $this->logger->warning('Scheduler run skipped: another run holds the lock');

            return 0;
        }

        $failed = false;
        try {
            foreach ($this->jobs as $job) {
                $name = $job->name();
                if ($only !== null && $only !== $name) {
                    continue;
                }
                $started = microtime(true);
                $this->runLog->started($name);
                try {
                    $counters = $job->run(new \DateTimeImmutable());
                    $status = isset($counters['skipped']) ? 'skipped' : 'ok';
                    $ms = (int) round((microtime(true) - $started) * 1000);
                    $this->runLog->finished($name, $status, null, $counters);
                    $this->logger->info('Scheduled job finished', ['job' => $name, 'status' => $status, 'counters' => $counters, 'durationMs' => $ms]);
                    $this->emit($name . ' ' . $status . ' ' . json_encode($counters) . ' ' . $ms . 'ms');
                } catch (\Throwable $e) {
                    if ($this->pdo?->inTransaction()) {
                        try {
                            $this->pdo->rollBack();
                        } catch (\Throwable) {
                            // connection gone; nothing to roll back
                        }
                    }
                    $ms = (int) round((microtime(true) - $started) * 1000);
                    $this->runLog->finished($name, 'failed', $e::class, null);
                    $this->logger->error('Scheduled job failed', ['job' => $name, 'exception' => $e, 'alert' => 'scheduler.job_failed']);
                    $this->emit($name . ' failed ' . $e::class . ' ' . $ms . 'ms');
                    $failed = true;
                }
            }
        } finally {
            $this->lock->release();
        }

        return $failed ? 1 : 0;
    }

    private function emit(string $line): void
    {
        if (is_callable($this->out)) {
            ($this->out)($line);
        }
    }
}

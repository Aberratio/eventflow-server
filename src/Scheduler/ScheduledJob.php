<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler;

/** PP.18: one unit of work run by bin/scheduler.php. Must be idempotent (retried every tick, may overlap on lock loss). */
interface ScheduledJob
{
    /** Stable job name, e.g. 'registrations.expire_unpaid'. Listed in SchedulerJobs::NAMES. */
    public function name(): string;

    /**
     * @return array<string, int> PII-free counters, e.g. ['checked' => 3, 'expired' => 2];
     *                            ['skipped' => 1] means "integration not configured".
     */
    public function run(\DateTimeImmutable $now): array;
}

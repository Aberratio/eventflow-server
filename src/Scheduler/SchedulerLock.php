<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler;

/** Guards against two scheduler runs overlapping. */
interface SchedulerLock
{
    /** Non-blocking. True when this process now holds the lock. */
    public function acquire(): bool;

    public function release(): void;
}

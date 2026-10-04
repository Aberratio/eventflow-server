<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Support\Scheduler;

use ZmierzymyCzas\Scheduler\SchedulerLock;

final class FakeLock implements SchedulerLock
{
    public int $released = 0;

    public function __construct(private readonly bool $acquirable)
    {
    }

    public function acquire(): bool
    {
        return $this->acquirable;
    }

    public function release(): void
    {
        ++$this->released;
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Support\Scheduler;

use ZmierzymyCzas\Scheduler\ScheduledJob;

final class FakeJob implements ScheduledJob
{
    /** @param array<string, int> $counters */
    public function __construct(
        private readonly string $name,
        private readonly \ArrayObject $calls,
        private readonly array $counters,
        private readonly ?\Throwable $throws = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function run(\DateTimeImmutable $now): array
    {
        $this->calls[] = $this->name;
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->counters;
    }
}

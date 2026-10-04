<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler\Jobs;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Scheduler\ScheduledJob;

/** PP.18: Published -> Finished for events whose last day has passed (was lazy-on-read only). */
final class FinishPastEventsJob implements ScheduledJob
{
    public function __construct(private readonly EventRepository $events)
    {
    }

    public function name(): string
    {
        return 'events.finish_past';
    }

    public function run(\DateTimeImmutable $now): array
    {
        return ['finished' => $this->events->finishPastEvents()];
    }
}

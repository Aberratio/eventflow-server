<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler\Jobs;

use ZmierzymyCzas\Domain\Calendar\GoogleCalendarSyncService;
use ZmierzymyCzas\Scheduler\ScheduledJob;

/** R-17: sends queued / failed Google Calendar entries. Skipped while the integration is off or has no key. */
final class GoogleCalendarOutboxRetryJob implements ScheduledJob
{
    public const BATCH_LIMIT = 50;

    public function __construct(private readonly GoogleCalendarSyncService $sync)
    {
    }

    public function name(): string
    {
        return 'calendar.outbox_retry';
    }

    public function run(\DateTimeImmutable $now): array
    {
        if (!$this->sync->isActive()) {
            return ['skipped' => 1];
        }

        return $this->sync->processDue($now, self::BATCH_LIMIT);
    }
}

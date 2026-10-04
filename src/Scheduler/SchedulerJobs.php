<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler;

use PDO;
use Psr\Log\LoggerInterface;
use ZmierzymyCzas\Scheduler\Jobs\FinishPastEventsJob;

/**
 * PP.18: the job registry and the single plug-in point for new jobs (PP.19 adds
 * 'mail.outbox_retry' after 'mail.outbox_reconcile'). Order matters: expiry runs before
 * reminders so an already-lapsed Zapis never gets a "you are about to expire" mail.
 */
final class SchedulerJobs
{
    public const NAMES = [
        'events.finish_past',
    ];

    /** @return list<ScheduledJob> in NAMES order */
    public static function build(PDO $pdo, LoggerInterface $logger): array
    {
        return [
            new FinishPastEventsJob(new \ZmierzymyCzas\Domain\Events\EventRepository($pdo)),
        ];
    }
}

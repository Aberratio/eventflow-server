<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Scheduler\SchedulerJobs;

final class SchedulerJobsTest extends TestCase
{
    /** The registry order is a contract: later jobs were appended 'mail.results_published' and 'mail.outbox_retry' after 'mail.outbox_reconcile'. */
    public function testJobNamesAndOrderArePinned(): void
    {
        self::assertSame([
            'events.finish_past',
            'registrations.form_snapshots',
            'registrations.expire_unpaid',
            'registrations.payment_reminders',
            'bz.outbox_retry',
            'mail.outbox_reconcile',
            'mail.results_published',
            'mail.outbox_retry',
            'calendar.outbox_retry',
            'retention.purge',
        ], SchedulerJobs::NAMES);
    }
}

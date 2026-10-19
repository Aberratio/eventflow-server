<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler\Jobs;

use ZmierzymyCzas\Domain\Admin\AdminRepository;
use ZmierzymyCzas\Domain\Retention\RetentionRepository;
use ZmierzymyCzas\Scheduler\ScheduledJob;

/** R-05: 12-month retention of confidential form answers and of the activity log (specs 09, 10). */
final class RetentionPurgeJob implements ScheduledJob
{
    public function __construct(
        private readonly RetentionRepository $retention,
        private readonly AdminRepository $admin,
    ) {
    }

    public function name(): string
    {
        return 'retention.purge';
    }

    public function run(\DateTimeImmutable $now): array
    {
        return [
            'confidentialAnswers' => $this->retention->purgeConfidentialAnswers($now),
            'activityLogs' => $this->admin->purgeExpiredActivityLogs($now),
        ];
    }
}

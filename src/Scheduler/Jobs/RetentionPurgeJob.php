<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler\Jobs;

use ZmierzymyCzas\Domain\Admin\AdminRepository;
use ZmierzymyCzas\Domain\Retention\RetentionConfig;
use ZmierzymyCzas\Domain\Retention\RetentionRepository;
use ZmierzymyCzas\Scheduler\ScheduledJob;

/** Configurable retention (RetentionConfig: 90 days / 12 months by default) of confidential form answers and of the activity log. */
final class RetentionPurgeJob implements ScheduledJob
{
    public function __construct(
        private readonly RetentionRepository $retention,
        private readonly AdminRepository $admin,
        private readonly RetentionConfig $config = new RetentionConfig(),
    ) {
    }

    public function name(): string
    {
        return 'retention.purge';
    }

    public function run(\DateTimeImmutable $now): array
    {
        return [
            'confidentialAnswers' => $this->retention->purgeConfidentialAnswers($now, $this->config->confidentialAnswersDays),
            'activityLogs' => $this->admin->purgeExpiredActivityLogs($now, $this->config->activityLogMonths),
        ];
    }
}

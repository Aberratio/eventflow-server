<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler\Jobs;

use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxService;
use ZmierzymyCzas\Scheduler\ScheduledJob;

/**
 * PP.18: polls Central Mailer for the status of accepted outbox rows (GET only). Re-sending failed
 * rows and 429 handling are PP.19 (a 'mail.outbox_retry' job plugs into SchedulerJobs).
 */
final class MailOutboxReconcileJob implements ScheduledJob
{
    public const BATCH_LIMIT = 50;

    public function __construct(
        private readonly CentralMailerOutboxService $outbox,
        private readonly CentralMailerConfig $config,
    ) {
    }

    public function name(): string
    {
        return 'mail.outbox_reconcile';
    }

    public function run(\DateTimeImmutable $now): array
    {
        if (!$this->config->isConfigured()) {
            return ['skipped' => 1];
        }

        return $this->outbox->reconcile(self::BATCH_LIMIT);
    }
}

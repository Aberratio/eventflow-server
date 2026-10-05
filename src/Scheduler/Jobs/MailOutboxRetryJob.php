<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler\Jobs;

use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxService;
use ZmierzymyCzas\Scheduler\ScheduledJob;

/**
 * PP.19: dispatches outbox rows Central Mailer never accepted (deferred mass mails, failed and
 * stale in-flight rows) with backoff, a max-attempts limit and 429/Retry-After handling.
 * 50 rows per 5-minute tick stays well under Central Mailer's intake limit (60 POSTs/min).
 */
final class MailOutboxRetryJob implements ScheduledJob
{
    public const BATCH_LIMIT = 50;

    public function __construct(
        private readonly CentralMailerOutboxService $outbox,
        private readonly CentralMailerConfig $config,
    ) {
    }

    public function name(): string
    {
        return 'mail.outbox_retry';
    }

    public function run(\DateTimeImmutable $now): array
    {
        if (!$this->config->isConfigured()) {
            return ['skipped' => 1];
        }

        return $this->outbox->retryDue($now, self::BATCH_LIMIT);
    }
}

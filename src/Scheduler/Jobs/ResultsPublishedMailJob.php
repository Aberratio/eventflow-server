<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler\Jobs;

use ZmierzymyCzas\Domain\Mailer\ResultsPublication\ResultsPublicationNotifier;
use ZmierzymyCzas\Scheduler\ScheduledJob;

/**
 * Fans pending "results published" requests out to deferred outbox rows. It also runs when Central
 * Mailer is not configured, so pending requests are drained and nothing is blasted later when it gets
 * configured; the request backlog never grows.
 */
final class ResultsPublishedMailJob implements ScheduledJob
{
    public function __construct(private readonly ResultsPublicationNotifier $notifier)
    {
    }

    public function name(): string
    {
        return 'mail.results_published';
    }

    public function run(\DateTimeImmutable $now): array
    {
        return $this->notifier->run($now);
    }
}

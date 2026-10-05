<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler\Jobs;

use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Scheduler\ScheduledJob;

/** PP.18: E1.14 form snapshot for finished events, captured shortly after the finish (was "first read"). */
final class CaptureFormSnapshotsJob implements ScheduledJob
{
    public function __construct(private readonly RegistrationRepository $registrations)
    {
    }

    public function name(): string
    {
        return 'registrations.form_snapshots';
    }

    public function run(\DateTimeImmutable $now): array
    {
        return ['captured' => $this->registrations->captureMissingFormSnapshots()];
    }
}

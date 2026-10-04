<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler\Jobs;

use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Scheduler\ScheduledJob;

/** PP.18: pending -> expired for lapsed payment windows, with the money guards (see RegistrationRepository). */
final class ExpireUnpaidRegistrationsJob implements ScheduledJob
{
    public function __construct(private readonly RegistrationRepository $registrations)
    {
    }

    public function name(): string
    {
        return 'registrations.expire_unpaid';
    }

    public function run(\DateTimeImmutable $now): array
    {
        return $this->registrations->expireOverdueRegistrations($now);
    }
}

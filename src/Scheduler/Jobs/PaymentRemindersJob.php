<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler\Jobs;

use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Scheduler\ScheduledJob;

/** PP.18: mail #7 (unpaid reminder), once per payment window. Skipped when Central Mailer is not configured. */
final class PaymentRemindersJob implements ScheduledJob
{
    public function __construct(private readonly RegistrationRepository $registrations)
    {
    }

    public function name(): string
    {
        return 'registrations.payment_reminders';
    }

    public function run(\DateTimeImmutable $now): array
    {
        return $this->registrations->sendDuePaymentReminders($now);
    }
}

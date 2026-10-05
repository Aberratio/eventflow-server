<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler;

use PDO;
use Psr\Log\LoggerInterface;
use ZmierzymyCzas\Domain\Bz\BzWebhookClient;
use ZmierzymyCzas\Domain\Bz\BzWebhookConfig;
use ZmierzymyCzas\Domain\Bz\BzWebhookOutboxRepository;
use ZmierzymyCzas\Domain\Bz\BzWebhookOutboxService;
use ZmierzymyCzas\Domain\Bz\Http\CurlHttpClient as BzCurlHttpClient;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerClient;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxService;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\CriticalMailer;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailTemplateRepository;
use ZmierzymyCzas\Domain\Mailer\Http\CurlHttpClient as MailerCurlHttpClient;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Scheduler\Jobs\BzOutboxRetryJob;
use ZmierzymyCzas\Scheduler\Jobs\CaptureFormSnapshotsJob;
use ZmierzymyCzas\Scheduler\Jobs\ExpireUnpaidRegistrationsJob;
use ZmierzymyCzas\Scheduler\Jobs\FinishPastEventsJob;
use ZmierzymyCzas\Scheduler\Jobs\MailOutboxReconcileJob;
use ZmierzymyCzas\Scheduler\Jobs\PaymentRemindersJob;

/**
 * PP.18: the job registry and the single plug-in point for new jobs (PP.19 adds
 * 'mail.outbox_retry' after 'mail.outbox_reconcile'). Order matters: expiry runs before
 * reminders so an already-lapsed Zapis never gets a "you are about to expire" mail.
 */
final class SchedulerJobs
{
    public const NAMES = [
        'events.finish_past',
        'registrations.form_snapshots',
        'registrations.expire_unpaid',
        'registrations.payment_reminders',
        'bz.outbox_retry',
        'mail.outbox_reconcile',
    ];

    /** @return list<ScheduledJob> in NAMES order */
    public static function build(PDO $pdo, LoggerInterface $logger): array
    {
        // Mirrors the wiring in routes.php for exactly what the jobs need. Gateways are null:
        // no job starts a payment session.
        $mailerConfig = CentralMailerConfig::fromEnv();
        $mailerClient = new CentralMailerClient(new MailerCurlHttpClient(), $mailerConfig);
        $outbox = new CentralMailerOutboxService($mailerClient, new CentralMailerOutboxRepository($pdo));
        $mailer = new CriticalMailer($mailerConfig, $outbox, new MailTemplateRepository($pdo));
        $bzConfig = BzWebhookConfig::fromEnv();
        $bzOutbox = $bzConfig->isConfigured()
            ? new BzWebhookOutboxService(
                new BzWebhookClient(new BzCurlHttpClient(), $bzConfig),
                new BzWebhookOutboxRepository($pdo),
                $logger
            )
            : null;
        $registrations = new RegistrationRepository($pdo, null, null, $mailer, $bzOutbox, $logger);

        return [
            new FinishPastEventsJob(new EventRepository($pdo)),
            new CaptureFormSnapshotsJob($registrations),
            new ExpireUnpaidRegistrationsJob($registrations),
            new PaymentRemindersJob($registrations),
            new BzOutboxRetryJob($pdo, $bzOutbox, $bzConfig),
            new MailOutboxReconcileJob($outbox, $mailerConfig),
        ];
    }
}

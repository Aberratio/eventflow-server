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
use ZmierzymyCzas\Domain\Calendar\GoogleCalendarSyncService;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerClient;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxService;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\CriticalMailer;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\EventReplyToResolver;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailTemplateRepository;
use ZmierzymyCzas\Domain\Mailer\Http\CurlHttpClient as MailerCurlHttpClient;
use ZmierzymyCzas\Domain\Mailer\ResultsPublication\ResultsPublicationMailRequests;
use ZmierzymyCzas\Domain\Mailer\ResultsPublication\ResultsPublicationNotifier;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Scheduler\Jobs\BzOutboxRetryJob;
use ZmierzymyCzas\Scheduler\Jobs\CaptureFormSnapshotsJob;
use ZmierzymyCzas\Scheduler\Jobs\ExpireUnpaidRegistrationsJob;
use ZmierzymyCzas\Scheduler\Jobs\FinishPastEventsJob;
use ZmierzymyCzas\Scheduler\Jobs\GoogleCalendarOutboxRetryJob;
use ZmierzymyCzas\Scheduler\Jobs\MailOutboxReconcileJob;
use ZmierzymyCzas\Scheduler\Jobs\MailOutboxRetryJob;
use ZmierzymyCzas\Scheduler\Jobs\PaymentRemindersJob;
use ZmierzymyCzas\Scheduler\Jobs\ResultsPublishedMailJob;
use ZmierzymyCzas\Scheduler\Jobs\RetentionPurgeJob;
use ZmierzymyCzas\Domain\Admin\AdminRepository;
use ZmierzymyCzas\Domain\Retention\RetentionConfig;
use ZmierzymyCzas\Domain\Retention\RetentionRepository;
use ZmierzymyCzas\Support\Env;

/**
 * PP.18: the job registry and the single plug-in point for new jobs (PP.19 added
 * 'mail.results_published' and 'mail.outbox_retry' after 'mail.outbox_reconcile'). Order matters: expiry runs
 * before reminders so an already-lapsed Zapis never gets a "you are about to expire" mail.
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
        'mail.results_published',   // PP.19: fan-out first, so the new deferred rows go out in the same tick
        'mail.outbox_retry',        // PP.19: dispatch deferred / failed / stale rows (429-aware)
        'calendar.outbox_retry',    // R-17: send approved Events to Google Calendar (skipped while off / no key)
        'retention.purge',          // R-05: configurable retention (90 d / 12 mo default) of confidential answers + activity log
    ];

    /** @return list<ScheduledJob> in NAMES order */
    public static function build(PDO $pdo, LoggerInterface $logger): array
    {
        // Mirrors the wiring in routes.php for exactly what the jobs need. Gateways are null:
        // no job starts a payment session.
        $mailerConfig = CentralMailerConfig::fromEnv();
        $mailerClient = new CentralMailerClient(new MailerCurlHttpClient(), $mailerConfig);
        $outbox = new CentralMailerOutboxService($mailerClient, $outboxRepo = new CentralMailerOutboxRepository($pdo), $logger);
        $mailer = new CriticalMailer($mailerConfig, $outbox, new MailTemplateRepository($pdo), replyToResolver: new EventReplyToResolver($pdo));
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
            new ResultsPublishedMailJob(new ResultsPublicationNotifier(
                $pdo,
                new ResultsPublicationMailRequests($pdo),
                $mailer,
                $outboxRepo,
                rtrim((string) Env::get('APP_URL', ''), '/'),
                $logger
            )),
            new MailOutboxRetryJob($outbox, $mailerConfig),
            new GoogleCalendarOutboxRetryJob(GoogleCalendarSyncService::fromEnv($pdo, $logger)),
            new RetentionPurgeJob(new RetentionRepository($pdo), new AdminRepository($pdo), RetentionConfig::fromEnv($logger)),
        ];
    }
}

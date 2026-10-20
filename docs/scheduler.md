# Scheduler runbook (`bin/scheduler.php`)

PP.18. Time-based work no longer happens lazily inside GET requests. One CLI entrypoint runs
eight idempotent jobs, in this order, on every tick:

| # | Job | What it does | Limits |
|---|---|---|---|
| 1 | `events.finish_past` | Published -> Finished for events whose last day has passed | one UPDATE |
| 2 | `registrations.form_snapshots` | Freezes the E1.14 form snapshot for finished events (non-confidential superset, always served filtered) | 500 Zapisy / tick |
| 3 | `registrations.expire_unpaid` | `pending` -> `expired`, releases the Dystans slot | 500 / tick |
| 4 | `registrations.payment_reminders` | Mail #7, once per payment window | 200 / tick |
| 5 | `bz.outbox_retry` | Retries failed and stale-queued BZ pushes (skipped when `BZ_WEBHOOK_BASE_URL` is unset) | 100 / tick, 8 attempts, backoff 5 min .. 24 h |
| 6 | `mail.outbox_reconcile` | Polls Central Mailer for the status of accepted rows (skipped when not configured) | 50 / tick |
| 7 | `mail.results_published` | PP.19: fans pending "Publikacja wyników" requests out to deferred outbox rows, one per distinct confirmed e-mail of the event and distance (also runs when Central Mailer is not configured, so requests are drained) | 20 requests / tick |
| 8 | `mail.outbox_retry` | PP.19: dispatches outbox rows Central Mailer never accepted (deferred, failed, stale in-flight); skipped when not configured | 50 / tick, 6 attempts, backoff 2 / 10 / 30 / 120 / 360 min, max age 48 h |
| 9 | `calendar.outbox_retry` | R-17: sends approved Events to Google Calendar (skipped while the integration is off or `GOOGLE_CALENDAR_SERVICE_ACCOUNT_FILE` is missing) | 50 / tick, 8 attempts, backoff 5 min .. 10 h |

| 10 | `retention.purge` | R-05: deletes answers to confidential form fields once the Event ended more than `RETENTION_CONFIDENTIAL_ANSWERS_DAYS` days ago (default 90), and activity-log entries older than `RETENTION_ACTIVITY_LOG_MONTHS` months (default 12); an invalid value (not an integer > 0) falls back to the default with a log warning (reads no longer delete) | unbounded DELETEs, idempotent |

## Mail delivery (PP.19)

`CentralMailerOutboxService::enqueue()` writes the outbox row and tries to send it once. It no
longer throws when Central Mailer fails (5xx, network, 429, rejection): the row is durable and
`mail.outbox_retry` owns delivery, so a mailer outage cannot turn a registration, payment webhook
or moderation action into a 500. Database errors still throw.

| State | `status` | `next_attempt_at` (UTC `Y-m-d H:i:s`) | Meaning |
|---|---|---|---|
| deferred | `queued` | now | written without an HTTP call (mail #10), waits for job 8 |
| in flight | `queued` / `failed` | now + 10 min (lease) | an HTTP call is under way; a killed process leaves the row due again after the lease |
| retry wait | `failed` | now + backoff or `Retry-After` | transient failure or 429 |
| terminal | `failed` | `NULL` | permanent rejection, max attempts, or max age; never dispatched again |
| accepted | Central Mailer status | `NULL` | `central_mailer_email_id` set; job 6 reconciles it |

* Attempts: at most 6 POSTs per row; the wait after failed attempt N is 2, 10, 30, 120, 360 min.
  The Idempotency-Key, subject, HTML, priority, metadata (sorted keys) and attachments are resent
  unchanged, so Central Mailer replays instead of answering 409.
* HTTP 429: `Retry-After` is honoured (default 60 s, clamped to 60..3600 s), the attempt is not
  counted and the rest of the batch is skipped for that tick (counter `rateLimited: 1`).
* Permanent: 409, 422 (suppressed recipient), 400 and 413 are terminal at once. 422 logs a
  WARNING; 409, 400, 413, max attempts and max age log an ERROR with alert key `mail.outbox_abandoned`.
* A row created more than 48 h ago is abandoned instead of being sent.
* Technical-priority rows (password reset, verification, account setup) are dispatched before standard ones.
* Throughput: at most 50 POSTs per 5-minute tick, under Central Mailer's intake limit (60 per minute).
  Central Mailer's own sending budget (100 per 15 minutes) is the real bottleneck for mass mails;
  watch it with `CentralMailerOutboxService::progressForContext()`.
* Mail #10 (results published): publishing official results only writes one row to
  `results_publication_mail_requests` (O(1)). Job 7 queues one deferred row per distinct confirmed
  e-mail (case-insensitive) of the event and distance, including Zapisy without a result. Deduplicated per
  (event, e-mail): re-publishing, a second distance or a new template version never resends. A
  re-publish that flips no result to official (`updatedCount` 0) records no request.
* Mails #5 and #6 carry the start code / identity code as an inline PNG QR (`cid:`) plus the code as text.

Rows that failed before migration 066 (`next_attempt_at IS NULL`) are not retried automatically
(they may be stale reminders or expired links). Requeue one manually:

```sql
UPDATE central_mailer_outbox
SET attempts = 0, next_attempt_at = DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s')
WHERE id = '<id>' AND central_mailer_email_id IS NULL;
```

A row created more than 48 h ago is abandoned again on the next tick; for those, repeat the business
action instead (for example a new password reset).

Money guards of `registrations.expire_unpaid`: a Zapis is never expired when its start fee is
paid, when it has a `paid` or `amount_mismatch` gateway transaction, or (for 15 minutes past the
deadline) when it has an open `created` gateway transaction. All deadline comparisons are
offset-aware. A gateway "paid" notification for an already expired Zapis revives it when the
Dystans has a free slot; otherwise the Zapis stays `expired`, no confirmation mail is sent and an
ERROR with alert key `payments.paid_after_expiry` is logged (an admin decides: refund or manual
admission).

Reminder lead: 24 h before the deadline for bank transfer (`organizer_bank`), 15 min for a
gateway payment.

## Install the cron entry

Use the hosting panel's "Zadania cron" or `crontab -e` over SSH:

```
*/5 * * * * /usr/local/bin/php /ABSOLUTE/BACKEND_REMOTE_DIR/bin/scheduler.php >/dev/null 2>&1
```

Before installing, confirm the PHP binary (`command -v php`, `php -v`, >= 8.2) over SSH and use
the same binary `deploy.yml` uses for `bin/migrate.php`. `BACKEND_REMOTE_DIR` is the path from the
deploy secret. The script reads the `.env` there, so no environment variables are needed in the
crontab. Staging needs the cron too, otherwise nothing expires there.

Frequency: every 5 minutes is required. The gateway window defaults to 45 minutes with a
15-minute reminder lead. If the hosting allows only 15 minutes, gateway reminders will often be
missed; in that case raise `REMINDER_LEAD_GATEWAY_MINUTES` in `RegistrationRepository` to 20
(constant change only).

Behavior change: lists may show a lapsed Zapis as `pending` for up to 5 minutes. It holds no slot
against new registrants (`create()` expires overdue Zapisy of its own Dystans first).

## Verify

```sh
php bin/scheduler.php --list      # job names, no database access
php bin/scheduler.php             # one line per job, exit 0
php bin/scheduler.php --status    # last run of every job
php bin/scheduler.php --job=registrations.expire_unpaid
grep 'Scheduled job' storage/logs/app-$(date +%F).log
php -r 'echo date_default_timezone_get();'   # must match the web runtime (phpinfo)
```

Exit codes: 0 ok / skipped / another run holds the lock; 1 a job threw; 2 database unreachable or the jobs could not be built;
64 usage error. A timezone mismatch between CLI and web can only delay processing, never expire
or remind early.

## Alerts

* `scheduler.job_failed` (ERROR, context `job`): a job threw; the other jobs still ran.
* `payments.paid_after_expiry` (ERROR): money arrived for a Zapis that could not be confirmed.
* `mail.outbox_abandoned` (ERROR, context `outboxId`, `mailType`): an outbox row will never be delivered
  (max attempts, max age, or a permanent Central Mailer rejection). Never contains the address or content.

## Troubleshooting

* `Scheduler run skipped: another run holds the lock`: a previous run is still going (MySQL
  advisory lock, released automatically when its connection dies). Harmless if occasional.
* A job stuck on `running` in `--status`: its run was killed; the next run overwrites it.
* `Scheduler run log unavailable`: migration 065 was not applied; jobs still run.
* Run a single job: `php bin/scheduler.php --job=<name>`.
* Deploys are in place (`unzip -o`), so a tick can coincide with a deploy. Jobs are idempotent;
  the worst case is one failed-run alert (PP.21 owns release directories).

# Scheduler runbook (`bin/scheduler.php`)

PP.18. Time-based work no longer happens lazily inside GET requests. One CLI entrypoint runs
six idempotent jobs, in this order, on every tick:

| # | Job | What it does | Limits |
|---|---|---|---|
| 1 | `events.finish_past` | Published -> Finished for events whose last day has passed | one UPDATE |
| 2 | `registrations.form_snapshots` | Freezes the E1.14 form snapshot for finished events (non-confidential superset, always served filtered) | 500 Zapisy / tick |
| 3 | `registrations.expire_unpaid` | `pending` -> `expired`, releases the Dystans slot | 500 / tick |
| 4 | `registrations.payment_reminders` | Mail #7, once per payment window | 200 / tick |
| 5 | `bz.outbox_retry` | Retries failed and stale-queued BZ pushes (skipped when `BZ_WEBHOOK_BASE_URL` is unset) | 100 / tick, 8 attempts, backoff 5 min .. 24 h |
| 6 | `mail.outbox_reconcile` | Polls Central Mailer for the status of accepted rows (skipped when not configured) | 50 / tick |

PP.19 plugs `mail.outbox_retry` (re-sending failed mail rows, Central Mailer 429 handling) into
`SchedulerJobs` after job 6. Not done here on purpose.

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

Exit codes: 0 ok / skipped / another run holds the lock; 1 a job threw; 2 database unreachable;
64 usage error. A timezone mismatch between CLI and web can only delay processing, never expire
or remind early.

## Alerts

* `scheduler.job_failed` (ERROR, context `job`): a job threw; the other jobs still ran.
* `payments.paid_after_expiry` (ERROR): money arrived for a Zapis that could not be confirmed.

## Troubleshooting

* `Scheduler run skipped: another run holds the lock`: a previous run is still going (MySQL
  advisory lock, released automatically when its connection dies). Harmless if occasional.
* A job stuck on `running` in `--status`: its run was killed; the next run overwrites it.
* `Scheduler run log unavailable`: migration 065 was not applied; jobs still run.
* Run a single job: `php bin/scheduler.php --job=<name>`.
* Deploys are in place (`unzip -o`), so a tick can coincide with a deploy. Jobs are idempotent;
  the worst case is one failed-run alert (PP.21 owns release directories).

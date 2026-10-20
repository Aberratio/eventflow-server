# Zmierzymy Czas Server

PHP 8.2 REST API for the React/Vite frontend. The API is intentionally shaped to match the frontend `ApiAdapter` contract.

## Stack

- Slim 4
- PDO + MySQL
- JWT bearer auth
- PHPUnit

## Local Setup

```sh
composer install
```
```sh
copy .env.example .env
```

Set `JWT_SECRET` in `.env` — it has no default and the API refuses to boot without
it (see "Configuration and boot guard" below):

```sh
php -r "echo bin2hex(random_bytes(32));"
```

```sh
composer migrate
```
```sh
composer seed
```
```sh
composer serve
```

The API runs at `http://localhost:8081/api/v1`.

`composer serve` is a foreground development server. Keep that terminal open while
using the frontend. If the terminal is closed or the process is stopped, browser
requests to the API will fail with `connection refused`.

The `serve` script disables Composer's default 300-second process timeout. If you
start the server manually instead, run `php -S localhost:8081 -t public` from this
directory.

`composer migrate` updates the existing MySQL schema. Run it after pulling backend
changes, especially when the API starts returning 500 errors after startup.

`composer seed` refreshes the demo data: it removes whatever the previous seed run
inserted, applies migrations, and loads a fresh set. Anything you created yourself is
left alone, so it is safe to re-run. `composer seed:fresh` is the old behaviour — drop
every table, migrate, seed — and only works when `APP_ENV=local`.

### Sanity checks
Quick sanity checks after starting the server:

```sh
curl http://localhost:8081/api/v1/health
```
```sh
curl http://localhost:8081/api/v1/events
```

If `/health` works but `/events` returns 500 with an SQL error such as
`Unknown column ...`, the database schema is stale. Run:

```sh
composer migrate
```

If the database is missing demo data or the schema is badly out of sync, reset it
with:

```sh
composer seed
```

## Demo data

Demo data lives in `database/seeders/`, never in `database/migrations/`. Migrations
carry schema only, so a production deploy cannot bring test accounts or fixture events
along with it.

| Command | What it does | Where |
| --- | --- | --- |
| `composer seed` | purge previous demo rows, migrate, load a fresh set | local, dev, staging |
| `composer seed:purge` | remove demo rows and stop | local, dev, staging |
| `composer seed:fresh` | drop all tables, migrate, seed | local only |
| `composer prune-demo` | report demo rows found; `php bin/prune-demo.php --force` deletes them | any, production included |
| `composer migrate` | schema only; on `APP_ENV=production` it also removes demo rows | any |

`bin/seed.php` refuses to run when `APP_ENV=production`, and `--fresh` refuses anywhere
but `local`. Production deploys run `composer migrate` and nothing else.

**One-time step:** a database seeded before `seed_records` existed holds demo rows the
purge cannot recognise, so the first `composer seed` there stops with a duplicate-key
message. Run `composer seed:fresh` once locally; every run after that is tracked.

### How the cleanup knows what to delete

The seed runner snapshots every table's primary keys before and after applying the
seeders, and records the difference in a `seed_records` table. Purging deletes exactly
those rows — which is what makes a seed refresh on staging safe next to real data. The
`/dev/seed-event` endpoint records what it creates the same way.

### Where the demo events come from

A seed run loads three sets, in this order:

1. **`001_demo.sql`** — the hand-written base: the canonical logins, a small catalogue of
   events, plus the domains that hang off nothing else (shop, challenges, CMS, partners).
2. **`generate_events.php`** — bulk volume: three events with 160 participants,
   registrations and results, generated at seed time into `database/seeders/.generated/`
   (git-ignored). A fixed RNG seed means every machine gets the same data; `--seed=N`
   produces a different set.
3. **`data/events.json`** — 49 events with their distances, price tiers, VIP prices,
   merchandise, discount codes, gallery albums, registrations, results and virtual-result
   submissions. This is the set that covers awkward states on purpose: bank-transfer vs
   payment-gateway checkout, private events with access codes, an event with no gallery,
   one with no results, drafts awaiting moderation, relay distances.

The third set used to live in the frontend as `src/api/mock/*.mock.ts` — roughly 9,000
lines of TypeScript literals that only a mock API adapter could read, so nothing about
those events existed behind the API. They are database rows now, and the app reads them
over HTTP like any other event.

`EventFixtureImporter` loads the JSON through `EventRepository`, the same write path the
API uses, so a fixture cannot describe an event the API could not have produced. Two
conflicts are reconciled while loading, both commented in the importer: eight people
appear in both `001_demo.sql` and the JSON under different ids and are merged onto the
SQL ones, and three fixture events reuse a slug `001_demo.sql` already claimed, so those
get a suffixed slug rather than being dropped.

To change the fixtures, edit `database/seeders/data/events.json` and re-seed.

### The calendar is rebuilt around the day you seed

Fixture dates used to be absolute, which rots: a set written in early 2026 was almost
entirely in the past by that autumn, leaving the calendar with nothing upcoming to show,
and twelve events were marked `active` while dated before the newest `past` one. Three
passes now run on every seed:

- **`FixtureCalendar`** treats the authored *status* as the intent and recomputes the date
  to match, spreading events from about a year before the seed date to a year after —
  seeded on 30 August 2026 that is **August 2025 to August 2027**. Order within each group
  is preserved, every date belonging to an event (price tiers, discount windows, entry
  timestamps) moves with it, and a year in the event's name moves too, so "Maraton
  Warszawski 2025" does not end up dated 2026.
- **`FixtureFieldFiller`** gives each event the surroundings its position implies: finished
  events get a full field, results and a gallery; upcoming ones are mostly open with a
  partly filled field, with one in three not yet open for entries; drafts stay bare.
  Generation is seeded from the event id, so a re-run produces the same runners.
- **`EventCoherence`** then reconciles the whole `events` table, including the rows written
  straight to the database by `001_demo.sql` and the generator, whose hard-coded dates age
  past. The date is treated as the fact: status, registration state, results and galleries
  are made to agree with it.

The result is that `composer seed` always produces a calendar with a year of history to
browse and a year of racing still to enter, whenever it is run.

### Shop and series

`data/shop.json` holds 16 products, 3 orders and their items, loaded by
`ShopFixtureImporter` on top of the five products `001_demo.sql` creates. It exists to
cover what those five could not: footwear and electronics categories, a product that is
out of stock, six with a struck-through original price, and orders so the order history
and the admin order screens have something in them. Products are matched on both id and
slug before inserting, so it never collides with the SQL seeder or duplicates a product.

`SeriesLinker` then gives every series four events. The seeders leave most series with one
event or none, which makes a standings table meaningless. Events are assigned after the
calendar is settled — which events exist is itself decided at seed time — preferring the
discipline a series already leans towards, and always mixing finished rounds with upcoming
ones so a series shows both results and a next date. Existing links are never disturbed.

### Logins

Demo users seeded by `composer seed` on a **local** database (`APP_ENV=local`) all use password `demo1234`.
On any other environment (staging) `bin/seed.php` scrubs those passwords right after seeding, so the accounts
exist but nobody can sign in to them (use "Nie pamiętam hasła" on a real account instead). To keep the demo
logins usable on dev/staging, set `SEED_DEMO_PASSWORD` in that server's `.env` (at least 8 characters, not
`demo123`/`demo1234`): the seed then gives every seeded account that password instead of scrubbing it. A rejected
value scrubs the accounts and fails the seed. A later `bin/scrub-demo-passwords.php` run removes it again.

- `jan@example.com` participant
- `marek@timing.pl` organizer
- `monika@zmierzymyczas.pl` moderator
- `admin@zmierzymyczas.pl` admin
- `superadmin@zmierzymyczas.pl` superadmin

The five `test.*@zmierzymyczas.pl` accounts and every generated `@demo.test`
participant use the same password (local only).

To remove the known demo passwords from an existing staging or production database run, from the
server directory with that environment's `.env`:

```
php bin/scrub-demo-passwords.php --dry-run   # counts the accounts, writes nothing
php bin/scrub-demo-passwords.php --deep      # also checks every account against demo123/demo1234 (slow, bcrypt per row)
```

It refuses to run when `APP_ENV=local`, prints only counts and ends the sessions of the scrubbed accounts.
Keep (or create) a real staff account first: the demo `admin@`/`superadmin@zmierzymyczas.pl` logins stop working.

### Generating one more event while clicking through the app

`POST /api/v1/dev/seed-event` builds a single event with participants, registrations
and results. The DevToolbox in the frontend calls it. It is available when
`APP_ENV=local` (no auth needed, for expired dev tokens; accounts it creates use `demo123`) and when
`APP_ENV=dev` or `staging` (a live organizer/moderator/admin/superadmin session is required; accounts it creates
get no usable password). Disabled everywhere else.

## Configuration and boot guard

`public/index.php` validates the environment through `ZmierzymyCzas\Config\AppConfig`
before it does anything else — before wiring routes, before opening a DB connection.
If the configuration is invalid, the app never boots: every request gets `503` with
`{"error":{"code":"service_misconfigured", ...}}` (a generic Polish message; no
variable names or secret values are ever in the response body), and the actual
reason is written to `error_log()` (printed to the console under `php -S`, written to
LiteSpeed's error log in production).

Run `php bin/check-config.php` any time to validate a `.env` without starting the
server — it prints `Configuration OK (...)` and exits 0, or `Configuration INVALID:`
with one `- <reason>` line per problem and exits 1. It never prints the secret. Use it
over SSH to check a server's `.env` before merging or deploying.

Rules (see `src/Config/AppConfig.php` for the exact messages):

| Variable | Rule |
|---|---|
| `APP_ENV` | One of `local`, `dev`, `staging`, `production`. `dev` is a hosted test instance and follows exactly the `staging` rules everywhere below. Unset defaults to `production` (the strictest rules); an unrecognized value refuses to boot. |
| `JWT_SECRET` | Required in **every** environment, at least 32 bytes. Outside local it must also not be a `change-me...` placeholder. No fallback exists — this was previously a literal in the repo, which is a critical vulnerability. |
| `APP_CORS_ORIGIN` | Comma-separated allowlist of exact front-end origins (`scheme://host[:port]`, no path, no trailing slash). Required outside local, where `*` is forbidden and production additionally requires `https://`. Unset in local falls back to the standard dev origins. `Access-Control-Allow-Credentials` is never sent — the client authenticates with a Bearer token only. |
| `APP_DEBUG` | Boolean. Refused (boot fails) when `true` and `APP_ENV=production`. |
| `TPAY_SANDBOX` / `PAYNOW_SANDBOX` | Boolean, default `true` (unset means sandbox — fail-closed toward *not* moving real money by accident, but this means unset also blocks production boot). Must be explicitly `false` when `APP_ENV=production`. |

Per-environment required variables (also in `.env.example`):

| Variable | Dev / Staging | Production |
|---|---|---|
| `APP_ENV` | `dev` or `staging` (exact) | `production` (exact) |
| `JWT_SECRET` | random, 32+ bytes, different from production | random, 32+ bytes, never reused from staging/repo |
| `APP_CORS_ORIGIN` | explicit origin(s), no `*` | explicit **https** origin(s), no `*`, no http |
| `APP_DEBUG` | `false` recommended (not enforced) | `false` or unset — `true` refuses to boot |
| `TPAY_SANDBOX` / `PAYNOW_SANDBOX` | `true` or `false` | explicit `false` — unset refuses to boot |

Outside debug mode, `JsonErrorMiddleware` also hides the message of any exception
that is not a Slim 4xx `HttpException` behind a generic Polish `server_error`
response — no SQL errors, stack traces, or internal details reach the client. Debug
mode (`APP_DEBUG=true`) keeps the exception message and a stack trace, which is why
it is refused in production.

## Logging, alerts and /health

Every 5xx, every payment webhook delivery, gateway refunds/sessions, amount mismatches,
BZ sync calls and boot failures are logged through a PSR-3 logger (Monolog 3). Records are
PII-free by construction: call sites log ids, counts and field keys only, and a redaction
layer (`src/Support/Log/PiiRedactor.php`) additionally masks e-mails, JWT/Bearer tokens,
phone numbers, PESEL, SQL duplicate-entry literals and any context key such as `email`,
`payload`, `headers`, `fields`, `token`, `signature`. Exceptions are logged as class,
scrubbed message and an argument-free trace. Residual risk: free-text names echoed by a
third-party exception message cannot be detected, so keep the log directory private.

| Key | Default | Meaning |
|---|---|---|
| `LOG_DIR` | `storage/logs` next to `public/` (staging/production); unset in `APP_ENV=local` = PHP `error_log()` only | Non-public directory for the log files. Not writable: falls back to `error_log()`. |
| `LOG_LEVEL` | `info` | `debug`, `info`, `notice`, `warning` or `error`. |
| `LOG_MAX_FILES` | `30` | Days of daily files kept (1-365). |
| `ALERT_EMAIL` | unset = alerts off | Recipient of alert e-mails. |
| `ALERT_THROTTLE_SECONDS` | `900` | At most one alert mail per alert key per window (60-86400). |

Invalid values fall back to the default and add one WARNING record naming the key.

Files are JSON lines named `app-YYYY-MM-DD.log` (mode `0600`), rotated daily by Monolog.
Read them over SSH, for example:

```sh
tail -n 50 storage/logs/app-$(date +%F).log
grep '"level_name":"ERROR"' storage/logs/app-$(date +%F).log
grep 'a1b2c3d4e5f60718' storage/logs/app-*.log   # a request id from an X-Request-Id header
```

Every 5xx response carries an `X-Request-Id` header whose value is also in the log record
(`context.requestId`). Uncaught exceptions and PHP fatals outside Slim are logged at
CRITICAL; PHP warnings keep their current behaviour.

Alerts: records at ERROR or above that carry an alert key are e-mailed through the Central
Mailer (`priority: technical`) when `ALERT_EMAIL` is set, Central Mailer is configured and
the log directory is writable. Alert keys: `payments.amount_mismatch`,
`payments.registration_mismatch`, `payments.refund_failed`, `payments.session_failed`, `payments.webhook_error`,
`payments.paid_after_expiry`, `scheduler.job_failed`, `mail.outbox_abandoned`, `http.server_error`,
`db.unavailable`, `boot.misconfigured`. Each key is throttled
(`ALERT_THROTTLE_SECONDS`); the next mail after a window reports how many were suppressed.
Alerts do not use the database or the mail outbox, so they still work when the DB is down.

`GET /api/v1/health` is public and runs `SELECT 1` on the application connection. 200 means
`{"success":true,"data":{"status":"ok","checks":{"database":"ok"},"version":"2026.10.0","commit":"565a6bb"}}`.
`version` is the release tag the code was deployed from and `commit` the deployed commit, both
read from the `.zmierzymy-czas-release` marker; either is `null` when unknown (a local checkout,
or `version` for a deploy that is not a release). `APP_VERSION` in `.env` overrides `version`
and should normally stay empty. When the database is
unreachable it answers 503 `service_unavailable` with a generic message and no details
(and logs an ERROR with alert key `db.unavailable`). If the connection cannot be opened at
boot, every request gets the same 503 (the PDO connect timeout is 5 s).

## Scheduler (cron)

Registration expiry, payment reminders, form snapshots, BZ push retries and mail status
reconciliation run from `bin/scheduler.php`, not from GET requests. It must be installed as a
cron job on every environment (staging too), every 5 minutes, or nothing expires and no
reminders go out:

```
*/5 * * * * /usr/local/bin/php /ABSOLUTE/BACKEND_REMOTE_DIR/bin/scheduler.php >/dev/null 2>&1
```

Runbook (jobs, verification, troubleshooting): [docs/scheduler.md](docs/scheduler.md).

## Security headers

Every API response — success, 4xx/5xx from `JsonErrorMiddleware`, CORS preflight, and
the `/uploads/*` PHP route — carries a fixed set of security headers, added outermost
by `ZmierzymyCzas\Http\Middleware\SecurityHeadersMiddleware`:

| Header | Value |
|---|---|
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'` — the API never renders documents. |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` (kept for legacy browsers alongside `frame-ancestors 'none'`) |
| `Referrer-Policy` | `no-referrer` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=(), usb=()` |
| `Strict-Transport-Security` | `max-age=31536000`, on every `APP_ENV` except `local`. No `includeSubDomains`/`preload` yet — the production domain is not finalized. |

Headers are set only if the response does not already carry them (`withHeader` after a
`hasHeader` check), so a future route can opt into its own policy without a conflict.
No route does this today.

The registration order lives in `ZmierzymyCzas\Http\HttpMiddlewareStack::register()`,
called once from `public/index.php`. Slim runs middleware LIFO (the last one added is
the outermost), so `SecurityHeadersMiddleware` is added last — after routing, body
parsing, `JsonErrorMiddleware` and `CorsMiddleware` — so it also covers CORS preflight
responses (answered by `CorsMiddleware` without calling the handler) and every error
response rendered by `JsonErrorMiddleware`.

The boot-failure branch in `public/index.php` (invalid configuration, `503`) emits the
same baseline headers via `SecurityHeadersMiddleware::baselineHeaders(false)` — always
without HSTS, since `APP_ENV` could not be parsed at that point.

Out of scope for now (tracked in later tickets): a dynamic per-event
`frame-ancestors`/`X-Robots-Tag` (PP.28), upload MIME hardening beyond `nosniff`
(PP.11), and COOP/COEP/CORP/`report-uri` (not planned — CORP in particular would break
cross-origin `<img>` loads of API uploads from the frontend).

## Deployment and operations

Operational procedures live in [`docs/RUNBOOK.md`](docs/RUNBOOK.md): deploy, rollback (code and
database), backups and the restore test, secrets and the owner checklist. In short: a push to
`master` deploys to staging; production is `workflow_dispatch` only, from `master`, with a typed
confirmation. Code rollback is the "Roll back API code" workflow. Database backups are taken by
hosting cron (`scripts/ops/db-backup.sh`), not by GitHub Actions.

## Frontend

Set the client env variable:

```sh
VITE_API_URL=http://localhost:8081/api/v1
```

The React app uses the real HTTP adapter by default and falls back to
`http://localhost:8081/api/v1` when `VITE_API_URL` is not set. Override
`VITE_API_URL` only when the API is available at a different address.

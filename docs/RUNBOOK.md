# Runbook: deploy, rollback, backups

Audience: the owner and whoever is on call. Technical, English. Commands are copy-paste ready;
replace `<...>` placeholders. Nothing here needs more than SSH access to the host and the GitHub UI or `gh`.

## 1. Overview

| Item | Value |
|---|---|
| API repo | `Aberratio/eventflow-server` (this repo), default branch `master` |
| Frontend repo | `Aberratio/eventflow-platform`, default branch `main` |
| Environments | `staging`, `production` (GitHub Environments of the same names) |
| Host | shared hosting, SSH + `scp`; API unpacked in place into `BACKEND_REMOTE_DIR` |
| Staging | deploys **automatically** on every push to `master`/`main` |
| Production | **`workflow_dispatch` only**, typed confirmation, from `master` (or a `rollback-*` tag) |

Safety layers, in order: a `guard` job (no secrets, no environment) validates inputs, then
`Check deploy configuration` fails fast if a secret is missing, then the test gate, then the
remote deploy. A push can never reach production; `scripts/ci/deploy-guard.sh` refuses it (exit 61).

## 2. Deploy (API)

**Staging:** merge or push to `master`. Watch Actions, workflow "Deploy API".

**Production:** Actions, "Deploy API", "Run workflow", branch `master`, `deploy_target=production`,
`migration_mode=run`, `confirm_production=DEPLOY_PRODUCTION`. With the CLI:

```sh
gh workflow run deploy.yml -R Aberratio/eventflow-server --ref master \
  -f deploy_target=production -f migration_mode=run -f confirm_production=DEPLOY_PRODUCTION
```

Phases of one deploy:

1. `guard`: input validation (exit 60-66).
2. `Check deploy configuration`: names missing secrets, exit 30. Nothing was deployed.
3. Tests (lint, migrate, seed, Unit, Integration) against MySQL 8.4.
4. Build the artifact (`--no-dev`, release marker `.zmierzymy-czas-release`).
5. `preflight` on the host: paths, `.env`, `APP_ENV` equals the target, DB settings, tools. Fails before upload.
6. Upload the zip, then `install` on the host:
   1. database dump `predeploy-<env>-<ts>.sql.gz` in `BACKEND_BACKUP_DIR` (newest 10 kept);
   2. **code snapshot** `code/code-<ts>-<previous release id>.tar.gz` (newest 5 kept; no `.env*`, uploads, `storage/`, `.deploy/`);
   3. `unzip -o`;
   4. `php bin/check-config.php`; on failure the snapshot is restored (exit 28);
   5. `php bin/migrate.php` (unless `migration_mode=skip`); on failure the snapshot is restored (exit 22).
7. Health check: `curl --fail <API_URL>/health`.

The database is never restored automatically.

Exit codes (workflow and scripts):

| Code | Meaning |
|---|---|
| 10, 11, 12 | `BACKEND_REMOTE_DIR` / `BACKEND_ALLOWED_ROOT` / `BACKEND_BACKUP_DIR` empty |
| 13, 14, 16 | remote dir is unsafe (`/`, `.`, `$HOME`), contains `..`, or resolves to an unsafe path |
| 15, 27 | backup dir / allowed root is not absolute |
| 17 | remote dir outside the allowed root |
| 18, 19 | deploy marker `.zmierzymy-czas-api-root` / `.env` missing on the host |
| 20 | DB settings incomplete in the host `.env` |
| 21 | pre-deploy dump failed or is invalid (nothing deployed) |
| 22 | migrations failed; previous code restored; DB may be partially migrated |
| 23 | unsupported `MIGRATION_MODE` |
| 24 | backup dir is inside the remote dir |
| 25 | `APP_ENV` in the host `.env` differs from the deploy target |
| 26 | empty DB password for production |
| 28 | `bin/check-config.php` refused the new release; previous code restored |
| 29 | code snapshot failed; nothing was changed |
| 30, 31, 32, 34 | missing secret / `SSH_HOST` not a bare hostname / `SSH_PORT` not numeric / empty known_hosts |
| 35 | `LOG_DIR` inside a public directory |
| 36 | required command missing on the host (name is printed) |
| 37 | uploaded zip missing on the host |
| 38 | unzip failed; previous code restored |
| 39 | rollback done but `check-config.php` fails afterwards |
| 40, 41 | rollback snapshot not found / invalid snapshot name |
| 42 | snapshot is corrupt or could not be extracted (manual intervention) |
| 50 | production deploy without the confirmation (defence in depth) |
| 60-66 | guard: bad operation or target (60), trigger (61), confirmation (62), ref (63), skipTests (64), migration mode (65), snapshot name (66) |

## 3. Deploy (frontend)

Repo `eventflow-platform`, workflow "Deploy frontend". Same model: a push to `main` deploys to
staging; production only by `workflow_dispatch` with `confirm_production=DEPLOY_PRODUCTION`
from `main` (or a `rollback-*` tag). Production can never skip tests. Transport is FTP or SSH,
depending on the secrets set. (The client-side guard lands after PP.15; until then the client
workflow has no production environment or secrets, so a production run fails at the build step.)

## 4. Rollback: API code

Actions, "Roll back API code", "Run workflow" on `master`:

1. `snapshot=list` first. It lists `code-*` snapshots newest first and changes nothing.
2. Then `snapshot=latest` or a name from the list. Production needs `confirm_production=ROLLBACK_PRODUCTION`.

```sh
gh workflow run rollback-api.yml -R Aberratio/eventflow-server --ref master \
  -f deploy_target=production -f snapshot=latest -f confirm_production=ROLLBACK_PRODUCTION
```

It takes a `prerollback-*` snapshot of the current state, restores the chosen snapshot, runs
`check-config.php`, then a health check. It runs **no migrations, no tests, and never touches the database.**

**Undo a rollback:** SSH to the host and restore the `prerollback-*` snapshot named in the output:

```sh
tar -xzf <BACKUP_DIR>/code/prerollback-<ts>-<id>.tar.gz -C <API_DIR>
php <API_DIR>/bin/check-config.php
```

Or redeploy the bad commit. `prerollback-*` files never match `latest`, so there is no ping-pong.

Known residue (harmless): `unzip -o` has a short window with mixed files; files deleted in a newer
release stay on disk; a rollback leaves files that the bad release added. The optimised classmap
comes from the release, so stray files are never autoloaded.

## 5. Rollback: frontend

1. `git tag rollback-YYYYMMDD-<sha7> <good sha> && git push origin <tag>`
2. `gh workflow run deploy.yml -R Aberratio/eventflow-platform --ref rollback-... -f deploy_target=production -f confirm_production=DEPLOY_PRODUCTION`
3. Delete the tag afterwards.

The same tag route works for the API as a full redeploy of older code (section 4 is the faster
snapshot route). Older code finds every migration it knows already in `schema_migrations`, so
`migrate.php` is a no-op.

## 6. Migrations are forward-only

There is no `down()`. A fix is a new forward migration. Migrations must be backward compatible
with the previous release (expand, then contract across two releases), because a code rollback
does not undo schema changes. Portability rules (MySQL 8.4, `information_schema` guards, no
`ADD COLUMN IF NOT EXISTS`) are in `TESTING.md`.

## 7. Database rollback

A human decision: it loses every write since the dump. Use it only when a migration damaged data.

1. Announce downtime.
2. Roll back the code first (section 4).
3. Pick the dump: `predeploy-<env>-<ts>.sql.gz` (state right before that deploy; preferred) or the
   latest `daily-<env>-<ts>.sql.gz` in `<BACKUP_DIR>/daily` (a day of writes may be lost).
4. Verify it: `gunzip -t <dump>` and, for daily dumps, `sha256sum -c <dump>.sha256`.
5. Restore with a temporary client config (never put the password on the command line):

   ```sh
   CNF="$(mktemp)"; chmod 600 "$CNF"
   printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' '<host>' '<port>' '<user>' '<password>' > "$CNF"
   gunzip -c <dump> | mysql --defaults-extra-file="$CNF" <database>
   rm -f "$CNF"
   ```
6. `php <API_DIR>/bin/migrate.php` must report nothing pending for the restored code.
7. Health check: `curl --fail <API_URL>/health`.

## 8. Backups and restore test

Run by **hosting cron**, not GitHub Actions: DB credentials stay on the host, dumps (which contain
personal data, including data of minors) never leave it, and backups keep running if GitHub or the
deploy key is unavailable. Times are hosting local time (presumably Europe/Warsaw; confirm). Set
`MAILTO`: the trailing `|| echo` prints only on failure, so mail arrives only when something broke.

```
30 2 * * *  bash <API_DIR>/scripts/ops/db-backup.sh --env-file <API_DIR>/.env --backup-dir <BACKUP_DIR>/daily >> <BACKUP_DIR>/db-backup.log 2>&1 || echo "ZC db-backup FAILED, see <BACKUP_DIR>/db-backup.log"
15 4 * * 0  bash <API_DIR>/scripts/ops/db-restore-check.sh --backup-dir <BACKUP_DIR>/daily --restore-env-file <BACKUP_DIR>/restore-check.env >> <BACKUP_DIR>/db-restore-check.log 2>&1 || echo "ZC restore check FAILED, see <BACKUP_DIR>/db-restore-check.log"
```

`<BACKUP_DIR>` is the `BACKEND_BACKUP_DIR` directory (outside the API dir). Each backup is three
files (mode 600): `daily-<env>-<ts>.sql.gz`, `.sql.gz.sha256`, `.manifest` (database, host, table
count, `schema_migrations` count, UTC time). Retention: 14 daily, plus 8 weekly copies made on Sundays.
Healthy log line: `Backup OK: <file> (<size>, tables=<n>, migrations=<m>)`.

Restore test: the restore env file is created by the owner (chmod 600) with distinct keys
`RESTORE_DB_HOST RESTORE_DB_PORT RESTORE_DB_DATABASE RESTORE_DB_USERNAME RESTORE_DB_PASSWORD`.
The scratch database name must contain `restore`; the user needs privileges on that database only.
Manual run: `bash <API_DIR>/scripts/ops/db-restore-check.sh --backup-dir <BACKUP_DIR>/daily --restore-env-file <BACKUP_DIR>/restore-check.env`.
It always wipes the scratch database afterwards. CI runs the same scripts against MySQL 8.4 (job
"Backup and restore scripts (MySQL 8.4)").

| Code | Meaning |
|---|---|
| 70 | scratch DB name does not contain `restore` |
| 71 | manifest says the dump came from the restore target |
| 72 | dump invalid (gzip, missing `-- Dump completed` trailer, missing manifest) |
| 73 | newest dump older than `--max-age-hours` (default 26): backups have stopped |
| 74 | dump contains `USE` / `CREATE DATABASE` |
| 75 | restore failed |
| 76, 77 | restored table count / `schema_migrations` count differs from the manifest |
| 78 | no `daily-*.sql.gz` found |
| 79 | sha256 mismatch or sidecar missing |
| 80 | bad usage or config (both scripts) |
| 81 | `mysqldump` failed (backup) |
| 82 | fresh dump failed the integrity check (backup) |
| 83 | another backup is running (backup) |

Disk: a code snapshot is roughly 20-60 MB times 5; dumps grow with data. Watch the hosting quota.

## 9. Secrets

GitHub Environment secrets (set per environment; use separate SSH keys for staging and production):

| Name | Used by | Format |
|---|---|---|
| `SSH_HOST` | deploy, rollback | bare hostname, no scheme or slash |
| `SSH_PORT` | deploy, rollback | digits |
| `SSH_USER` | deploy, rollback | login name |
| `SSH_PRIVATE_KEY` | deploy, rollback | ed25519 private key, full OpenSSH text |
| `SSH_KNOWN_HOSTS` | deploy, rollback | output of `ssh-keyscan -p <port> <host>`, fingerprint verified |
| `BACKEND_REMOTE_DIR` | deploy, rollback | absolute path of the API dir (holds `.env`, marker `.zmierzymy-czas-api-root`) |
| `BACKEND_ALLOWED_ROOT` | deploy, rollback | absolute path that must contain the API dir |
| `BACKEND_BACKUP_DIR` | deploy, rollback | absolute path outside the API dir |
| `API_URL` | health check | API base URL without trailing slash, so that `<API_URL>/health` resolves |

Server `.env` keys that matter for deploy: `APP_ENV` (must equal the target), `DB_*`, `JWT_SECRET`,
`LOG_DIR`, `APP_UPLOAD_DIR`, `CENTRAL_MAILER_*`. See README, "Configuration and boot guard".

## 10. Secret rotation

- **SSH deploy key:** generate ed25519, add the public key to the host `authorized_keys`, update the
  environment secret, run a staging deploy, remove the old key.
- **`SSH_KNOWN_HOSTS`:** `ssh-keyscan -p <port> <host>`; verify the fingerprint out of band.
- **`JWT_SECRET`:** change in `.env`; every user is logged out.
- **DB password:** hosting panel, then `.env`, then `php bin/check-config.php`.
- **Tpay / PayNow keys:** provider panel, then `.env`, then check-config.
- **`CENTRAL_MAILER_API_KEY`:** insert the new key, switch `.env`, then deactivate the old row (`active=0`).
- **`INCOMING_API_KEYS` (Biuro Zawodow):** coordinate an overlap window with the other app.
- **FTP password (frontend):** hosting panel, then the environment secret.

## 11. Central Mailer key for ZC production

1. Generate a key of at least 32 characters: `openssl rand -base64 48 | tr -d '\n/+=' | cut -c1-48`.
2. In the Central Mailer production DB:

   ```sql
   INSERT INTO email_clients (source_app, api_key_hash, active, queue_weight, rate_limit_count,
     rate_limit_window_minutes, created_at, updated_at)
   VALUES ('zmierzymy_czas', SHA2('<key>', 256), 1, 1, <limit>, 15, NOW(), NOW());
   ```
3. In the ZC production `.env`: `CENTRAL_MAILER_API_KEY=<key>` and `CENTRAL_MAILER_BASE_URL=https://<mailer host>` (no trailing slash).
4. Run `php bin/check-config.php`.
5. Keep the plaintext key out of chat, tickets and git.

## 12. Owner checklist

State read on 2026-10-04:

- Branch protection and rulesets return 403 ("Upgrade to GitHub Pro or make this repository
  public") for both private repos: **none exists**.
- Only the `staging` environment exists (no protection rules, no deployment-branch policy). **There is no `production` environment.**
- No secrets are set (repo level or `staging`) in either repo.
- Dependabot alerts are off; automated security fixes are disabled.
- The latest "Deploy API" run fails, as expected without secrets; "Deploy frontend" fails at
  "Build staging" (API_URL missing).

Open decisions: Q1 GitHub plan (recommended: GitHub Pro, which unlocks protection, environment
secrets and branch policies for private repos); Q2 off-host copy of backups (decide after O9; if the
provider keeps under 7 days or not off-site, open a ticket for an encrypted push to external
storage); Q3 release dirs plus symlink (not before launch).

- **O1.** Decide on the GitHub plan (Q1).
- **O2.** After O1, branch protection on `master` (server) and `main` (client): require a PR; require
  the checks `Backend unit gate`, `Backend integration gate`, `Workflow and script checks` and
  `Backup and restore scripts (MySQL 8.4)` (server), `Frontend fast gate` and `Playwright smoke`
  (client); block force-push and deletion. "Include administrators" is optional; leave it off
  at first and turn it on after launch.
- **O3.** Create environment `production` in both repos with deployment branches "Selected branches and
  tags": `master`/`main` plus `rollback-*`. Required reviewers and wait timers in private repos may need
  Enterprise; verify in Settings. The typed confirmation and the ref guard remain the gate.
- **O4.** Set the environment secrets (section 9). Server: the nine names above. Client: `API_URL`,
  `FRONTEND_URL`, `VITE_STAGING_GATE_PASSWORD` (staging), `FTP_*` or `SSH_*` plus
  `FRONTEND_REMOTE_DIR` and `FRONTEND_ALLOWED_ROOT`. Use separate SSH keys per environment.
- **O5.** Enable Dependabot alerts and security updates (Settings, Code security) in both repos.
- **O6.** On the host: create `<BACKUP_DIR>/daily`; create a scratch DB plus user whose name contains
  `restore` (for example `<prefix>_zc_restore_check`) with privileges on that DB only; write
  `<BACKUP_DIR>/restore-check.env` (chmod 600); add the two cron entries (section 8) with `MAILTO`;
  after the first run check that `db-backup.log` shows `Backup OK`.
- **O7.** Confirm the API domain docroot points to `<API_DIR>/public`, not `<API_DIR>`. Otherwise `.env`,
  `scripts/` and `storage/` would be web-reachable.
- **O8.** Central Mailer key for ZC production (section 11).
- **O9.** Ask the hosting provider (seohost) about its own backup retention and off-host copies (Q2).

## 13. Scheduler cron

Added by PP.18. Registration expiry, payment reminders, form snapshots, BZ push retries and mail
status reconciliation run from `bin/scheduler.php`, not from web requests. Without this cron nothing
expires and no reminders go out, on staging as well as production. Full runbook:
[scheduler.md](scheduler.md).

- **Cron line** (hosting panel "Zadania cron" or `crontab -e`; use the same PHP binary as `bin/migrate.php`):

  ```
  */5 * * * * /usr/local/bin/php /ABSOLUTE/BACKEND_REMOTE_DIR/bin/scheduler.php >/dev/null 2>&1
  ```

  `BACKEND_REMOTE_DIR` is the path from the deploy secret; the script reads the `.env` there.
- **Frequency:** every 5 minutes (required: the gateway window is 45 min with a 15 min reminder lead).
- **Overlap lock:** a MySQL advisory lock (`GET_LOCK`, per database). A run that finds it held logs the
  WARNING `Scheduler run skipped: another run holds the lock` and exits 0. The lock is freed when the
  holding process dies.
- **Exit codes:** 0 ok / skipped / lock held; 1 a job threw; 2 database unreachable or job wiring failed;
  64 usage error.
- **Run by hand:** `php bin/scheduler.php` (one line per job), `--status`, `--list`,
  `--job=registrations.expire_unpaid`.
- **Alerts:** `scheduler.job_failed` (a job threw or the jobs could not be built; the other jobs still ran)
  and `payments.paid_after_expiry` (money arrived for a Zapis that could not be confirmed: refund it or
  admit the participant manually). Both need an admin to look at the log.
- **Payment webhooks, registration id cross-check (PP.63):** a signed Tpay/PayNow notification carries the
  registration id we sent (Tpay `transactionHiddenDescription` / `tr_crc`, PayNow `externalId`). If it is
  present, has the format of our ids (UUID v4) and differs from the `registration_id` stored on the gateway
  transaction row, nothing is booked: the row becomes `amount_mismatch` (same state as a wrong amount), the
  gateway is acked (200) and one ERROR with alert `payments.registration_mismatch` is logged (transaction id,
  the row's registration id and gateway only; the notified id is never logged). Check the gateway
  configuration (mixed-up merchant account?) and reconcile by hand. A notification whose id is absent, empty
  or not in our format is NOT blocked (the real Tpay field is unverified until TD.4): the normal flow
  continues and a WARNING with `check` = `payments.registration_id_unverifiable` is logged (no alert key, so
  no e-mail). TD.4 must confirm the real field name and format; if that WARNING shows up for real payments,
  the field is not echoed as our id and the check is not protecting that gateway yet.

## 14. Shop orders: organizer package (PP.105)

- **When the package is granted:** only when a shop order becomes `paid`. Creating an order grants nothing
  (status `pending`). There is no payment gateway for shop orders yet, so "paid" is a staff confirmation:
  `PATCH /api/v1/shop/orders/{id}/status` `{"status":"paid"}` (moderator, admin, superadmin) or the `status`
  key of `PATCH /api/v1/shop/orders/{id}` (admin). Both go through `OrderRepository::changeStatus()`.
- **What `paid` grants** (`shop_orders.organizer_credits_granted` records it, so it happens once): credits
  += number of packages, `event_limit` += number of packages (organizers), and a plain `participant` becomes
  `organizer` (role change ends the buyer's sessions: they log in again). Staff buyers get credits only.
- **Cancel / refund of a paid package order:** takes back only UNUSED credits and lowers `event_limit` but not
  below the events the organizer already has. The role is never demoted automatically (use
  `PATCH /users/{id}/role` if needed).
- **Limit:** at most 10 packages per order line (`422 order_invalid_quantity`).
- **Audit of orders created by the old code (read-only, run BEFORE deploying migration 067).** The old code
  granted credits and the role when the order was created, regardless of payment. Migration 067 marks such
  orders as already granted (so marking them paid later does not grant twice) and corrects nothing else.
  `pending` rows with `organizer_package` below were granted without payment: decide per row (mark paid, or
  cancel after reversing by hand / via the new cancel path, or fix credits manually). After 067 add
  `o.organizer_credits_granted` to the SELECT and to the GROUP BY.

```sql
SELECT o.order_number, o.status, o.created_at, o.user_id, u.email, u.role,
       u.organizer_package_credits, u.event_limit, SUM(i.quantity) AS package_qty
FROM shop_orders o
JOIN shop_order_items i ON i.order_id = o.id
JOIN shop_products p ON p.id = i.product_id
JOIN users u ON u.id = o.user_id
WHERE p.service_kind IN ('organizer_package', 'organizer_license')
GROUP BY o.order_number, o.status, o.created_at, o.user_id, u.email, u.role,
         u.organizer_package_credits, u.event_limit
ORDER BY o.created_at;
```

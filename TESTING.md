# Testing Strategy

## Gates

- Unit PR gate: `composer lint && composer stan && composer test -- --testsuite Unit --fail-on-skipped`
- Integration gate (exact CI commands): `composer migrate` (twice — the second run must
  print `Migrations applied: 0 file(s).`, proving the ledger works), `composer seed`, then
  `composer test -- --testsuite Integration --fail-on-skipped --exclude-group requires-local-samples`.
- The test database's `.env` must set `APP_ENV=local`. `bin/seed.php` treats a missing
  `APP_ENV` as production and refuses to seed, so the gate fails without it.

## Backend Layers

- Use PHPUnit Unit for isolated logic such as JWT, UUIDs, validation helpers, and calculations.
- Use PHPUnit Integration for repositories and HTTP route contracts.
- HTTP contract tests should verify status codes, stable JSON shape, RBAC behavior, and important domain visibility rules.
- Integration tests may skip locally when the database is unavailable, but CI must provide a migrated and seeded MySQL database.

## Local test database

Tests must never run against your development database — a full Integration run drops and
reseeds data. Point them at a **separate** database through `ZC_TEST_DB_*` process
environment variables instead of editing `.env`:

```bash
# create <name>_test in your MySQL/MariaDB first, then:
ZC_TEST_DB_DATABASE=<name>_test composer test:prepare
ZC_TEST_DB_DATABASE=<name>_test vendor/bin/phpunit --testsuite Integration --display-skipped
```

`ZC_TEST_DB_HOST` / `_PORT` / `_USERNAME` / `_PASSWORD` are also recognised (all optional;
`.env`'s values are the fallback for anything not set). `ZC_TEST_DB_DATABASE` is **required**
to select a different database at all, and **must end in `_test`** — `TestDatabase` refuses
anything else, including your existing dev database, with a loud error. `composer
test:prepare` runs `tests/bin/test-db.php prepare` (drop + migrate + seed, same sequence CI
runs). `tests/bin/test-db.php migrate` and `tests/bin/test-db.php fingerprint` are also
available for debugging a migration by hand.

No `.env` file is ever created or modified by any of this — the overrides only live in the
process environment for that one command's lifetime (`ZmierzymyCzas\Support\Env::set()`,
applied once in `tests/bootstrap.php`, before anything under test can call `Env::load()`
again).

## Strict sql_mode in tests

Local XAMPP MariaDB is not strict; production, staging and CI (MySQL 8.4) are. So every test
database connection (`Connection::fromEnv()` and the PDO test doubles) runs
`SET SESSION sql_mode` with MySQL 8.4's defaults (`TestDatabase::TEST_SQL_MODE`), set from
`tests/bootstrap.php` and `tests/bin/test-db.php` through the `DB_SESSION_SQL_MODE` env key
(read only by `Connection`; production never sets it). A missing NOT NULL column or a bad
ENUM value therefore fails locally the way it fails in production.

- `ZC_TEST_DB_SQL_MODE=<mode>` uses another mode; `ZC_TEST_DB_SQL_MODE=server` keeps the
  server's own (opt-out, e.g. to bisect).
- A new PDO test double must call `Connection::applySessionSqlMode($this)` right after
  `parent::__construct(...)`; `StrictSessionTest` guards the existing ones.

## MySQL 8.4 portability check with docker

Production and staging run MySQL 8.4; the local MariaDB most developers have does not
prove a migration is portable. To check against the real engine:

```bash
docker run -d --name zc-mysql84 -p 3312:3306 -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8.4
docker exec zc-mysql84 mysql -uroot -e "CREATE DATABASE app_test;"

ZC_TEST_DB_HOST=127.0.0.1 ZC_TEST_DB_PORT=3312 ZC_TEST_DB_USERNAME=root ZC_TEST_DB_DATABASE=app_test composer test:prepare
ZC_TEST_DB_HOST=127.0.0.1 ZC_TEST_DB_PORT=3312 ZC_TEST_DB_USERNAME=root ZC_TEST_DB_DATABASE=app_test php tests/bin/test-db.php migrate
```

The second `migrate` must print `Migrations applied: 0 file(s).` — if it does not, either a
migration is not idempotent or the ledger did not record it, and `MigrationIdempotencyTest`
should fail too.

## Writing migrations

- **No MariaDB-only `IF [NOT] EXISTS` on an ALTER sub-clause** (`ADD COLUMN IF NOT EXISTS`,
  `DROP COLUMN IF EXISTS`, `ADD INDEX IF NOT EXISTS`, `ADD CONSTRAINT ... FOREIGN KEY IF NOT
  EXISTS`, ...) — MySQL 8.4 rejects it outright (syntax error 1064). `IF [NOT] EXISTS` is
  only portable on `CREATE TABLE` / `DROP TABLE`.
- Instead, precede a **single-clause** ALTER with a `-- @guard:` marker on its own line,
  evaluated against `information_schema` by `Migrator`/`MigrationGuard` before the statement
  runs:

  ```sql
  -- @guard: column-missing events.background_id
  ALTER TABLE events ADD COLUMN background_id VARCHAR(36) NULL;
  -- @guard: foreign-key-missing events.fk_events_background
  ALTER TABLE events ADD CONSTRAINT fk_events_background FOREIGN KEY (background_id) REFERENCES event_backgrounds(id);
  ```

  Guard kinds: `column-missing` / `column-exists` (ADD / DROP COLUMN), `index-missing` /
  `index-exists` (ADD / DROP [UNIQUE] INDEX), `foreign-key-missing` (ADD CONSTRAINT ...
  FOREIGN KEY). A multi-clause ALTER must be split into one ALTER per clause, each with its
  own guard, keeping the original clause order. `MigrationPortabilityTest`
  (`tests/Unit/Database`) lints this statically for every file, with no database needed.
- No TEXT/BLOB/JSON column may have a literal `DEFAULT '...'` — MySQL 8's default strict
  `sql_mode` rejects it (error 1101). Use an expression default instead: `DEFAULT ('')`
  (MySQL ≥ 8.0.13, MariaDB ≥ 10.2).
- **The ledger does not excuse non-idempotency.** `schema_migrations` (filename, sha256
  checksum, `applied_at`) means a file only (re-)applies when it is missing from the ledger
  or its checksum changed — but every file must still tolerate being applied more than once:
  bootstrapping the ledger on an existing database, a crash mid-file (DDL is not
  transactional), or a hand-edited migration all replay it. `MigrationIdempotencyTest`
  enforces this by forcing a full re-apply (`Migrator::applyAll($pdo, reapplyAll: true)`)
  twice against a seeded database and checking the schema fingerprint and every table's row
  count are unchanged.
- A backfill that needs to keep catching rows inserted outside the migration path (seeders,
  fixtures, tests) after its own file is ledgered — not just rows that existed the first time
  it ran — cannot rely on the ledger re-running its file. Move it to a small PHP class called
  unconditionally from `Migrator::applyAll()`, after the file loop (see
  `CompetitorProfileBackfill`, `ChallengeConditionTreeBackfill`, `FormFieldsCatalogBackfill`,
  `CustomFormFieldsBackfill` for the pattern) — it must still be idempotent on its own.

## Skips fail CI

`--fail-on-skipped` is set on both suites in CI. The only tests allowed to skip there carry
`@group requires-local-samples` (need `przyklady/import-wynikow/*`, outside this repository,
never present in CI) — excluded explicitly via `--exclude-group`. Any other skip is a CI
failure: it usually means a migration or seed step silently failed and the test's own
`try {...} catch { markTestSkipped(...) }` swallowed it — "0 failures, N skipped" is not a
green run.

## ZC <-> BZ end-to-end contract test

`tests/e2e/bz/run.php` starts the real ZC server (this checkout) and the real Biuro Zawodow API
(`Aberratio/biuro_zawodow-api`, a second checkout) on two fresh MySQL databases and drives the
whole integration over HTTP and the two CLIs. Each side's own suites test against fakes of the
other; this is the only test that notices when the two stop agreeing.

Scenario (every step has HTTP and database assertions, about 65 in total):

- **S0** BZ stores the platform connection and never echoes the token; a pull while the ZC
  integration is still off is refused (`token_rejected`) and imports nothing.
- **S1** the ZC organizer enables the integration (`PATCH /events/{id}`).
- **S2** BZ pulls: three participants with matching `platform_registration_id`, `pqr_` and
  `zqr_` codes; a second pull creates no duplicates.
- **S3** a participant withdraws on ZC; the webhook is delivered in the same request (outbox
  `sent`), BZ marks only that participant withdrawn; a repeated cancel and `bz.outbox_retry`
  send nothing twice.
- **S4** at BZ a `zqr_` scan and a `pqr_` scan resolve the right participants and check them in;
  scanning the withdrawn one warns (`platform_removed_at`) and does not check in.
- **S5** `scripts/push-platform-changes.php` writes `checked_in_at` / `checked_in_by` back into
  ZC for the two checked-in participants only; a second push changes nothing.

Exit codes: `0` passed, `1` an assertion failed (step label and redacted diagnostics are
printed), `2` environment problem (missing extension, vendor, busy port, bad DB name ...).

### Run it locally

Use dedicated checkouts or worktrees of both repos; the runner writes (and afterwards deletes)
its own `.env` in the ZC checkout and refuses to touch a `.env` it did not write.

```
git worktree add ../zc-e2e <branch>          # ZC server
git worktree add ../bz-e2e origin/master     # in the BZ api repo
composer install                              # in both
```

```
export BZ_DIR=/path/to/bz-e2e
export E2E_DB_HOST=127.0.0.1 E2E_DB_PORT=3306 E2E_DB_USER=root E2E_DB_PASSWORD=
php tests/e2e/bz/run.php            # or: composer e2e:bz
```

Needs PHP extensions `pdo_mysql`, `curl` and `sodium` (XAMPP on Windows:
`php -d extension=sodium tests/e2e/bz/run.php`) and MySQL 8.4 (the run drops and recreates
exactly `zc_e2e` and `bz_e2e`; names must end in `_e2e`). Optional variables: `E2E_ZC_DB`,
`E2E_BZ_DB`, `E2E_ZC_PORT` (18081), `E2E_BZ_PORT` (18090), `E2E_LOG_DIR`, `ZC_DIR`; `--keep`
leaves the databases, the `.env` and the logs for debugging. A run takes under 30 s. It does
not use `bin/seed.php` (the full seed takes minutes locally); the fixture loads only the demo
accounts.

### CI (`.github/workflows/e2e-bz.yml`)

Runs on pushes to main/master, weekly on Monday night (catches BZ-side drift) and manually
(`bz_ref` input; default is the repository variable `BZ_API_REF`, else `master`). The BZ
repository is private, so the job needs a read-only deploy key. **Owner setup, once:**

1. Create an SSH key pair without passphrase: `ssh-keygen -t ed25519 -N "" -C "zc-e2e-bz" -f bz-e2e-key`.
2. `Aberratio/biuro_zawodow-api` -> Settings -> Deploy keys -> add the **public** key, leave
   "Allow write access" unchecked.
3. `Aberratio/eventflow-server` -> Settings -> Secrets and variables -> Actions -> new secret
   `BZ_API_REPO_SSH_KEY` with the **private** key; then delete the local key files.
4. After the first green run, add the `e2e` check (job "ZC <-> BZ end-to-end contract") as a
   required check in the branch ruleset.

Until the secret exists the job does not run the test: it finishes with a warning annotation
"BZ e2e did NOT run" and a "SKIPPED" job summary. That is deliberately visible, but it is not
a gate. Pin a BZ ref temporarily through `BZ_API_REF` if a BZ change must not block ZC pull
requests.

## Checking an existing DB (e.g. staging) before configuring deploy secrets

Read-only — run these against the target database and compare against a fresh database's
`tests/bin/test-db.php fingerprint` output:

```sql
SELECT VERSION(), @@sql_mode, @@time_zone;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, COLUMN_NAME;

SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
 WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY' ORDER BY 1, 2;

-- Duplicate root condition-tree nodes from an old (pre-PP.13) re-run of migration 054:
SELECT challenge_id, COUNT(*) AS roots FROM challenge_condition_nodes
 WHERE parent_node_id IS NULL GROUP BY challenge_id HAVING roots > 1;

-- Orphan challenge_events rows (no matching challenges row):
SELECT COUNT(*) FROM challenge_events ce LEFT JOIN challenges c ON c.id = ce.challenge_id WHERE c.id IS NULL;
```

If `VERSION()` reports MariaDB rather than MySQL, or the schema is missing migrations, that
is a decision for the owner (whether CI should stay targeting MySQL 8.4, and whether to
repair the database) before configuring deploy secrets — not something a migration run
should paper over.

## Contract Rule

Any API payload or response shape change should update the route/repository implementation and a backend HTTP contract or repository integration test.

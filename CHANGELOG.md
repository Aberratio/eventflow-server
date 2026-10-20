# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and versions follow [calendar versioning](https://calver.org/)
(`YYYY.MM.PATCH`). See [RELEASING.md](RELEASING.md) for how a version is cut
and deployed.

## [Unreleased]

Not yet released to production. The first production deploy will be tagged
as the first version.

### Added

- Challenge participation belongs to the competitor (zakres P2, to be confirmed
  by the client): a caregiver can join a challenge for a ward
  (`POST /challenges/{id}/join` with an optional `competitorId`; 403 for a
  competitor outside the caregiver's care), progress and rankings are computed
  per competitor, and `GET /challenge-participations?userId=<own id>` also
  returns the wards' participations with `competitorName`. Existing rows are
  mapped to the account's own competitor profile by migration 120; rows that
  cannot be mapped keep working by account.
- REST API (Slim 4, PDO/MySQL, JWT) shaped to the ZmierzymyCzas frontend
  contract: events and their lifecycle, registrations with pricing variants
  and custom form fields, teams and relay member swaps, results with audited
  corrections, series and challenges with progress and rankings, a shop, and
  info pages.
- Anonymised competitors appear in public start lists, results and rankings
  as `Anonim #<code>`, a label stable per competitor across events that
  carries no personal data.
- Online payments through Tpay and PayNow, with webhook amount and
  registration cross-checks, payment retries and expiry of unpaid
  registrations.
- Account security: e-mail verification and verified e-mail change, password
  reset with hashed one-time tokens, session revocation and rotation, and
  blocked-account checks on every request.
- Route-level access control covered by an RBAC test grid over every
  registered route.
- Transactional mail through Central Mailer via an outbox with backoff and
  `Retry-After` handling, QR code attachments and the organizer as reply-to.
- Scheduler (`bin/scheduler.php`) for payment reminders, registration expiry,
  results-published mail, outbox and webhook retries, and Google Calendar
  sync.
- Integration with the biuro_zawodow race office: webhook pushes with retries
  and an end-to-end contract test in CI.
- Operations: PII-redacting structured logs, throttled e-mail alerts,
  `/health`, daily database backups with restore checks, and scripted deploys
  with code snapshots and rollback.
- Demo data seeding around the current date, with locked demo accounts
  outside local environments.
- `/health` reports the deployed `version` (from the release tag) and
  `commit`, and the deploy fails its health check when the commit does not
  match. `APP_VERSION` only overrides the version now: clear the old
  `2026xxxx-x` placeholder from existing `.env` files.

[Unreleased]: https://github.com/Aberratio/eventflow-server/commits/master

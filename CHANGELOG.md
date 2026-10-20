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

- REST API (Slim 4, PDO/MySQL, JWT) shaped to the ZmierzymyCzas frontend
  contract: events and their lifecycle, registrations with pricing variants
  and custom form fields, teams and relay member swaps, results with audited
  corrections, series and challenges with progress and rankings, a shop, and
  info pages.
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
- Settlement report for gateway (route A) payments: per-event payments, refunds,
  platform commission (`SETTLEMENT_COMMISSION_BPS`, default 0) and the net amount to
  pay out to the organizer for a date range, with CSV/XLSX export. Admin only
  (`GET /admin/settlements`, `GET /admin/settlements/export`).
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

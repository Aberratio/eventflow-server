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
- Anonymised competitors appear in public start lists, results and rankings
  as `Anonim #<code>`, a label stable per competitor across events that
  carries no personal data.
- Online payments through Tpay and PayNow, with webhook amount and
  registration cross-checks, payment retries and expiry of unpaid
  registrations.
- Discount code batches: an organizer of the event (or an admin) generates up
  to 1000 unique, unambiguous codes at once (prefix, value, usage limit per
  code, validity) and downloads the batch as a CSV
  (`POST|GET /events/{id}/discount-code-batches`, `.../{batchId}/export`).
  Batch codes are ordinary discount codes.
- Account security: e-mail verification and verified e-mail change, password
  reset with hashed one-time tokens, session revocation and rotation, and
  blocked-account checks on every request.
- Route-level access control covered by an RBAC test grid over every
  registered route.
- Caregivers and organizers can report a possible duplicate competitor
  profile; reports go to a moderator/admin queue next to profile merging and
  are closed by a rejection or by the merge.
- Transactional mail through Central Mailer via an outbox with backoff and
  `Retry-After` handling, QR code attachments and the organizer as reply-to.
- Settlement report for gateway (route A) payments: per-event payments, refunds,
  platform commission (`SETTLEMENT_COMMISSION_BPS`, default 0) and the net amount to
  pay out to the organizer for a date range, with CSV/XLSX export. Admin only
  (`GET /admin/settlements`, `GET /admin/settlements/export`).
- Scheduler (`bin/scheduler.php`) for payment reminders, registration expiry,
  results-published mail, outbox and webhook retries, and Google Calendar
  sync.
- Data retention job `retention.purge`: answers to confidential form fields
  are deleted 90 days after the event ends and activity log entries after 12
  months (`RETENTION_CONFIDENTIAL_ANSWERS_DAYS`,
  `RETENTION_ACTIVITY_LOG_MONTHS`).
- Integration with the biuro_zawodow race office: webhook pushes with retries
  and an end-to-end contract test in CI.
- Registration form per distance: a distance inherits the event form and can
  override an inherited field (required/visible/hidden, label, variants and
  prices) or add its own fields. Registration validation and pricing, the
  public form endpoint (`?distanceId=`) and new organizer endpoints under
  `/events/{id}/distances/{distanceId}/form-fields` use the distance's form.
- Operations: PII-redacting structured logs, throttled e-mail alerts,
  `/health`, daily database backups with restore checks, and scripted deploys
  with code snapshots and rollback.
- Registration price quote also refuses a full distance and a team the caller
  has no invitation to, with the same errors as the registration itself.
- An event switched to private visibility is removed from the Google Calendar
  (queued through the calendar outbox, like a cancellation).
- Manually corrected result places never collide: two manual places cannot be
  equal in a classification, and computed places skip the manual ones. Results
  also carry the place by gender (`genderPosition`).
- Demo data seeding around the current date, with locked demo accounts
  outside local environments.
- `/health` reports the deployed `version` (from the release tag) and
  `commit`, and the deploy fails its health check when the commit does not
  match. `APP_VERSION` only overrides the version now: clear the old
  `2026xxxx-x` placeholder from existing `.env` files.

### Fixed

- An unknown event slug returns a 404 error response (never 200 with empty data).
- Error responses no longer leak exception messages or stack traces outside
  local debug mode (`APP_DEBUG=true` with `APP_ENV=local`).
- CORS preflight allows `PUT` (together with GET, POST, PATCH, DELETE and
  OPTIONS).

[Unreleased]: https://github.com/Aberratio/eventflow-server/commits/master

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

Foundation

- REST API (Slim 4, PDO/MySQL, JWT) shaped to the ZmierzymyCzas frontend
  contract: events and their lifecycle, registrations with pricing variants
  and custom form fields, teams and relay member swaps, results with audited
  corrections, series and challenges with progress and rankings, a shop, and
  info pages.
- Account security: e-mail verification and verified e-mail change, password
  reset with hashed one-time tokens, session revocation and rotation, and
  blocked-account checks on every request.
- Login throttling: 5 failed attempts per account or 20 per IP within 15
  minutes lock further attempts with a growing lockout (1, 5, then 15
  minutes) answered with `429` and `Retry-After`; unknown e-mails behave the
  same, a lockout is written to the activity log and a successful login
  resets the account counter. Tunable with `LOGIN_THROTTLE_*` variables.
- Operations: PII-redacting structured logs, throttled e-mail alerts,
  `/health`, daily database backups with restore checks, and scripted deploys
  with code snapshots and rollback.
- Demo data seeding around the current date, with locked demo accounts
  outside local environments.
- Scheduler (`bin/scheduler.php`) for payment reminders, registration expiry,
  results-published mail, outbox and webhook retries, and Google Calendar
  sync.
- Data retention job `retention.purge`: answers to confidential form fields
  are deleted 90 days after the event ends and activity log entries after 12
  months (`RETENTION_CONFIDENTIAL_ANSWERS_DAYS`,
  `RETENTION_ACTIVITY_LOG_MONTHS`).

Registrations and payments

- Online payments through Tpay and PayNow, with webhook amount and
  registration cross-checks, payment retries and expiry of unpaid
  registrations.
- Registration form per distance: a distance inherits the event form and can
  override an inherited field (required/visible/hidden, label, variants and
  prices) or add its own fields. Registration validation and pricing, the
  public form endpoint (`?distanceId=`) and new organizer endpoints under
  `/events/{id}/distances/{distanceId}/form-fields` use the distance's form.
- Start (bib) number ranges per distance: ranges of one event may not overlap,
  `POST /events/{eventId}/bib-numbers/assign` gives the next free number within
  each range, and a manual number must be unique in the event (409) and fall
  inside the distance range (422).
- Discount code batches: an organizer of the event (or an admin) generates up
  to 1000 unique, unambiguous codes at once (prefix, value, usage limit per
  code, validity) and downloads the batch as a CSV
  (`POST|GET /events/{id}/discount-code-batches`, `.../{batchId}/export`).
  Batch codes are ordinary discount codes.
- Settlement report for gateway payments: per-event payments, refunds,
  platform commission (`SETTLEMENT_COMMISSION_BPS`, default 0) and the net
  amount to pay out to the organizer for a date range, with CSV/XLSX export.
  Admin only (`GET /admin/settlements`, `GET /admin/settlements/export`).
- Self-service cancellation of a registration by its owner: per-event switch
  and cut-off date (`selfServiceEnabled`, `selfServiceDeadline`, default 7 days
  before the start), a refund request opened automatically for a paid
  registration, the freed slot returned to the pool, and a per-registration
  change history (`GET /registrations/{id}/changes`). Organizers can still
  cancel at any time.
- Change of the distance or fee variant of a registration:
  `POST /registrations/{id}/distance-change` and a read-only
  `.../distance-change/quote`. The owner may do it inside the self-service
  window, the organizer always; capacity is checked on the target distance,
  the seat moves, the category is recomputed, the start fee is priced again at
  today's price list (other settlement lines and a still valid discount code
  stay), the bib number is cleared and the start code kept. A cheaper distance
  opens a refund request for the overpayment; a dearer one is refused for the
  owner (`balance_due_requires_organizer`) and left to the organizer, who gets
  `balanceDueMinor` and settles the extra by hand. The change is written to
  the registration history and the activity log, mailed to the registrant and
  pushed to Biuro Zawodów as `distance_change`.
- Transfer of a registration to another competitor by the event manager:
  `POST /registrations/{id}/transfer` with `competitorId` or `newPerson`; the
  owner gets 403. The registration, its distance, bib number and frozen
  settlement stay; the person, contact data and category change, the start
  code rotates and the custom form answers are dropped. The personal NNW
  insurance line is priced again for the new person; any difference is only
  reported (`balanceDueMinor` / `overpaymentMinor`), with no payment, refund or
  settlement between the two people. Relay team members are refused (use the
  team member swap). The previous and the new person get a mail, the change
  goes to the registration history, the activity log and to Biuro Zawodów as
  `transfer`.

Competitors, challenges and results

- Challenge participation belongs to the competitor: a caregiver can join a
  challenge for a ward (`POST /challenges/{id}/join` with an optional
  `competitorId`; 403 for a competitor outside the caregiver's care), progress
  and rankings are computed per competitor, and
  `GET /challenge-participations?userId=<own id>` also returns the wards'
  participations with `competitorName`. Existing rows are mapped to the
  account's own competitor profile; rows that cannot be mapped keep working by
  account.
- Anonymised competitors appear in public start lists, results and rankings
  as `Anonim #<code>`, a label stable per competitor across events that
  carries no personal data.
- Caregivers and organizers can report a possible duplicate competitor
  profile; reports go to a moderator/admin queue next to profile merging and
  are closed by a rejection or by the merge.
- Results carry the place by gender (`genderPosition`).

Event Team

- Event Team: besides its Owner an event can have Co-managers, any account
  whatever its global role. A Co-manager edits the event and manages
  registrations, results, form fields and mail templates; the Team roster, the
  event's payment configuration, deleting the event, handing over ownership
  and refunds or manual payments stay with the Owner (and admin). The Owner
  (or an admin) invites a Co-manager by e-mail
  (`POST /events/{id}/team/invitations`, link valid 7 days, only its hash is
  stored), lists the Team (`GET /events/{id}/team`), resends an invitation and
  takes access away (`DELETE /events/{id}/team/{memberId}`, the person is told
  by mail). The invited account accepts at
  `POST /team-invitations/{token}/accept` (its e-mail must match the
  invitation); `GET /team-invitations/{token}` is a public preview. A revoked
  or merely invited member has no access. Invites, acceptances and revocations
  are recorded in the activity register, and the organizer panel list
  (`GET /organizers/{id}/events`) includes co-managed events with a `teamRole`
  of `owner` or `co_manager`.

Integrations and mail

- Transactional mail through Central Mailer via an outbox with backoff and
  `Retry-After` handling, QR code attachments and the organizer as reply-to.
- Integration with the biuro_zawodow race office: webhook pushes with retries
  and an end-to-end contract test in CI.

### Changed

- `/health` reports the deployed `version` (from the release tag) and
  `commit`, and the deploy fails its health check when the commit does not
  match. `APP_VERSION` only overrides the version now: clear the old
  `2026xxxx-x` placeholder from existing `.env` files.
- Internal refactor: route handlers answer domain failures through one
  `DomainErrorResponder` (status from the error category, messages from a
  central catalogue, per-route overrides stay at the call site) instead of
  per-route message chains. API responses are unchanged.

### Fixed

- An unknown event slug returns a 404 error response (never 200 with empty data).
- Error responses no longer leak exception messages or stack traces outside
  local debug mode (`APP_DEBUG=true` with `APP_ENV=local`).
- CORS preflight allows `PUT` (together with GET, POST, PATCH, DELETE and
  OPTIONS).
- Registration price quote also refuses a full distance and a team the caller
  has no invitation to, with the same errors as the registration itself.
- An event switched to private visibility is removed from the Google Calendar
  (queued through the calendar outbox, like a cancellation).
- Manually corrected result places never collide: two manual places cannot be
  equal in a classification, and computed places skip the manual ones.

[Unreleased]: https://github.com/Aberratio/eventflow-server/commits/master

-- E1.9: Central Mailer — technical integration layer. Own outbox table, per the
-- recommended integration shape in kontrakt-central-mailer.md §6.4: Central
-- Mailer's idempotency is keyed on (sourceApp, Idempotency-Key) + a hash of the
-- request body that includes the rendered `html` (§6.1-6.2), and it has no
-- webhooks (§8.2) — only polling. So this platform needs its own record of "what
-- did we ask to be sent, with which exact HTML, and what did Central Mailer last
-- tell us about it", independent of Central Mailer's own 90-day retention (§8.4).
--
-- `idempotency_key` is UNIQUE and is written by the caller *before* the HTTP call
-- to `POST /emails` — see CentralMailerOutboxService::enqueue(). Constructing a
-- deterministic key (no random_bytes/uniqid/timestamps — §6.2) is the caller's
-- responsibility per message type; this table only enforces that whatever key
-- shows up is unique, never generates one itself.
--
-- `source_entity_type`/`source_entity_id` are free-form references to whatever
-- domain entity triggered the message (e.g. a registration, a password-reset
-- token) — deliberately untyped here since E1.9 does not define any concrete
-- message types (that catalog is E1.10).
--
-- `status` mirrors whatever Central Mailer's own `status` field last reported
-- (`pending|processing|sent|retry|failed|unknown`, kept as free-form VARCHAR
-- rather than an ENUM because the source doc's own §1.2 notes Central Mailer's
-- OpenAPI already lags its code on this exact field), plus one extra value this
-- table owns itself — `queued` — meaning "outbox row written, `POST /emails` not
-- yet attempted or its result not yet recorded".
CREATE TABLE IF NOT EXISTS central_mailer_outbox (
  id VARCHAR(36) PRIMARY KEY,
  source_entity_type VARCHAR(64) NOT NULL,
  source_entity_id VARCHAR(36) NULL,
  idempotency_key VARCHAR(255) NOT NULL,
  priority VARCHAR(16) NOT NULL DEFAULT 'normal',
  recipient VARCHAR(255) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  html LONGTEXT NOT NULL,
  context_id VARCHAR(64) NULL,
  metadata JSON NULL,
  central_mailer_email_id VARCHAR(64) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  last_error VARCHAR(1024) NULL,
  created_at VARCHAR(64) NOT NULL,
  sent_to_mailer_at VARCHAR(64) NULL,
  last_checked_at VARCHAR(64) NULL,
  UNIQUE KEY uniq_central_mailer_outbox_idempotency_key (idempotency_key),
  INDEX idx_central_mailer_outbox_source (source_entity_type, source_entity_id),
  INDEX idx_central_mailer_outbox_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

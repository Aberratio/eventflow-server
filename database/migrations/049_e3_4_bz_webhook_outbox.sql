-- E3.4: "Push zmian krytycznych (rezygnacja, zwrot) webhookiem" — own outbox
-- table, mirroring central_mailer_outbox's shape (037_e19_central_mailer_outbox.sql)
-- and the same reasoning: BZ's webhook receiver does not exist yet (separate
-- repo/team backlog, E3.7) and even once it does, this platform needs its own
-- durable record of "what change did we tell BZ about, with which idempotency
-- key, and did the call succeed" independent of whatever BZ's own retention is.
--
-- `idempotency_key` is UNIQUE and is written by the caller *before* the HTTP
-- call — see BzWebhookOutboxService::enqueue(). Deterministic per
-- (registration, change type): `bz-push:{registrationId}:{changeType}`, never a
-- timestamp/random suffix, so a retried transition (e.g. a request that died
-- after writing this row but before the HTTP response came back) resends the
-- exact same event rather than duplicating it.
--
-- `event_id`/`registration_id` are the identifiers BZ needs to resolve which
-- participant record changed (issue 12 §1). `change_type` is one of
-- 'cancellation' | 'refund_approved' | 'refund_refunded' — this ticket's two
-- named triggers (rezygnacja, zwrot), with refund split into its two
-- BZ-relevant sub-states so a "do zwrotu" and a completed "zwrócono" are
-- distinguishable events, not the same one repeated. `payload` is the exact
-- JSON body sent (or about to be sent) to BZ — kept for audit/debugging, never
-- containing money fields (see BzWebhookOutboxService's doc comment).
--
-- Migracje nie mają ledgera i uruchamiają się przy każdym starcie
-- (Migrator.php) — CREATE TABLE IF NOT EXISTS jest tu bezpieczne do powtórzenia.
CREATE TABLE IF NOT EXISTS bz_webhook_outbox (
  id VARCHAR(36) PRIMARY KEY,
  event_id VARCHAR(36) NOT NULL,
  registration_id VARCHAR(36) NOT NULL,
  change_type VARCHAR(32) NOT NULL,
  idempotency_key VARCHAR(255) NOT NULL,
  payload JSON NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  last_error VARCHAR(1024) NULL,
  created_at VARCHAR(64) NOT NULL,
  sent_at VARCHAR(64) NULL,
  UNIQUE KEY uniq_bz_webhook_outbox_idempotency_key (idempotency_key),
  INDEX idx_bz_webhook_outbox_event (event_id),
  INDEX idx_bz_webhook_outbox_registration (registration_id),
  INDEX idx_bz_webhook_outbox_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

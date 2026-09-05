-- E1.10: server-side storage for the mail template model (17-katalog-maili
-- §5). Central Mailer has no template concept at all (04 §5) — every template
-- (global default and per-event override alike) lives here, and MailRenderer
-- turns a stored row plus a variables array into the final HTML that
-- CriticalMailer hands to CentralMailerOutboxService::enqueue().
--
-- `event_id` is NOT NULL with '' meaning "the global/system default" rather
-- than nullable — MySQL's UNIQUE index treats every NULL as distinct, so a
-- nullable event_id could not actually enforce "at most one global row per
-- mail_type". Using '' keeps (mail_type, event_id) a real uniqueness
-- constraint for both the global row and every per-event override.
--
-- `version` starts at 1 and is incremented on every save (MailTemplateRepository)
-- — it feeds directly into the deterministic Idempotency-Key format from
-- 17 §6 (`{sourceEntityId}:{mailType}:v{version}`), so an admin/organizer
-- editing a template does not collide with an idempotency key already used
-- for the same entity under the previous wording.
CREATE TABLE IF NOT EXISTS mail_templates (
  id VARCHAR(36) PRIMARY KEY,
  mail_type VARCHAR(64) NOT NULL,
  event_id VARCHAR(36) NOT NULL DEFAULT '',
  subject VARCHAR(255) NOT NULL,
  body_html LONGTEXT NOT NULL,
  version INT NOT NULL DEFAULT 1,
  updated_by VARCHAR(36) NULL,
  created_at VARCHAR(64) NOT NULL,
  updated_at VARCHAR(64) NOT NULL,
  UNIQUE KEY uniq_mail_templates_type_event (mail_type, event_id),
  INDEX idx_mail_templates_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- E1.10 mail #13 ("Ukończenie Wyzwania", 17 §1 row 13): challenge completion
-- is not a discrete write anywhere today — ChallengeRepository::hydrateParticipation()
-- only *computes* isCompleted on every read from the entries list. This column
-- is the minimal addition needed to fire the completion mail exactly once per
-- participation, at the actual moment an approved entry count first reaches
-- the challenge's minCompleted threshold (see ChallengeRepository::reviewEntry()
-- and ::syncResultEntries()).
ALTER TABLE challenge_participations
  ADD COLUMN IF NOT EXISTS completion_notified_at VARCHAR(64) NULL;

-- PP.19: domknięcie maili (ponawianie wysyłki, załączniki QR, mail "Publikacja wyników").
--
-- Migrator wykonuje każdy plik przy każdym uruchomieniu (i ponownie, gdy zmieni się
-- suma kontrolna), więc wszystko tutaj musi być idempotentne i przenośne
-- (MySQL 8.4 + MariaDB 10.4): adnotacje @guard, bez `ADD COLUMN IF NOT EXISTS`.

-- Blok 1: ponawianie wysyłki maili (licznik prób, termin następnej próby w UTC 'Y-m-d H:i:s',
-- załączniki jako dokładny tekst - LONGTEXT, nie JSON, bo MySQL 8.4 normalizuje kolejność kluczy JSON).
-- @guard: column-missing central_mailer_outbox.attempts
ALTER TABLE central_mailer_outbox ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER status;
-- @guard: column-missing central_mailer_outbox.next_attempt_at
ALTER TABLE central_mailer_outbox ADD COLUMN next_attempt_at VARCHAR(64) NULL AFTER attempts;
-- @guard: column-missing central_mailer_outbox.attachments
ALTER TABLE central_mailer_outbox ADD COLUMN attachments LONGTEXT NULL AFTER metadata;
-- @guard: index-missing central_mailer_outbox.idx_central_mailer_outbox_next_attempt
ALTER TABLE central_mailer_outbox ADD INDEX idx_central_mailer_outbox_next_attempt (next_attempt_at);
-- Wiersze sprzed PP.19: jedna próba już była; next_attempt_at zostaje NULL = bez automatycznego ponawiania.
UPDATE central_mailer_outbox SET attempts = 1
WHERE attempts = 0 AND next_attempt_at IS NULL AND (central_mailer_email_id IS NOT NULL OR status = 'failed');

-- Blok 2: source_entity_id mieści "eventId:odbiorca" (maile #8 i #10), wcześniej VARCHAR(36) przepełniał się w MySQL 8.4 (strict).
ALTER TABLE central_mailer_outbox MODIFY source_entity_id VARCHAR(80) NULL;

-- Blok 3: zlecenia maili "Publikacja wyników" (fan-out wykonuje harmonogram).
CREATE TABLE IF NOT EXISTS results_publication_mail_requests (
  event_id VARCHAR(36) NOT NULL,
  distance_id VARCHAR(36) NOT NULL,
  requested_at VARCHAR(64) NOT NULL,
  processed_at VARCHAR(64) NULL,
  recipients_queued INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (event_id, distance_id),
  INDEX idx_results_publication_mail_requests_pending (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

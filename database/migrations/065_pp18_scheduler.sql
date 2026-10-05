-- PP.18: harmonogram zadań (bin/scheduler.php) zamiast przetwarzania "przy odczycie".
--
-- Plik jest rozszerzany w kolejnych etapach o dopisane na końcu, chronione bloki.
-- Migrator wykonuje każdy plik przy każdym uruchomieniu (i ponownie, gdy zmieni się
-- suma kontrolna), więc wszystko tutaj musi być idempotentne i przenośne
-- (MySQL 8.4 + MariaDB 10.4): adnotacje @guard, bez `ADD COLUMN IF NOT EXISTS`.

-- Blok 1: stan uruchomień harmonogramu. Tylko klasa wyjątku, nigdy treść komunikatu.
CREATE TABLE IF NOT EXISTS scheduler_job_runs (
  job VARCHAR(64) NOT NULL PRIMARY KEY,
  last_started_at VARCHAR(64) NOT NULL,
  last_finished_at VARCHAR(64) NULL,
  last_status VARCHAR(16) NOT NULL,
  last_error_class VARCHAR(255) NULL,
  last_counters JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blok 2: przypomnienie o płatności raz na okno płatności. Kolumna trzyma wartość
-- payment_expires_at, dla której przypomnienie już poszło (ponowienie płatności otwiera
-- nowe okno, więc dostaje własne przypomnienie).
-- @guard: column-missing registrations.payment_reminder_for
ALTER TABLE registrations ADD COLUMN payment_reminder_for VARCHAR(64) NULL AFTER payment_expires_at;

-- Backfill: Zapisy już przypomniane starym mechanizmem nie dostaną drugiego maila w tym samym oknie.
UPDATE registrations r SET r.payment_reminder_for = r.payment_expires_at
WHERE r.status = 'pending' AND r.payment_reminder_for IS NULL AND r.payment_expires_at IS NOT NULL
  AND EXISTS (SELECT 1 FROM central_mailer_outbox o
              WHERE o.source_entity_type = 'unpaid_reminder' AND o.source_entity_id = r.id);

-- Blok 3: ponawianie pushy do BZ (licznik prób i termin następnej próby).
-- @guard: column-missing bz_webhook_outbox.attempts
ALTER TABLE bz_webhook_outbox ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER status;
-- @guard: column-missing bz_webhook_outbox.next_attempt_at
ALTER TABLE bz_webhook_outbox ADD COLUMN next_attempt_at VARCHAR(64) NULL AFTER attempts;
UPDATE bz_webhook_outbox SET attempts = 1 WHERE attempts = 0 AND status IN ('sent', 'failed');

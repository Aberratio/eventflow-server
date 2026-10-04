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

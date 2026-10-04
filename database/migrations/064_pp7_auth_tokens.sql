-- PP.7: unieważnianie sesji i jednorazowe tokeny akcji (reset hasła,
-- weryfikacja i zmiana e-maila).
--
-- Blok 7a: `users.token_version` (podbijany przy zmianie/resecie hasła i zmianie
-- e-maila; JWT niesie `tv` i każdy uwierzytelniony request porównuje go z bazą)
-- oraz `revoked_session_tokens` (lista `jti` wylogowanych sesji, tylko bieżące
-- urządzenie). Domyślnie token_version = 0, a JWT bez `tv` liczy się jako 0,
-- więc wdrożenie nie wylogowuje nikogo.
--
-- Plik jest rozszerzany w kolejnych etapach (7b, 7c) o dopisane na końcu,
-- chronione bloki. Migrator nie ma ledgera i wykonuje każdy plik przy każdym
-- uruchomieniu, więc wszystko tutaj musi być idempotentne i przenośne
-- (MySQL 8.4 + MariaDB 10.4): adnotacje @guard, bez `ADD COLUMN IF NOT EXISTS`.

-- @guard: column-missing users.token_version
ALTER TABLE users ADD COLUMN token_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER status;

CREATE TABLE IF NOT EXISTS revoked_session_tokens (
  jti CHAR(32) NOT NULL PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rst_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blok 7b: jednorazowe tokeny akcji (reset hasła; w 7c także weryfikacja i
-- zmiana e-maila). W bazie leży tylko sha256 tokenu, surowy token jest
-- wyłącznie w linku z maila. `id` jest też źródłem idempotencji maila
-- (CriticalMailer), dzięki czemu każdy kolejny mail dla tego samego
-- użytkownika jest wysyłany. Celowo bez klucza obcego do `users`: migracje 057
-- (anonimizacja) i 059 (scalanie) zostają nietknięte, a konsumpcja łączy
-- `users`, więc osierocone wiersze są nieszkodliwe.
CREATE TABLE IF NOT EXISTS user_action_tokens (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL,
  purpose VARCHAR(32) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  new_email VARCHAR(255) NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_uat_token_hash (token_hash),
  INDEX idx_uat_user_purpose (user_id, purpose, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blok 7c: `users.email_verified_at` (NULL = niezweryfikowany; w API tylko jako
-- bool `emailVerified`, nigdy znacznik czasu). Nic nie jest uzależnione od
-- weryfikacji — to informacja dla użytkownika.
--
-- Backfill: konta założone przed wdrożeniem liczą się jako zweryfikowane
-- (inaczej każdy obecny użytkownik zobaczyłby niewyjaśnioną plakietkę
-- "niezweryfikowany"; ich maile rejestracyjne i tak zawierały martwy link).
-- Literał daty (dzień po commicie 7c) sprawia, że ponowne uruchomienie pliku
-- jest idempotentne: konta założone później nigdy nie są auto-weryfikowane.
-- @guard: column-missing users.email_verified_at
ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER email;

UPDATE users SET email_verified_at = created_at
WHERE email_verified_at IS NULL AND created_at < '2026-10-05';

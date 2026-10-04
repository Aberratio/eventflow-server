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

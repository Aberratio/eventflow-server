-- G-07 (umowa §5.8): trwałe "ostatnie logowanie" użytkownika. NULL = jeszcze nigdy się nie zalogował.
-- Ustawiane przy udanym POST /auth/login, zwracane jako `lastLoginAt` w DTO użytkownika.

-- @guard: column-missing users.last_login_at
ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL;

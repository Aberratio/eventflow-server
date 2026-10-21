-- R-10: login throttling. One row per throttled subject: `account` (sha256 of the normalised e-mail,
-- also for e-mails that do not exist, so an unknown address behaves exactly like a real one) or `ip`.
-- Times are unix seconds written by the application clock; locked_until > now means "blocked".
-- Old rows are deleted lazily by LoginThrottleRepository on every recorded failure.
CREATE TABLE IF NOT EXISTS login_throttle (
  scope VARCHAR(8) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  failures INT UNSIGNED NOT NULL DEFAULT 0,
  window_start BIGINT UNSIGNED NOT NULL DEFAULT 0,
  lockout_level INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (scope, key_hash),
  INDEX idx_login_throttle_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE events
  ADD COLUMN IF NOT EXISTS registration_open_date VARCHAR(64) NULL AFTER registration_deadline,
  ADD COLUMN IF NOT EXISTS registration_until_event_start TINYINT(1) NOT NULL DEFAULT 0 AFTER registration_open_date;

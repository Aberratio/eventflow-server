-- @guard: column-missing events.registration_open_date
ALTER TABLE events ADD COLUMN registration_open_date VARCHAR(64) NULL AFTER registration_deadline;
-- @guard: column-missing events.registration_until_event_start
ALTER TABLE events ADD COLUMN registration_until_event_start TINYINT(1) NOT NULL DEFAULT 0 AFTER registration_open_date;

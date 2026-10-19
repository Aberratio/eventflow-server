-- R-17 (umowa §5.9 / §4.4.13): integracja z Kalendarzem Google. Ustawienia (jeden wiersz), kolejka
-- wysyłek z ponowieniami i id wydarzenia Google zapamiętane na Wydarzeniu. Klucz konta usługi NIE
-- jest przechowywany w bazie (tylko plik wskazany zmienną GOOGLE_CALENDAR_SERVICE_ACCOUNT_FILE).
CREATE TABLE IF NOT EXISTS google_calendar_settings (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    calendar_id VARCHAR(255) NOT NULL DEFAULT '',
    time_zone VARCHAR(64) NOT NULL DEFAULT 'Europe/Warsaw',
    last_sync_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO google_calendar_settings (id, enabled, calendar_id, time_zone)
VALUES ('00000000-0000-0000-0000-000000000001', 0, '', 'Europe/Warsaw');

CREATE TABLE IF NOT EXISTS google_calendar_outbox (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    event_id VARCHAR(36) NOT NULL,
    status ENUM('queued','failed','sent','abandoned') NOT NULL DEFAULT 'queued',
    attempts INT NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    next_attempt_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    INDEX idx_gcal_outbox_due (status, next_attempt_at),
    INDEX idx_gcal_outbox_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @guard: column-missing events.google_calendar_event_id
ALTER TABLE events ADD COLUMN google_calendar_event_id VARCHAR(255) NULL;

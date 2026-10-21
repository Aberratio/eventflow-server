-- R-01 (spec 09 pkt 2-3, macierz uprawnien §2): Zespol Wydarzenia. Wlasciciel to nadal events.organizer_id
-- (bez duplikacji); ta tabela trzyma wylacznie Wspolzarzadzajacych - dowolne Konto, niezaleznie od roli
-- globalnej. Zakres P2, do potwierdzenia przez Zamawiajacego (umowa §5.10).
-- user_id jest NULL, dopoki zaproszony nie zaakceptuje zaproszenia (zaproszenie idzie na adres e-mail).
-- Token zaproszenia jest przechowywany wylacznie jako hash (surowy token trafia tylko do maila).
CREATE TABLE IF NOT EXISTS event_team_members (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    event_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NULL,
    role ENUM('co_manager') NOT NULL DEFAULT 'co_manager',
    status ENUM('invited','active','revoked') NOT NULL DEFAULT 'invited',
    invited_email VARCHAR(255) NOT NULL,
    invite_token_hash CHAR(64) NULL,
    invite_expires_at DATETIME NULL,
    invited_by VARCHAR(36) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at DATETIME NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_event_team_event_user (event_id, user_id),
    UNIQUE KEY uq_event_team_event_email (event_id, invited_email),
    UNIQUE KEY uq_event_team_token (invite_token_hash),
    INDEX idx_event_team_user_status (user_id, status),
    INDEX idx_event_team_event_status (event_id, status),
    CONSTRAINT fk_event_team_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_team_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

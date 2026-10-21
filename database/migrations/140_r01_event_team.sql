-- Event (Wydarzenie) Team. The owner is still events.organizer_id
-- (no duplication); this table holds only Co-managers - any Account, regardless of the
-- global role.
-- user_id is NULL until the invitee accepts the invitation (the invitation goes to an email address).
-- The invitation token is stored only as a hash (the raw token goes only into the email).
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

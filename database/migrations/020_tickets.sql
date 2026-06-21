CREATE TABLE IF NOT EXISTS tickets (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    registration_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    event_id VARCHAR(36) NOT NULL,
    event_name VARCHAR(255) NOT NULL DEFAULT '',
    distance_name VARCHAR(255) NOT NULL DEFAULT '',
    ticket_number VARCHAR(64) NOT NULL UNIQUE,
    qr_code VARCHAR(512) DEFAULT NULL,
    status ENUM('active','used','cancelled') NOT NULL DEFAULT 'active',
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tickets_registration (registration_id),
    INDEX idx_tickets_user (user_id),
    INDEX idx_tickets_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

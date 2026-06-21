CREATE TABLE IF NOT EXISTS certificates (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    result_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    event_id VARCHAR(36) NOT NULL,
    event_name VARCHAR(255) NOT NULL DEFAULT '',
    distance_name VARCHAR(255) NOT NULL DEFAULT '',
    certificate_number VARCHAR(64) NOT NULL UNIQUE,
    pdf_url VARCHAR(512) DEFAULT NULL,
    finish_time VARCHAR(32) DEFAULT NULL,
    position INT DEFAULT NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_certs_result (result_id),
    INDEX idx_certs_user (user_id),
    INDEX idx_certs_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

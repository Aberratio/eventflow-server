CREATE TABLE IF NOT EXISTS event_documents (
  id VARCHAR(36) PRIMARY KEY,
  event_id VARCHAR(36) NOT NULL,
  name VARCHAR(255) NOT NULL,
  type ENUM('regulamin','harmonogram','mapa','informacje','wyniki','inne') NOT NULL DEFAULT 'inne',
  file_url VARCHAR(512) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_event_documents_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

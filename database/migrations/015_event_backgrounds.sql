CREATE TABLE IF NOT EXISTS event_backgrounds (
  id VARCHAR(36) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  image_url VARCHAR(512) NOT NULL,
  thumbnail_url VARCHAR(512) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @guard: column-missing events.background_id
ALTER TABLE events ADD COLUMN background_id VARCHAR(36) NULL;
-- @guard: foreign-key-missing events.fk_events_background
ALTER TABLE events ADD CONSTRAINT fk_events_background FOREIGN KEY (background_id) REFERENCES event_backgrounds(id) ON DELETE SET NULL;

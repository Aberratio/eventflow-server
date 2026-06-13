ALTER TABLE events
  ADD COLUMN IF NOT EXISTS organizer_logo_shape ENUM('square','portrait','landscape') NOT NULL DEFAULT 'square' AFTER organizer_logo_url;

-- @guard: column-missing events.organizer_logo_shape
ALTER TABLE events ADD COLUMN organizer_logo_shape ENUM('square','portrait','landscape') NOT NULL DEFAULT 'square' AFTER organizer_logo_url;

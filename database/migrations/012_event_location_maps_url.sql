ALTER TABLE events
  ADD COLUMN IF NOT EXISTS location_maps_url VARCHAR(512) NULL AFTER location;

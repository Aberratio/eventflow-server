-- @guard: column-missing events.location_maps_url
ALTER TABLE events ADD COLUMN location_maps_url VARCHAR(512) NULL AFTER location;

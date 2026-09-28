-- @guard: column-missing events.original_image_url
ALTER TABLE events ADD COLUMN original_image_url VARCHAR(512) NULL AFTER image_url;
-- @guard: column-missing events.original_banner_url
ALTER TABLE events ADD COLUMN original_banner_url VARCHAR(512) NULL AFTER banner_url;
-- @guard: column-missing events.original_organizer_logo_url
ALTER TABLE events ADD COLUMN original_organizer_logo_url VARCHAR(512) NULL AFTER organizer_logo_url;

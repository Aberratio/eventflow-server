ALTER TABLE events
  ADD COLUMN IF NOT EXISTS original_image_url VARCHAR(512) NULL AFTER image_url,
  ADD COLUMN IF NOT EXISTS original_banner_url VARCHAR(512) NULL AFTER banner_url,
  ADD COLUMN IF NOT EXISTS original_organizer_logo_url VARCHAR(512) NULL AFTER organizer_logo_url;

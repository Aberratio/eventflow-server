ALTER TABLE events
  ADD COLUMN IF NOT EXISTS logistics_info JSON NULL AFTER display_options;

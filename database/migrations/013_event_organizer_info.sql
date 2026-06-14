ALTER TABLE events
  ADD COLUMN IF NOT EXISTS organizer_info JSON NULL AFTER logistics_info;

-- @guard: column-missing events.organizer_info
ALTER TABLE events ADD COLUMN organizer_info JSON NULL AFTER logistics_info;

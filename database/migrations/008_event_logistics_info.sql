-- @guard: column-missing events.logistics_info
ALTER TABLE events ADD COLUMN logistics_info JSON NULL AFTER display_options;

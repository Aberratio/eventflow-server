-- @guard: column-missing event_distances.info_html
ALTER TABLE event_distances ADD COLUMN info_html TEXT NULL AFTER office_price;

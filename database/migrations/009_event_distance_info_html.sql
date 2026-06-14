ALTER TABLE event_distances
  ADD COLUMN IF NOT EXISTS info_html TEXT NULL AFTER office_price;

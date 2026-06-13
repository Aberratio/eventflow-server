ALTER TABLE event_distances
  ADD COLUMN IF NOT EXISTS office_price DECIMAL(10,2) NULL AFTER price;

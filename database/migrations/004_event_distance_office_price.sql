-- @guard: column-missing event_distances.office_price
ALTER TABLE event_distances ADD COLUMN office_price DECIMAL(10,2) NULL AFTER price;

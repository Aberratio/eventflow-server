-- @guard: column-missing users.birth_date
ALTER TABLE users ADD COLUMN birth_date VARCHAR(16) NULL AFTER birth_year;
-- @guard: column-missing users.address
ALTER TABLE users ADD COLUMN address JSON NULL AFTER birth_date;
-- @guard: column-missing users.sos_contacts
ALTER TABLE users ADD COLUMN sos_contacts JSON NULL AFTER address;

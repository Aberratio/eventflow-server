-- @guard: column-missing partners.logo_dark_url
ALTER TABLE partners ADD COLUMN logo_dark_url VARCHAR(512) NULL AFTER logo_url;

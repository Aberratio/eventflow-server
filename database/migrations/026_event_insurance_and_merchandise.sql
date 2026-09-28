-- The frontend has always sent these three fields in its event payloads and reads them
-- back when rendering the registration form, but the API mapped none of them, so they
-- were silently dropped on every save. `organizers_html` already had a column and was
-- simply never written to.
-- @guard: column-missing events.insurance_option
ALTER TABLE events ADD COLUMN insurance_option JSON NULL AFTER rules_html;
-- @guard: column-missing events.enable_merchandise_in_registration
ALTER TABLE events ADD COLUMN enable_merchandise_in_registration TINYINT(1) NOT NULL DEFAULT 0 AFTER insurance_option;

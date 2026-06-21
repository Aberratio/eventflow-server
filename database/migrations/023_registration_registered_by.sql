ALTER TABLE registrations
  ADD COLUMN IF NOT EXISTS registered_by_id VARCHAR(36) NULL COMMENT 'Upoważniony, który dokonał zapisu w imieniu uczestnika';

ALTER TABLE registrations
  ADD INDEX IF NOT EXISTS idx_registrations_registered_by (registered_by_id);

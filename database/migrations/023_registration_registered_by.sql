-- @guard: column-missing registrations.registered_by_id
ALTER TABLE registrations ADD COLUMN registered_by_id VARCHAR(36) NULL COMMENT 'Upoważniony, który dokonał zapisu w imieniu uczestnika';

-- @guard: index-missing registrations.idx_registrations_registered_by
ALTER TABLE registrations ADD INDEX idx_registrations_registered_by (registered_by_id);

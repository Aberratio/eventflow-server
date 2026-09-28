-- @guard: column-missing event_distances.start_type
ALTER TABLE event_distances ADD COLUMN start_type ENUM('individual','relay') NOT NULL DEFAULT 'individual' AFTER categories;
-- @guard: column-missing event_distances.relay_min_team_size
ALTER TABLE event_distances ADD COLUMN relay_min_team_size INT NULL AFTER start_type;
-- @guard: column-missing event_distances.relay_max_team_size
ALTER TABLE event_distances ADD COLUMN relay_max_team_size INT NULL AFTER relay_min_team_size;
-- @guard: column-missing event_distances.relay_team_completion_deadline
ALTER TABLE event_distances ADD COLUMN relay_team_completion_deadline DATE NULL AFTER relay_max_team_size;

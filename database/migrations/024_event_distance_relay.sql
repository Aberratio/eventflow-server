ALTER TABLE event_distances
  ADD COLUMN IF NOT EXISTS start_type ENUM('individual','relay') NOT NULL DEFAULT 'individual' AFTER categories,
  ADD COLUMN IF NOT EXISTS relay_min_team_size INT NULL AFTER start_type,
  ADD COLUMN IF NOT EXISTS relay_max_team_size INT NULL AFTER relay_min_team_size,
  ADD COLUMN IF NOT EXISTS relay_team_completion_deadline DATE NULL AFTER relay_max_team_size;

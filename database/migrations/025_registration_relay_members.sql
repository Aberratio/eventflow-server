ALTER TABLE registrations
  ADD COLUMN IF NOT EXISTS relay_members JSON NULL AFTER team_id;

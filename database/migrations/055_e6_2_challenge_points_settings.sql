-- E6.2: global, admin-configurable "points per place" table (issue 15 §2,
-- "Punktacja: za miejsce, N najlepszych startow").
--
-- Shape choice: a SINGLE settings row with a JSON array column, matching the
-- ORIGINAL insurance_settings shape (018_insurance_settings.sql) before it
-- grew into a catalog (E1.2) - not that later catalog shape, because points-
-- per-place is genuinely one global ordered list (spec: "system punktowy
-- malejacy, konfigurowalny globalnie"), not a set of named variants an
-- organizer picks between. A JSON array (not one row per place in a
-- child table) keeps "shift place 3 from 90 to 92" a single UPDATE instead of
-- a multi-row renumber, and the whole table is always read/written together
-- (ChallengePointsSettingsRepository::getSettings()/updateSettings()), the
-- same access pattern payment_config (001_init.sql, E1.4/E1.7) already
-- established for "one JSON blob describing a whole configurable policy".
--
-- points_table[i] = points for place (i+1). floor_points is the constant
-- awarded for any place beyond the table's length (spec: "system punktowy
-- malejacy ... np. 1. miejsce = 100 pkt, 2. = 95 itd." - the table doesn't
-- have to reach the back of the field, so a floor is needed rather than 0
-- dropping off a cliff or the table needing one row per possible finisher).
--
-- Seed: 20 places, 100 down to 5 in steps of 5 (the spec's own example
-- extended to a plausible mountain-running-style length), floor 0 beyond
-- that - an admin can reshape both freely via PATCH /challenge-points-settings.
--
-- Migrations have no ledger and re-run on every boot (see Migrator.php) -
-- CREATE TABLE IF NOT EXISTS + INSERT IGNORE on a fixed id is this project's
-- established idempotent-singleton pattern (insurance_settings mirrors it).
CREATE TABLE IF NOT EXISTS challenge_points_settings (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  points_table JSON NOT NULL,
  floor_points INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO challenge_points_settings (id, points_table, floor_points)
VALUES (
  '00000000-0000-0000-0000-000000000002',
  '[100,95,90,85,80,75,70,65,60,55,50,45,40,35,30,25,20,15,10,5]',
  0
);

-- E7.1: Cykl życia Drużyny — formalizuje registrations.team_id/team_name jako
-- prawdziwy byt zamiast wolnego, niewalidowanego tekstu.
--
-- Rozstrzygnięcie: zc-second-brain/specyfikacje/zmierzymy-czas/issues/23-druzyna-sztafeta-sklad-i-wymiana.md
-- Answer §1 ("Drużyna niepełna jest legalnym stanem; limit liczy Zapisy") —
-- Drużyna z N Zapisami na M miejsc zajmuje dokładnie N miejsc w limicie
-- Dystansu (event_distances.registered_count, E1.3, pozostaje bez zmian).
-- Kompletność Drużyny jest stanem WYLICZANYM z liczby powiązanych Zapisów
-- (RegistrationRepository/TeamRepository), nigdy osobnym polem tu.
--
-- `special_category` zarezerwowana pod E7.5 (kategoria specjalna Drużyny,
-- Answer §7) — nullable, nieużywana w tym tickecie, tylko miejsce na później.
--
-- `team_name` na `registrations` zostaje jako zdenormalizowana kopia etykiety
-- (ten sam wzorzec co reszta tej tabeli, np. event_name/distance_name) — czyta
-- ją bez zmian ResultRepository::teamClassification() (E2.7) oraz publiczna
-- lista startowa, obie z zapytaniami wyłącznie po `registrations.team_id`/
-- `team_name`, bez joina do nowej tabeli.
CREATE TABLE IF NOT EXISTS teams (
  id VARCHAR(36) PRIMARY KEY,
  event_id VARCHAR(36) NOT NULL,
  distance_id VARCHAR(36) NOT NULL,
  name VARCHAR(180) NOT NULL,
  created_by_user_id VARCHAR(36) NULL,
  special_category VARCHAR(64) NULL,
  created_at VARCHAR(64) NOT NULL,
  CONSTRAINT fk_teams_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_teams_distance FOREIGN KEY (distance_id) REFERENCES event_distances(id) ON DELETE CASCADE,
  INDEX idx_teams_distance (distance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- `registrations.team_id` was free text (any string a client sent —
-- RegistrationRepository::create() passed it through verbatim, zero
-- validation). None of it can satisfy the new FK below, so it's cleared here
-- before the constraint is added — `team_name` (the display label actually
-- read by teamClassification()/the public start list) is left untouched,
-- the same "denormalized copy survives even if the structured link resets"
-- shape this table already has elsewhere (e.g. a removed discount still
-- keeps its frozen settlement line). Idempotent: once cleared/real, this is
-- a no-op on every later re-run of this file (see Migrator's docblock).
UPDATE registrations r
LEFT JOIN teams t ON t.id = r.team_id
SET r.team_id = NULL
WHERE r.team_id IS NOT NULL AND t.id IS NULL;

-- @guard: foreign-key-missing registrations.fk_registrations_team
ALTER TABLE registrations ADD CONSTRAINT fk_registrations_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL;

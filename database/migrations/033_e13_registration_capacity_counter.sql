-- E1.3: Materializowany licznik limitu miejsc/progu cenowego, aktualizowany
-- transakcyjnie przy każdym Zapisie.
--
-- Rozstrzygnięcie: zc-second-brain/specyfikacje/zmierzymy-czas/issues/21-zestawienia-i-eksporty-organizatora.md
-- sekcja 5 ("Źródło danych: live query, materializacja tylko dla limitu") oraz
-- issues/07-model-cenowy-zapisu.md rozstrzygnięcie 4 ("Licznik progu liczy zapisy
-- nieanulowane — tę samą populację co limit miejsc na Dystansie").
--
-- `event_distances.registered_count` (001_init.sql) already exists as a column,
-- but nothing has ever written to it transactionally — it was only ever set by
-- the demo/test seeders. RegistrationRepository::pricingForRegistration() has
-- been reading it for price-tier resolution since E1.2, so it was silently stale
-- for every event that received real (non-seeded) registrations. This migration
-- backfills it from the actual current population (pending + confirmed
-- registrations — same predicate the rest of the codebase already uses for
-- "not cancelled") before RegistrationRepository starts maintaining it
-- transactionally on every create()/status change going forward.

UPDATE event_distances d
SET d.registered_count = (
  SELECT COUNT(*)
  FROM registrations r
  WHERE r.distance_id = d.id
    AND r.status NOT IN ('removed', 'cancelled')
);

-- Read-modify-write on this column now happens inside the same transaction as
-- registrations INSERT/UPDATE (row lock via `SELECT ... FOR UPDATE` on
-- event_distances, plus an affected-rows-guarded increment) — an index on the
-- population predicate keeps the one-time backfill above and any future manual
-- reconciliation query fast on large registrations tables.
-- @guard: index-missing registrations.idx_registrations_distance_status
ALTER TABLE registrations ADD INDEX idx_registrations_distance_status (distance_id, status);

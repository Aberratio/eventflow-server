-- R-06, faza 2: klucze unikalne po Zawodniku. Opiekun może zapisać na to samo
-- Wyzwanie siebie i kilku podopiecznych (ten sam user_id, różni Zawodnicy), więc
-- dotychczasowe UNIQUE po user_id musi ustąpić UNIQUE po competitor_id. user_id NIE
-- jest usuwany; wiersze z competitor_id NULL (Konto bez profilu Zawodnika) nie podlegają
-- unikalności - pilnuje ich kod (join/submitEntry szukają wtedy po user_id).
--
-- Nowy klucz powstaje tylko wtedy, gdy po backfillu 120 nie ma duplikatów
-- (competitors.user_id jest UNIQUE, więc z własnych wierszy duplikat nie powstaje;
-- strażnik chroni przed ręcznie dopisanymi danymi). Stary klucz spada dopiero,
-- gdy nowy istnieje, a zamiast niego zostaje zwykły indeks pod wyszukiwanie.

-- @guard: index-missing challenge_participations.uniq_challenge_competitor
SET @r06_sql = IF((SELECT COUNT(*) FROM (SELECT 1 FROM challenge_participations WHERE competitor_id IS NOT NULL GROUP BY challenge_id, competitor_id HAVING COUNT(*) > 1) d) = 0, 'ALTER TABLE challenge_participations ADD UNIQUE INDEX uniq_challenge_competitor (challenge_id, competitor_id)', 'SELECT 1');
-- @guard: index-missing challenge_participations.uniq_challenge_competitor
PREPARE r06_stmt FROM @r06_sql;
-- @guard: index-missing challenge_participations.uniq_challenge_competitor
EXECUTE r06_stmt;
-- @guard: index-missing challenge_participations.uniq_challenge_competitor
DEALLOCATE PREPARE r06_stmt;

-- @guard: index-missing challenge_entries.uniq_challenge_entry_competitor_event
SET @r06_sql = IF((SELECT COUNT(*) FROM (SELECT 1 FROM challenge_entries WHERE competitor_id IS NOT NULL GROUP BY challenge_id, competitor_id, event_id HAVING COUNT(*) > 1) d) = 0, 'ALTER TABLE challenge_entries ADD UNIQUE INDEX uniq_challenge_entry_competitor_event (challenge_id, competitor_id, event_id)', 'SELECT 1');
-- @guard: index-missing challenge_entries.uniq_challenge_entry_competitor_event
PREPARE r06_stmt FROM @r06_sql;
-- @guard: index-missing challenge_entries.uniq_challenge_entry_competitor_event
EXECUTE r06_stmt;
-- @guard: index-missing challenge_entries.uniq_challenge_entry_competitor_event
DEALLOCATE PREPARE r06_stmt;

-- Stary klucz po user_id spada tylko, gdy nowy faktycznie powstał (albo już był).
-- @guard: index-exists challenge_participations.uniq_challenge_participant
SET @r06_sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'challenge_participations' AND index_name = 'uniq_challenge_competitor') > 0, 'ALTER TABLE challenge_participations DROP INDEX uniq_challenge_participant, ADD INDEX idx_challenge_participations_challenge_user (challenge_id, user_id)', 'SELECT 1');
-- @guard: index-exists challenge_participations.uniq_challenge_participant
PREPARE r06_stmt FROM @r06_sql;
-- @guard: index-exists challenge_participations.uniq_challenge_participant
EXECUTE r06_stmt;
-- @guard: index-exists challenge_participations.uniq_challenge_participant
DEALLOCATE PREPARE r06_stmt;

-- @guard: index-exists challenge_entries.uniq_challenge_entry_event
SET @r06_sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'challenge_entries' AND index_name = 'uniq_challenge_entry_competitor_event') > 0, 'ALTER TABLE challenge_entries DROP INDEX uniq_challenge_entry_event, ADD INDEX idx_challenge_entries_challenge_user_event (challenge_id, user_id, event_id)', 'SELECT 1');
-- @guard: index-exists challenge_entries.uniq_challenge_entry_event
PREPARE r06_stmt FROM @r06_sql;
-- @guard: index-exists challenge_entries.uniq_challenge_entry_event
EXECUTE r06_stmt;
-- @guard: index-exists challenge_entries.uniq_challenge_entry_event
DEALLOCATE PREPARE r06_stmt;

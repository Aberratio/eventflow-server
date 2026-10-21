-- Phase 2: unique keys per Competitor. A guardian can register themselves and several wards
-- for the same Challenge (same user_id, different Competitors), so
-- the existing UNIQUE on user_id must give way to UNIQUE on competitor_id. user_id is NOT
-- removed; rows with competitor_id NULL (an Account without a Competitor profile) are not subject to
-- uniqueness - code guards them (join/submitEntry then look up by user_id).
--
-- The new key is created only if, after the backfill in 120, there are no duplicates
-- (competitors.user_id is UNIQUE, so own rows cannot produce a duplicate;
-- the guard protects against manually inserted data). The old key is dropped only
-- once the new one exists, and a plain lookup index stays in its place.

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

-- The old key on user_id is dropped only if the new one was actually created (or already existed).
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

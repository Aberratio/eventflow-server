-- Participation in a Challenge (Wyzwanie)
-- belongs to the Competitor (Zawodnik), not the Account. Phase 1 (this migration):
-- nullable `competitor_id` on participations and entries + backfill with the Account's own
-- Competitor profile (competitors.user_id). `user_id` stays (it is the Account that
-- registered / will get the email) - it is not removed. A row without a Competitor profile stays
-- NULL and is read by user_id (fallback in code).
-- @guard: column-missing challenge_participations.competitor_id
ALTER TABLE challenge_participations ADD COLUMN competitor_id VARCHAR(36) NULL AFTER user_id;
-- @guard: column-missing challenge_entries.competitor_id
ALTER TABLE challenge_entries ADD COLUMN competitor_id VARCHAR(36) NULL AFTER user_id;

-- @guard: index-missing challenge_participations.idx_challenge_participations_competitor
ALTER TABLE challenge_participations ADD INDEX idx_challenge_participations_competitor (competitor_id);
-- @guard: index-missing challenge_entries.idx_challenge_entries_competitor
ALTER TABLE challenge_entries ADD INDEX idx_challenge_entries_competitor (competitor_id);

-- ON DELETE SET NULL: Competitors are not deleted (merging = a status), and if it
-- ever happened, the row falls back to being read by user_id instead of vanishing with the progress.
-- @guard: foreign-key-missing challenge_participations.fk_challenge_participations_competitor
ALTER TABLE challenge_participations
  ADD CONSTRAINT fk_challenge_participations_competitor
    FOREIGN KEY (competitor_id) REFERENCES competitors (id) ON DELETE SET NULL;
-- @guard: foreign-key-missing challenge_entries.fk_challenge_entries_competitor
ALTER TABLE challenge_entries
  ADD CONSTRAINT fk_challenge_entries_competitor
    FOREIGN KEY (competitor_id) REFERENCES competitors (id) ON DELETE SET NULL;

-- Backfill (idempotent: touches only rows with NULL).
UPDATE challenge_participations cp
INNER JOIN competitors c ON c.user_id = cp.user_id
SET cp.competitor_id = c.id
WHERE cp.competitor_id IS NULL;

UPDATE challenge_entries ce
INNER JOIN competitors c ON c.user_id = ce.user_id
SET ce.competitor_id = c.id
WHERE ce.competitor_id IS NULL;

-- E6.1: condition tree for Wyzwanie completion rules (issue 15 §1, "Reguła
-- ukończenia: drzewo warunków, nie jeden płaski próg").
--
-- ADDITIVE, not a rewrite: `challenges`, `challenge_events`,
-- `challenge_participations`, `challenge_entries` and `challenge_products`
-- (001_init.sql) keep their exact shape and behaviour — the flat
-- `min_completed`/`challenge_events` model they implement is now understood
-- as "the default single-node tree" (see backfill below), not replaced.
-- ChallengeRepository's existing read/write paths for those tables are
-- untouched by this migration.
--
-- Every Wyzwanie gets exactly one root row in `challenge_condition_nodes`
-- (parent_node_id IS NULL). Three leaf node types plus one grouping type
-- cover the spec's "trzy typy węzła + drzewo AND/próg":
--   - event_threshold          ukończ >= N z M wskazanych Wydarzeń
--   - point_threshold          zbierz >= X punktów z klasyfikacji Wydarzeń
--                              (scoring engine itself is E6.2 — only the
--                              structural X / "N best results" config lives
--                              here)
--   - sub_challenge_threshold  ukończ >= N z M wskazanych Wyzwań
--   - group                    a pure composition node: "required_count of
--                              its children must be satisfied". AND is just
--                              the case required_count = COUNT(children); a
--                              separate "threshold" node type would have
--                              been a duplicate of the same N-of-M shape the
--                              three leaf types already carry, so "AND/próg"
--                              is modelled as one mechanism (required_count
--                              vs. child/reference count), not two.
--
-- Leaf nodes carry their OWN linked event/sub-challenge sets
-- (challenge_condition_node_events / challenge_condition_node_sub_challenges)
-- rather than reusing `challenge_events` — a tree can have more than one
-- event_threshold node at different levels (e.g. "ukończ 3 z 5 Biegów
-- wiosennych" AND "ukończ 2 z 3 Biegów jesiennych"), each needing its own
-- subset. `challenge_events` keeps meaning exactly what it means today: the
-- whole-challenge event list used by the legacy flat endpoints, by
-- syncResultEntries()'s auto-approval join, and by anything not yet on the
-- tree (E6.2/E6.3 scoring and auto-verification land on the tree separately).
--
-- Cycle prevention (a sub-challenge's tree can't reference back up to an
-- ancestor) is enforced in ChallengeRepository (app-level graph walk), not
-- here — SQL foreign keys can guarantee "the referenced challenge already
-- exists" but not "no cycle", and the spec's own invariant ("referencja idzie
-- tylko w dół") makes the walk a straightforward finite check.
CREATE TABLE IF NOT EXISTS challenge_condition_nodes (
  id VARCHAR(36) PRIMARY KEY,
  challenge_id VARCHAR(36) NOT NULL,
  parent_node_id VARCHAR(36) NULL,
  node_type ENUM('group','event_threshold','point_threshold','sub_challenge_threshold') NOT NULL,
  required_count INT NULL,
  points_required INT NULL,
  best_results_count INT NULL,
  window_start VARCHAR(64) NULL,
  window_end VARCHAR(64) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ccn_challenge (challenge_id),
  INDEX idx_ccn_parent (parent_node_id),
  CONSTRAINT fk_ccn_challenge FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE CASCADE,
  CONSTRAINT fk_ccn_parent FOREIGN KEY (parent_node_id) REFERENCES challenge_condition_nodes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS challenge_condition_node_events (
  node_id VARCHAR(36) NOT NULL,
  event_id VARCHAR(36) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (node_id, event_id),
  INDEX idx_ccne_node (node_id),
  INDEX idx_ccne_event (event_id),
  CONSTRAINT fk_ccne_node FOREIGN KEY (node_id) REFERENCES challenge_condition_nodes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS challenge_condition_node_sub_challenges (
  node_id VARCHAR(36) NOT NULL,
  sub_challenge_id VARCHAR(36) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (node_id, sub_challenge_id),
  INDEX idx_ccnsc_node (node_id),
  INDEX idx_ccnsc_sub (sub_challenge_id),
  CONSTRAINT fk_ccnsc_node FOREIGN KEY (node_id) REFERENCES challenge_condition_nodes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ccnsc_sub FOREIGN KEY (sub_challenge_id) REFERENCES challenges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: a challenge with no condition-tree node AT ALL gets a single root
-- `event_threshold` node mirroring its current `challenge_events` (N = M =
-- linked count, i.e. "ukończ wszystkie", what the flat model implicitly meant
-- before this migration). The invariant this keeps (PP.13): a challenge with no
-- nodes gets the default tree exactly once; an app-created tree (ChallengeRepository
-- gives its root a fresh UUID, not the challenge's own id) is left alone on every
-- re-run. See ChallengeConditionTreeBackfill's own docblock for the exact SQL and
-- why it runs unconditionally from Migrator::applyAll(), not only when this file
-- itself (re-)applies through the ledger: seeders/fixtures insert `challenges` rows
-- directly, after migrations (and the ledger) have already run.
-- @php: ChallengeConditionTreeBackfill::run

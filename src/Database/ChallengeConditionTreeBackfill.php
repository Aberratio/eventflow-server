<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;

/**
 * Backfill for migration 054: every challenge with no condition-tree node AT
 * ALL gets a single root `event_threshold` node mirroring its current
 * `challenge_events` (N = M = linked count, i.e. "complete all", what the flat
 * model implicitly meant before that migration).
 *
 * This needs to run on every `Migrator::applyAll()` call, not only when 054
 * itself (re-)applies through the ledger — it is an ongoing catch-up for challenges
 * created outside the app's own tree-building path (`ChallengeRepository::create()`
 * always gives its root node a fresh UUID and populates node_events itself, so an
 * app-created challenge never needs this). Seeders and fixture imports insert
 * `challenges`/`challenge_events` rows directly by SQL, after migrations have already
 * run and 054 is recorded in the ledger — exactly the shape of gap
 * {@see CompetitorProfileBackfill} and {@see CustomFormFieldsBackfill} exist to close
 * for their own tables. Idempotent: `WHERE NOT EXISTS (... n.challenge_id = c.id)`
 * checks for ANY node belonging to the challenge, so a re-run never adds a second
 * root next to an app-created one, and the second INSERT only ever targets a root
 * this same backfill created (`n.id = c.id`). `challenge_events` rows with no
 * matching `challenges` row (orphans — no FK ties them together, by design) are
 * simply not selected.
 */
final class ChallengeConditionTreeBackfill
{
    public static function run(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO challenge_condition_nodes (id, challenge_id, parent_node_id, node_type, required_count, sort_order)
             SELECT c.id, c.id, NULL, 'event_threshold', (SELECT COUNT(*) FROM challenge_events ce WHERE ce.challenge_id = c.id), 0
             FROM challenges c
             WHERE NOT EXISTS (SELECT 1 FROM challenge_condition_nodes n WHERE n.challenge_id = c.id)"
        );

        $pdo->exec(
            "INSERT INTO challenge_condition_node_events (node_id, event_id, sort_order)
             SELECT n.id, ce.event_id, ce.sort_order
             FROM challenge_events ce
             INNER JOIN challenge_condition_nodes n
               ON n.id = ce.challenge_id AND n.challenge_id = ce.challenge_id
              AND n.parent_node_id IS NULL AND n.node_type = 'event_threshold'
             WHERE NOT EXISTS (
               SELECT 1 FROM challenge_condition_node_events cne WHERE cne.node_id = n.id AND cne.event_id = ce.event_id
             )"
        );
    }
}

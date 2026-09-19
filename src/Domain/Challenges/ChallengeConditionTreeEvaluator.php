<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Challenges;

use PDO;

/**
 * E6.3 (issue 15 §3, "Weryfikacja: automat liczy, ścieżka manualna dla
 * dowodu"): the full recursive evaluator over a Wyzwanie's condition tree
 * (E6.1's `challenge_condition_nodes`) for ONE competitor — "czy ten
 * zawodnik ukończył to Wyzwanie" (root satisfied) rather than E6.2's narrower
 * "score this one point_threshold node".
 *
 * Node types (E6.1 §1):
 * - `event_threshold`      >= requiredCount of the linked Events have a
 *                           `finished` result for this competitor (optional
 *                           windowStart/windowEnd filters by the Event's own
 *                           `date`).
 * - `point_threshold`      delegates entirely to ChallengePointThresholdScorer
 *                           (E6.2) — this class does not reimplement scoring.
 *                           E6.2 deliberately does not apply a time window to
 *                           this node type (its own class doc comment scopes
 *                           it to points + "N best results" only), and this
 *                           ticket does not expand that scope either — a
 *                           `windowStart`/`windowEnd` on a `point_threshold`
 *                           node is hydrated (schema-wise it CAN carry one,
 *                           see ChallengeRepository::hydrateNode()) but is
 *                           silently ignored by both E6.2's scorer and this
 *                           evaluator. Flagged as a known gap, not fixed here.
 * - `sub_challenge_threshold` recursively evaluates whether the competitor
 *                           has completed >= requiredCount of the referenced
 *                           sub-Wyzwania's own root nodes (see
 *                           isChallengeCompletedBy()). Time-window
 *                           interpretation is a judgment call — see
 *                           subChallengeCompletionTimestamp()'s doc comment.
 * - `group`                >= requiredCount of its children are satisfied
 *                           (AND is simply requiredCount === count(children),
 *                           per E6.1's own doc comment on the migration).
 *
 * Memoization: a single top-level evaluation call threads one `$memo` array
 * (challengeId|userId -> bool) through every recursive call, so a tree that
 * references the same sub-challenge from two sibling `sub_challenge_threshold`
 * nodes (or the same challenge is asked about twice in one pass) evaluates it
 * exactly once. The memo is caller-owned (start a fresh `[]` per competitor
 * per evaluation) — nothing here caches across calls or competitors, which
 * would risk staleness after a result import; that's deliberately out of
 * scope per the ticket ("don't over-engineer a caching layer").
 *
 * Depth guard: `assertValidTree()` (ChallengeRepository, E6.1) already
 * rejects cycles at save time via a real graph walk, so this guard should
 * never trip in practice. It exists purely as defense-in-depth against a
 * future bug in that check causing infinite recursion — a RuntimeException
 * is thrown rather than silently returning false, so a real bug surfaces
 * loudly (in logs, via the caller's catch) instead of masquerading as "not
 * completed".
 */
final class ChallengeConditionTreeEvaluator
{
    /**
     * Generous on purpose: a legitimate tree (group-of-groups nesting
     * sub_challenge_threshold references several levels deep, per the
     * spec's "3 brązowe = 1 srebrna, 3 srebrne = 1 złota" example) is at
     * most a handful of levels. 30 is far beyond any real tree while still
     * catching a runaway recursion quickly.
     */
    private const MAX_DEPTH = 30;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ChallengeRepository $challenges,
        private readonly ChallengePointThresholdScorer $pointScorer,
    ) {
    }

    /**
     * Whether $userId has completed $challengeId — loads that challenge's own
     * root condition node and evaluates it. This is the entry point
     * `sub_challenge_threshold` nodes recurse through, and is also what
     * ChallengeRepository calls for the top-level "is this Wyzwanie
     * completed" check.
     *
     * @param array<string, bool> $memo keyed "challengeId|userId"
     */
    public function isChallengeCompletedBy(string $challengeId, string $userId, array &$memo = [], int $depth = 0): bool
    {
        $this->guardDepth($depth);
        $key = $challengeId . '|' . $userId;
        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }
        // Own guard against a self-cycle degenerately slipping through:
        // mark "false" before recursing so a cycle that somehow reaches back
        // here resolves to false on the repeat visit instead of looping.
        $memo[$key] = false;
        $tree = $this->challenges->getConditionTree($challengeId);
        $result = $tree === null ? false : $this->evaluateNode($tree, $userId, $memo, $depth);
        $memo[$key] = $result;
        return $result;
    }

    /**
     * Evaluates a single already-hydrated node (ChallengeRepository::hydrateNode()
     * shape). Exposed publicly (not just via isChallengeCompletedBy()) so a
     * caller that already has the tree in hand (e.g. ChallengeRepository::find()'s
     * embedded `conditionTree`) can evaluate it without a redundant
     * getConditionTree() round-trip.
     *
     * @param array<string, mixed> $node
     * @param array<string, bool> $memo
     */
    public function evaluateNode(array $node, string $userId, array &$memo, int $depth = 0): bool
    {
        $this->guardDepth($depth);

        return match ($node['type'] ?? null) {
            'event_threshold' => $this->evaluateEventThreshold($node, $userId),
            'point_threshold' => $this->pointScorer->score($node, $userId)['satisfied'],
            'sub_challenge_threshold' => $this->evaluateSubChallengeThreshold($node, $userId, $memo, $depth),
            'group' => $this->evaluateGroup($node, $userId, $memo, $depth),
            default => false,
        };
    }

    private function guardDepth(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException(
                "ChallengeConditionTreeEvaluator: recursion depth guard tripped at depth {$depth} " .
                '(> ' . self::MAX_DEPTH . ') — assertValidTree() should make this unreachable; ' .
                'treat as a bug in cycle prevention, not a real result.'
            );
        }
    }

    /** @param array<string, mixed> $node */
    private function evaluateEventThreshold(array $node, string $userId): bool
    {
        $required = max(0, (int) ($node['requiredCount'] ?? 0));
        $eventIds = array_values(array_unique(array_map(
            static fn (array $e): string => (string) $e['eventId'],
            array_filter((array) ($node['events'] ?? []), 'is_array')
        )));
        if ($eventIds === []) {
            return $required <= 0;
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $sql = "SELECT COUNT(DISTINCT r.event_id) FROM results r
                INNER JOIN events e ON e.id = r.event_id
                WHERE r.user_id = ? AND r.status = 'finished' AND r.event_id IN ({$placeholders})";
        $params = [$userId, ...$eventIds];

        $windowStart = $node['windowStart'] ?? null;
        $windowEnd = $node['windowEnd'] ?? null;
        if ($windowStart !== null && $windowStart !== '') {
            $sql .= ' AND e.date >= ?';
            $params[] = $windowStart;
        }
        if ($windowEnd !== null && $windowEnd !== '') {
            $sql .= ' AND e.date <= ?';
            $params[] = $windowEnd;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $count = (int) $stmt->fetchColumn();

        return $count >= $required;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool> $memo
     */
    private function evaluateGroup(array $node, string $userId, array &$memo, int $depth): bool
    {
        $children = array_values(array_filter((array) ($node['children'] ?? []), 'is_array'));
        if ($children === []) {
            return false;
        }
        $required = array_key_exists('requiredCount', $node) && $node['requiredCount'] !== null
            ? max(0, (int) $node['requiredCount'])
            : count($children); // no explicit threshold -> plain AND over all children

        $satisfiedCount = 0;
        foreach ($children as $child) {
            if ($this->evaluateNode($child, $userId, $memo, $depth + 1)) {
                $satisfiedCount++;
            }
        }

        return $satisfiedCount >= $required;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool> $memo
     *
     * Time-window interpretation (issue 15 §1 leaves this to judgment —
     * "prawdopodobnie zakończenie ... uczestnictwa, jeśli istnieje, albo
     * wyprowadzone z leżących pod spodem zgłoszeń"): a sub-challenge's own
     * "completion moment" for window purposes is, in priority order:
     *   1. its `challenge_participations.completion_notified_at` for this
     *      competitor — the same materialized moment E1.10's mail #13 uses,
     *      the most authoritative "when they finished this Wyzwanie" the
     *      schema has;
     *   2. failing that (e.g. the competitor's tree-based completion was
     *      just computed by THIS evaluation pass and no participation row/
     *      notification has caught up yet, or they never `join()`-ed the
     *      sub-challenge at all), the latest `reviewed_at`/`submitted_at`
     *      among their approved `challenge_entries` for that sub-challenge —
     *      a reasonable proxy for "when the underlying result was verified".
     * If NEITHER exists, the sub-challenge's completion timing genuinely
     * can't be determined — rather than guessing (crediting or discrediting
     * it arbitrarily), it is conservatively excluded from the count when a
     * window is set. Without a window, timing doesn't matter and it's simply
     * counted once isChallengeCompletedBy() says true.
     */
    private function evaluateSubChallengeThreshold(array $node, string $userId, array &$memo, int $depth): bool
    {
        $required = max(0, (int) ($node['requiredCount'] ?? 0));
        $subs = array_values(array_filter((array) ($node['subChallenges'] ?? []), 'is_array'));
        if ($subs === []) {
            return false;
        }

        $windowStart = $node['windowStart'] ?? null;
        $windowEnd = $node['windowEnd'] ?? null;
        $hasWindow = ($windowStart !== null && $windowStart !== '') || ($windowEnd !== null && $windowEnd !== '');

        $count = 0;
        foreach ($subs as $sub) {
            $subId = (string) ($sub['challengeId'] ?? '');
            if ($subId === '') {
                continue;
            }
            if (!$this->isChallengeCompletedBy($subId, $userId, $memo, $depth + 1)) {
                continue;
            }
            if ($hasWindow) {
                $completedAt = $this->subChallengeCompletionTimestamp($subId, $userId);
                if ($completedAt === null) {
                    continue; // can't verify timing -> conservatively not counted
                }
                if ($windowStart !== null && $windowStart !== '' && $completedAt < (strtotime((string) $windowStart) ?: PHP_INT_MAX)) {
                    continue;
                }
                if ($windowEnd !== null && $windowEnd !== '' && $completedAt > (strtotime((string) $windowEnd) ?: PHP_INT_MIN)) {
                    continue;
                }
            }
            $count++;
        }

        return $count >= $required;
    }

    private function subChallengeCompletionTimestamp(string $subChallengeId, string $userId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT completion_notified_at FROM challenge_participations WHERE challenge_id = ? AND user_id = ?'
        );
        $stmt->execute([$subChallengeId, $userId]);
        $notifiedAt = $stmt->fetchColumn();
        if ($notifiedAt) {
            $ts = strtotime((string) $notifiedAt);
            if ($ts !== false) {
                return $ts;
            }
        }

        $stmt = $this->pdo->prepare(
            "SELECT MAX(COALESCE(reviewed_at, submitted_at)) FROM challenge_entries
             WHERE challenge_id = ? AND user_id = ? AND status = 'approved'"
        );
        $stmt->execute([$subChallengeId, $userId]);
        $fallback = $stmt->fetchColumn();
        if ($fallback) {
            $ts = strtotime((string) $fallback);
            if ($ts !== false) {
                return $ts;
            }
        }

        return null;
    }
}

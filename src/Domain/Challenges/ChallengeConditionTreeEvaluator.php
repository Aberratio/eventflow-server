<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Challenges;

use PDO;

/**
 * Automatic verification (with a manual path for proof): the full recursive
 * evaluator over a Wyzwanie's condition tree
 * (`challenge_condition_nodes`) for ONE competitor — "has this
 * competitor completed this Wyzwanie" (root satisfied) rather than the narrower
 * point-threshold scorer's "score this one point_threshold node".
 *
 * Node types:
 * - `event_threshold`      >= requiredCount of the linked Events have a
 *                           `finished` result for this competitor (optional
 *                           windowStart/windowEnd filters by the Event's own
 *                           `date`).
 * - `point_threshold`      delegates entirely to ChallengePointThresholdScorer
 * — this class does not reimplement scoring.
 *                           The scorer deliberately does not apply a time window to
 *                           this node type (its own class doc comment scopes
 *                           it to points + "N best results" only), and this
 *                           class does not expand that scope either — a
 *                           `windowStart`/`windowEnd` on a `point_threshold`
 *                           node is hydrated (schema-wise it CAN carry one,
 *                           see ChallengeRepository::hydrateNode()) but is
 *                           silently ignored by both the scorer and this
 *                           evaluator. Flagged as a known gap, not fixed here.
 * - `sub_challenge_threshold` recursively evaluates whether the competitor
 *                           has completed >= requiredCount of the referenced
 *                           sub-Wyzwania's own root nodes (see
 *                           isChallengeCompletedBy()). Time-window
 *                           interpretation is a judgment call — see
 *                           subChallengeCompletionTimestamp()'s doc comment.
 * - `group`                >= requiredCount of its children are satisfied
 *                           (AND is simply requiredCount === count(children),
 *                           per the migration's own doc comment).
 *
 * Memoization: a single top-level evaluation call threads one `$memo` array
 * (challengeId|userId -> bool) through every recursive call, so a tree that
 * references the same sub-challenge from two sibling `sub_challenge_threshold`
 * nodes (or the same challenge is asked about twice in one pass) evaluates it
 * exactly once. The memo is caller-owned (start a fresh `[]` per competitor
 * per evaluation) — nothing here caches across calls or competitors, which
 * would risk staleness after a result import; that's deliberately out of
 * scope: don't over-engineer a caching layer.
 *
 * Depth guard: `assertValidTree()` (ChallengeRepository) already
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
     * "3 bronze = 1 silver, 3 silver = 1 gold" example) is at
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
    public function isChallengeCompletedBy(string $challengeId, string $userId, array &$memo = [], int $depth = 0, ?string $competitorId = null): bool
    {
        $this->guardDepth($depth);
        $key = $challengeId . '|' . $userId . ($competitorId !== null ? '|' . $competitorId : '');
        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }
        // Own guard against a self-cycle degenerately slipping through:
        // mark "false" before recursing so a cycle that somehow reaches back
        // here resolves to false on the repeat visit instead of looping.
        $memo[$key] = false;
        $tree = $this->challenges->getConditionTree($challengeId);
        $result = $tree === null ? false : $this->evaluateNode($tree, $userId, $memo, $depth, $challengeId, $competitorId);
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
     * $challengeId scopes an `event_threshold` node's
     * manual-entry lookup to entries submitted/approved under THIS Wyzwanie —
     * `challenge_entries` is keyed on (challengeId, userId, eventId)
     * (ChallengeRepository::submitEntry()'s entryFor() lookup), i.e. two
     * different Wyzwania that both link the same Event each require their
     * own separate manual submission + Moderator approval. Left `null` for
     * backward compatibility (older callers, and every direct unit test of
     * a bare node in isolation) — a `null` challengeId falls back to the
     * broader "approved manual entry for this Event by this user under ANY
     * challenge" match rather than refusing to match at all, since a caller
     * that truly doesn't know its own challenge context has no narrower
     * question to ask.
     *
     * $competitorId, when given, makes the evaluation per Zawodnik (results attributed
     * by ChallengeResultOwnership, manual entries by `challenge_entries.competitor_id`);
     * `null` keeps the legacy per-Konto behaviour (`user_id`) for rows with no Zawodnik.
     *
     * @param array<string, mixed> $node
     * @param array<string, bool> $memo
     */
    public function evaluateNode(array $node, string $userId, array &$memo, int $depth = 0, ?string $challengeId = null, ?string $competitorId = null): bool
    {
        $this->guardDepth($depth);

        return match ($node['type'] ?? null) {
            'event_threshold' => $this->evaluateEventThreshold($node, $userId, $challengeId, $competitorId),
            'point_threshold' => $this->pointScorer->score($node, $userId, $competitorId)['satisfied'],
            'sub_challenge_threshold' => $this->evaluateSubChallengeThreshold($node, $userId, $memo, $depth, $competitorId),
            'group' => $this->evaluateGroup($node, $userId, $memo, $depth, $challengeId, $competitorId),
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

    /**
     * Counts an Event as "completed for this competitor"
     * from EITHER a real `results` row (auto-synced, the pre-existing path)
     * OR an approved `challenge_entries` row with `source = 'manual'` (the
     * moderator-verified proof path) — whichever exists. This is the fix for
     * a real gap in the original design: a manually-approved entry for an external
     * Event (an external Wydarzenie is a state without
     * Dystansy and Zapisy) has no timing infrastructure behind it at all, so
     * it can NEVER have a `results` row — without this UNION, an approved
     * manual proof would satisfy `submitEntry()`/`reviewEntry()`'s own bookkeeping
     * but never actually count toward the tree's `event_threshold` node, i.e.
     * the Wyzwanie would never be marked complete no matter how many manual
     * entries a Moderator approves. `DISTINCT` across the UNION collapses the
     * (rare, but possible — e.g. a result was later imported for an Event that
     * also has an old approved manual entry) double-count case: one Event
     * counts once no matter which path verified it.
     *
     * The window filter is applied to both sources uniformly — the `results`
     * path already filtered by the Event's own `date` via a JOIN; the manual
     * path does the same (it has no separate "when did this happen" signal
     * more authoritative than the Event's date, since submitted/reviewed
     * timestamps describe the paperwork, not the race), so both branches are
     * windowed identically rather than picking two different notions of
     * "when".
     *
     * @param array<string, mixed> $node
     */
    private function evaluateEventThreshold(array $node, string $userId, ?string $challengeId, ?string $competitorId): bool
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
        $windowStart = $node['windowStart'] ?? null;
        $windowEnd = $node['windowEnd'] ?? null;
        $windowSql = '';
        $windowParams = [];
        if ($windowStart !== null && $windowStart !== '') {
            $windowSql .= ' AND e.date >= ?';
            $windowParams[] = $windowStart;
        }
        if ($windowEnd !== null && $windowEnd !== '') {
            $windowSql .= ' AND e.date <= ?';
            $windowParams[] = $windowEnd;
        }

        $challengeSql = $challengeId !== null ? ' AND ce.challenge_id = ?' : '';
        $challengeParam = $challengeId !== null ? [$challengeId] : [];
        if ($competitorId !== null) {
            $resultJoins = ChallengeResultOwnership::JOINS;
            $resultWho = ChallengeResultOwnership::COMPETITOR_EXPR . ' = ?';
            $entryWho = '(ce.competitor_id = ? OR (ce.competitor_id IS NULL AND ce.user_id = ?))';
            $resultWhoParams = [$competitorId];
            $entryWhoParams = [$competitorId, $userId];
        } else {
            $resultJoins = '';
            $resultWho = 'r.user_id = ?';
            $entryWho = 'ce.user_id = ?';
            $resultWhoParams = [$userId];
            $entryWhoParams = [$userId];
        }
        $sql = "SELECT COUNT(DISTINCT event_id) FROM (
                    SELECT r.event_id AS event_id FROM results r{$resultJoins}
                    INNER JOIN events e ON e.id = r.event_id
                    WHERE {$resultWho} AND r.status = 'finished' AND r.event_id IN ({$placeholders}){$windowSql}
                    UNION
                    SELECT ce.event_id AS event_id FROM challenge_entries ce
                    INNER JOIN events e ON e.id = ce.event_id
                    WHERE {$entryWho} AND ce.status = 'approved' AND ce.source = 'manual' AND ce.event_id IN ({$placeholders}){$windowSql}{$challengeSql}
                ) completed_events";
        $params = [...$resultWhoParams, ...$eventIds, ...$windowParams, ...$entryWhoParams, ...$eventIds, ...$windowParams, ...$challengeParam];

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $count = (int) $stmt->fetchColumn();

        return $count >= $required;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool> $memo
     */
    private function evaluateGroup(array $node, string $userId, array &$memo, int $depth, ?string $challengeId, ?string $competitorId): bool
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
            if ($this->evaluateNode($child, $userId, $memo, $depth + 1, $challengeId, $competitorId)) {
                $satisfiedCount++;
            }
        }

        return $satisfiedCount >= $required;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool> $memo
     *
     * Time-window interpretation (a judgment call — presumably the end of
     * participation if one exists, otherwise derived from the underlying
     * entries): a sub-challenge's own
     * "completion moment" for window purposes is, in priority order:
     *   1. its `challenge_participations.completion_notified_at` for this
     *      competitor — the same materialized moment the completion mail uses,
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
    private function evaluateSubChallengeThreshold(array $node, string $userId, array &$memo, int $depth, ?string $competitorId): bool
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
            if (!$this->isChallengeCompletedBy($subId, $userId, $memo, $depth + 1, $competitorId)) {
                continue;
            }
            if ($hasWindow) {
                $completedAt = $this->subChallengeCompletionTimestamp($subId, $userId, $competitorId);
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

    private function subChallengeCompletionTimestamp(string $subChallengeId, string $userId, ?string $competitorId = null): ?int
    {
        $stmt = $this->pdo->prepare(
            $competitorId !== null
                ? 'SELECT completion_notified_at FROM challenge_participations WHERE challenge_id = ? AND competitor_id = ?'
                : 'SELECT completion_notified_at FROM challenge_participations WHERE challenge_id = ? AND user_id = ?'
        );
        $stmt->execute([$subChallengeId, $competitorId ?? $userId]);
        $notifiedAt = $stmt->fetchColumn();
        if ($notifiedAt) {
            $ts = strtotime((string) $notifiedAt);
            if ($ts !== false) {
                return $ts;
            }
        }

        $stmt = $this->pdo->prepare(
            "SELECT MAX(COALESCE(reviewed_at, submitted_at)) FROM challenge_entries
             WHERE challenge_id = ? AND " . ($competitorId !== null ? '(competitor_id = ? OR (competitor_id IS NULL AND user_id = ?))' : 'user_id = ?') . " AND status = 'approved'"
        );
        $stmt->execute($competitorId !== null ? [$subChallengeId, $competitorId, $userId] : [$subChallengeId, $userId]);
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

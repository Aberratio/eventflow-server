<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Challenges;

use PDO;

/**
 * Scoring (points per placement, N best starts): scores
 * one `point_threshold` condition-tree node for one competitor.
 *
 * Deliberately narrow scope: this computes ONE node's own
 * points/satisfaction as a building block. Recursively evaluating a whole
 * tree (group AND/threshold over its children, including nested
 * point_threshold results) for automatic completion detection is the job of
 * ChallengeConditionTreeEvaluator, a separate class - this
 * class doesn't know about `group`/`sub_challenge_threshold` nodes at all.
 *
 * Identity convention: results.user_id directly, matching the existing
 * Challenges-domain convention (ChallengeRepository::syncResultEntries()
 * joins `results r ... r.user_id = :userId`, not the
 * registration->competitor_id mapping ResultRepository::lifetimeRecordsForCompetitor()
 * uses for a different feature) - a competitor here is "the platform user
 * appearing in `challenge_participations.user_id`", the same identity every
 * other read in this domain already keys on.
 *
 * Placement source: `results.position`, the ONLY placement/classification
 * convention this repo has (ResultRepository::renumberEventDistance() ranks
 * strictly by ascending time_in_seconds into consecutive integers - there is
 * no existing ex-aequo/shared-place convention at the Event classification
 * level to reuse). The "remisy" (ties) rule is about two
 * COMPETITORS tying on total Wyzwanie POINTS sharing a rank in the
 * Wyzwanie's own classification - a cross-competitor ranking concern that
 * belongs to whatever builds that classification (the ranking calculator), not to
 * this per-competitor, per-node scorer, which only ever looks at one
 * competitor's own results.
 *
 * Only `status = 'finished'` results count (dnf/dns/dsq
 * are excluded from classification placement) and non-virtual ones are
 * intentionally NOT excluded here (unlike
 * ResultRepository::lifetimeRecordsForCompetitor()'s record-keeping, which
 * excludes virtual results because a virtual result is not as
 * trustworthy for a personal record specifically) - Wyzwanie scoring has no such
 * carve-out for Wyzwanie scoring, and a virtual result that an organizer
 * verified into an official classification position is still a real
 * placement in that Event's results table.
 */
final class ChallengePointThresholdScorer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ChallengePointsSettingsRepository $pointsSettings,
    ) {
    }

    /**
     * @param array<string, mixed> $node a hydrated `point_threshold` node
     *   (ChallengeRepository::hydrateNode() shape: `events` list with
     *   `eventId`, `pointsRequired`, `bestResultsCount`)
     * @return array{
     *   totalPoints: int,
     *   pointsRequired: int,
     *   satisfied: bool,
     *   bestResultsCount: ?int,
     *   breakdown: list<array{eventId: string, place: int, points: int}>
     * }
     */
    public function score(array $node, string $userId, ?string $competitorId = null): array
    {
        $settings = $this->pointsSettings->getSettings();
        $eventIds = array_values(array_unique(array_map(
            static fn (array $e): string => (string) $e['eventId'],
            $node['events'] ?? []
        )));

        $perEvent = [];
        foreach ($eventIds as $eventId) {
            $place = $this->placementFor($eventId, $userId, $competitorId);
            if ($place === null) {
                // No finished result for this competitor on this Event - it
                // simply doesn't contribute a candidate score, not a 0-point
                // entry (a 0-point entry would still occupy one of the "N
                // best" slots and could crowd out a genuinely low-scoring but
                // real result).
                continue;
            }
            $perEvent[] = [
                'eventId' => $eventId,
                'place' => $place,
                'points' => $this->pointsSettings->pointsForPlace($place, $settings),
            ];
        }

        // Best N first: highest points win ties on points via stable input
        // order (usort in PHP 8 is stable), which is fine here - which two
        // equal-scoring events get kept when N is smaller than the candidate
        // count doesn't change the SUM, only which `breakdown` rows are shown.
        usort($perEvent, static fn (array $a, array $b): int => $b['points'] <=> $a['points']);

        $bestResultsCount = isset($node['bestResultsCount']) && $node['bestResultsCount'] !== null
            ? max(0, (int) $node['bestResultsCount'])
            : null;
        $take = $bestResultsCount !== null && $bestResultsCount > 0 ? $bestResultsCount : count($perEvent);
        $best = array_slice($perEvent, 0, $take);

        $totalPoints = array_sum(array_column($best, 'points'));
        $pointsRequired = max(0, (int) ($node['pointsRequired'] ?? 0));

        return [
            'totalPoints' => $totalPoints,
            'pointsRequired' => $pointsRequired,
            'satisfied' => $totalPoints >= $pointsRequired,
            'bestResultsCount' => $bestResultsCount,
            'breakdown' => $best,
        ];
    }

    private function placementFor(string $eventId, string $userId, ?string $competitorId): ?int
    {
        if ($competitorId !== null) {
            // Progress is per Zawodnik - results of a registration made for a ward
            // belong to the ward, not to the caregiver whose user_id is on the result.
            $stmt = $this->pdo->prepare(
                "SELECT r.position FROM results r" . ChallengeResultOwnership::JOINS
                . " WHERE r.event_id = :eventId AND " . ChallengeResultOwnership::COMPETITOR_EXPR . " = :competitorId AND r.status = 'finished' ORDER BY r.position ASC LIMIT 1"
            );
            $stmt->execute(['eventId' => $eventId, 'competitorId' => $competitorId]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT position FROM results WHERE event_id = :eventId AND user_id = :userId AND status = 'finished' ORDER BY position ASC LIMIT 1"
            );
            $stmt->execute(['eventId' => $eventId, 'userId' => $userId]);
        }
        $position = $stmt->fetchColumn();
        if ($position === false || $position === null || (int) $position < 1) {
            return null;
        }
        return (int) $position;
    }
}

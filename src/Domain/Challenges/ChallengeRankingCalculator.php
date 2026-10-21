<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Challenges;

use ZmierzymyCzas\Domain\Competitors\CompetitorNamePresenter;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;

/**
 * E6.6 (issue 15 §6, "Ranking: publiczny, z respektowaniem anonimizacji"):
 * turns one or more Wyzwania's `challenge_participations` into a public
 * ranking — descending by points, ex-aequo ties sharing a rank, anonymized
 * competitors masked. Takes a single challenge id (the Wyzwanie ranking, this
 * ticket's core deliverable) or several (the Series ranking, §6's "Klasyfikacja
 * Serii" — a Series has no participations of its own, so its ranking is the
 * union of its linked Wyzwania's participants, points summed across them).
 *
 * Deliberately a standalone collaborator, not more methods bolted onto
 * ChallengeRepository (already large) — takes ChallengeRepository and
 * CompetitorRepository as collaborators rather than a PDO, since everything
 * it needs (condition trees, participations, competitor profiles) already
 * has a repository that owns that query shape; duplicating those SQL
 * statements here would drift from them over time.
 *
 * Point-total edge case (issue 15 §6 doesn't resolve this — E6.2/E6.3 only
 * modeled `point_threshold` as ONE node type among four): a Wyzwanie whose
 * tree has zero `point_threshold` nodes anywhere (e.g. a pure
 * `event_threshold`/`sub_challenge_threshold` root — "ukoncz wszystkie
 * wydarzenia" with no points concept at all) has no meaningful point total
 * to rank by. Judgment call, documented here: such a Wyzwanie still produces
 * a ranking, but "points" falls back to a binary 1/0 ("ukonczyl" beats "nie
 * ukonczyl", ex-aequo shared by the same rank-sharing rule) rather than
 * hiding the ranking entirely — a participant still wants to see where they
 * stand, and a binary ranking is honest about what the Wyzwanie actually
 * measures instead of inventing a fake point value. `hasPoints` on the
 * returned array tells the caller (client) which case it got, so the UI can
 * label the column "Punkty" vs. "Ukonczono" instead of presenting a 1/0 as if
 * it were a real score.
 */
final class ChallengeRankingCalculator
{
    public function __construct(
        private readonly ChallengeRepository $challenges,
        private readonly CompetitorRepository $competitors,
        private readonly ChallengePointThresholdScorer $scorer,
    ) {
    }

    /**
     * @param array<int, string> $challengeIds
     * @return array{
     *   hasPoints: bool,
     *   entries: list<array{
     *     rank: int, userId: ?string, competitorId: ?string, name: string, isAnonymized: bool,
     *     points: int, isCompleted: bool
     *   }>
     * }
     */
    public function rank(array $challengeIds): array
    {
        $challengeIds = array_values(array_unique(array_filter($challengeIds, static fn (string $id): bool => $id !== '')));

        $pointNodesByChallenge = [];
        $hasPoints = false;
        foreach ($challengeIds as $challengeId) {
            $tree = $this->challenges->getConditionTree($challengeId);
            $nodes = $tree !== null ? $this->collectPointThresholdNodes($tree) : [];
            $pointNodesByChallenge[$challengeId] = $nodes;
            if ($nodes !== []) {
                $hasPoints = true;
            }
        }

        // R-06: one row per Zawodnik (key = competitor id); a participation the migration could not map
        // to a Zawodnik is keyed by the Konto and read by user_id, as before.
        /** @var array<string, array{userId: string, competitorId: ?string, userName: string, isCompleted: bool}> $participants */
        $participants = [];
        foreach ($challengeIds as $challengeId) {
            foreach ($this->challenges->participations($challengeId) as $participation) {
                $userId = (string) $participation['userId'];
                $competitorId = ($participation['competitorId'] ?? null) !== null ? (string) $participation['competitorId'] : null;
                $key = $competitorId ?? 'user:' . $userId;
                $existing = $participants[$key] ?? null;
                $participants[$key] = [
                    'userId' => $userId,
                    'competitorId' => $competitorId,
                    'userName' => $existing['userName'] ?? (string) $participation['userName'],
                    // A competitor with several linked Wyzwania (Series
                    // ranking) counts as "completed" if they completed ANY
                    // one of them — see class docblock for why this is a
                    // judgment call, not a spec-given rule.
                    'isCompleted' => ($existing['isCompleted'] ?? false) || (bool) $participation['isCompleted'],
                ];
            }
        }

        $scored = [];
        foreach ($participants as $info) {
            $userId = $info['userId'];
            $points = $hasPoints
                ? $this->sumPoints($pointNodesByChallenge, $userId, $info['competitorId'])
                : ($info['isCompleted'] ? 1 : 0);

            $competitor = $info['competitorId'] !== null
                ? $this->competitors->find($info['competitorId'])
                : $this->competitors->findByUserId($userId);
            $isAnonymized = $competitor !== null && (bool) ($competitor['isAnonymized'] ?? false);
            $realName = $competitor !== null
                ? trim($competitor['firstName'] . ' ' . $competitor['lastName'])
                : $info['userName'];
            if ($realName === '') {
                $realName = $info['userName'];
            }

            // A ward must not expose the caregiver's account id in the public ranking: show the
            // ward's own account (if any), else none.
            $publicUserId = $competitor !== null && ($competitor['userId'] ?? null) !== $userId
                ? ($competitor['userId'] ?? null)
                : $userId;

            $scored[] = [
                'userId' => $publicUserId,
                'competitorId' => $info['competitorId'],
                'name' => CompetitorNamePresenter::publicName($realName, $isAnonymized, $competitor !== null ? (string) $competitor['id'] : null),
                'isAnonymized' => $isAnonymized,
                'points' => $points,
                'isCompleted' => $info['isCompleted'],
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['points'] <=> $a['points']);

        return ['hasPoints' => $hasPoints, 'entries' => $this->assignRanks($scored)];
    }

    /**
     * Standard competition ranking ("1,1,3"): ties share the same rank
     * number, and the next distinct value skips as many ranks as there were
     * tied competitors ahead of it — NOT "1,1,2" (dense ranking) and NOT
     * "1,2,2" (ties get the lower participant's rank rather than sharing
     * one). Chosen because it's the convention issue 15 §2's own "Remisy"
     * note implies ("dzielą miejsce w klasyfikacji") and the one most
     * competitors recognize from real classifications (shared golf/running
     * placings): two people tied for 1st means the next person is 3rd, not
     * 2nd.
     *
     * @param list<array{points: int, userId: ?string, competitorId: ?string, name: string, isAnonymized: bool, isCompleted: bool}> $scored
     * @return list<array{rank: int, userId: ?string, competitorId: ?string, name: string, isAnonymized: bool, points: int, isCompleted: bool}>
     */
    private function assignRanks(array $scored): array
    {
        $entries = [];
        $rank = 0;
        $position = 0;
        $previousPoints = null;
        foreach ($scored as $row) {
            $position++;
            if ($previousPoints === null || $row['points'] !== $previousPoints) {
                $rank = $position;
            }
            $entries[] = ['rank' => $rank, ...$row];
            $previousPoints = $row['points'];
        }
        return $entries;
    }

    /** @param array<string, array<int, array<string, mixed>>> $pointNodesByChallenge */
    private function sumPoints(array $pointNodesByChallenge, string $userId, ?string $competitorId = null): int
    {
        $total = 0;
        foreach ($pointNodesByChallenge as $nodes) {
            foreach ($nodes as $node) {
                $total += $this->scorer->score($node, $userId, $competitorId)['totalPoints'];
            }
        }
        return $total;
    }

    /**
     * Recursively walks a hydrated condition tree (ChallengeRepository::
     * hydrateNode() shape) collecting every `point_threshold` node,
     * regardless of nesting depth under `group` nodes. A tree can have more
     * than one (e.g. a group combining two separate point thresholds) —
     * every one found contributes its own totalPoints, summed.
     *
     * @param array<string, mixed> $node
     * @return list<array<string, mixed>>
     */
    private function collectPointThresholdNodes(array $node): array
    {
        $type = (string) ($node['type'] ?? '');
        if ($type === 'point_threshold') {
            return [$node];
        }
        if ($type === 'group') {
            $found = [];
            foreach (($node['children'] ?? []) as $child) {
                if (is_array($child)) {
                    $found = [...$found, ...$this->collectPointThresholdNodes($child)];
                }
            }
            return $found;
        }
        return [];
    }
}

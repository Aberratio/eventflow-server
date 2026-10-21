<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Challenges;

use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Repository;
use ZmierzymyCzas\Domain\Shop\OrderRepository;
use ZmierzymyCzas\Support\InputRules;

final class ChallengeRepository extends Repository
{
    private const COLUMNS = 'id, name, slug, description, image_url AS imageUrl, series_id AS seriesId, min_completed AS minCompleted, max_time AS maxTime, start_date AS startDate, end_date AS endDate, status, created_by AS createdBy';
    private const STATUSES = ['active', 'completed', 'upcoming', 'archived'];

    /** PP.76: request-body rules for POST/PATCH /challenges (see InputRules). */
    private const INPUT_RULES = [
        'name' => [InputRules::TEXT_REQUIRED, 'Nazwa wyzwania'],
        'slug' => [InputRules::TEXT_REQUIRED, 'Adres URL (slug) wyzwania'],
        'description' => [InputRules::TEXT, 'Opis wyzwania'],
        'imageUrl' => [InputRules::TEXT_NULLABLE, 'Zdjęcie wyzwania'],
        'seriesId' => [InputRules::TEXT_NULLABLE, 'Seria'],
        'startDate' => [InputRules::TEXT_NULLABLE, 'Data rozpoczęcia wyzwania'],
        'endDate' => [InputRules::TEXT_NULLABLE, 'Data zakończenia wyzwania'],
        'status' => [InputRules::TEXT_NULLABLE, 'Status wyzwania'],
        'maxTime' => [InputRules::TEXT_NULLABLE, 'Limit czasu'],
        'events' => [InputRules::LIST_NULLABLE, 'Wydarzenia wyzwania'],
        'products' => [InputRules::LIST_NULLABLE, 'Nagrody wyzwania'],
        'rewards' => [InputRules::LIST_NULLABLE, 'Nagrody wyzwania'],
    ];
    private const ENTRY_STATUSES = ['pending', 'approved', 'rejected'];
    /** E6.1 condition-tree node types (issue 15 §1). */
    private const NODE_TYPES = ['group', 'event_threshold', 'point_threshold', 'sub_challenge_threshold'];
    private const LEAF_EVENT_NODE_TYPES = ['event_threshold', 'point_threshold'];

    /**
     * E6.3 lazily-built collaborator (issue 15 §3): the full recursive tree
     * evaluator. Built on first use rather than taken as a constructor
     * argument so every existing call site (`new ChallengeRepository($pdo)`,
     * all over routes.php and the test suite) keeps working unchanged.
     */
    private ?ChallengeConditionTreeEvaluator $treeEvaluator = null;

    /**
     * E6.5 (issue 15 §4): lazily-built collaborator, same shape/rationale as
     * $treeEvaluator above — claimReward() needs OrderRepository::create() to
     * actually place the reward order, but adding it as a constructor
     * argument would break every existing `new ChallengeRepository($pdo)`
     * call site across routes.php and the test suite.
     */
    private ?OrderRepository $orders = null;

    /**
     * G-06b: whether an online gateway (Tpay/PayNow) is configured. Passed on to the
     * OrderRepository used for reward orders so they apply the same payment-method
     * rule as POST /shop/orders (card/BLIK only with a gateway).
     */
    public function __construct(\PDO $pdo, private readonly bool $onlineGatewayConfigured = true)
    {
        parent::__construct($pdo);
    }

    /** @param array<string, mixed> $filters @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (empty($filters['includeArchived'])) {
            $where[] = "status <> 'archived'";
        }
        foreach (['status' => 'status', 'seriesId' => 'series_id'] as $input => $column) {
            if (!empty($filters[$input])) {
                $where[] = "{$column} = :{$input}";
                $params[$input] = $filters[$input];
            }
        }
        if (!empty($filters['search'])) {
            $where[] = 'name LIKE :search';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . " FROM challenges{$whereSql} ORDER BY start_date DESC, name ASC");
        $stmt->execute($params);
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM challenges WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare("SELECT " . self::COLUMNS . " FROM challenges WHERE slug = :slug AND status <> 'archived'");
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, string $actorId): array
    {
        InputRules::check($data, self::INPUT_RULES, true);
        $data += ['description' => ''];
        $id = $this->id();
        $this->pdo->beginTransaction();
        try {
            $this->insert('challenges', $this->challengeData($id, [...$data, 'createdBy' => $actorId], true));
            $this->replaceEvents($id, $data['events'] ?? []);
            $this->replaceProducts($id, $data['products'] ?? $data['rewards'] ?? []);
            $tree = $this->conditionTreeOrDefault($data);
            $this->assertValidTree($id, $tree);
            $this->saveConditionTreeInternal($id, $tree);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $data
     *
     * E6.2 resync-gap fix (issue 15 §2, gap flagged by E6.1's own
     * verification): editing a challenge's legacy flat `events` list through
     * this method used to leave the condition tree (E6.1) untouched unless
     * the caller also sent an explicit `conditionTree` - silently stale once
     * E6.2 makes the tree's `point_threshold`/`event_threshold` nodes drive
     * real scoring decisions.
     *
     * Chosen fix, scoped deliberately: auto-resync ONLY the unambiguous
     * default-shape case (root is a single `event_threshold` node with no
     * time window whose linked events are exactly the challenge's current
     * flat `challenge_events` list - precisely the shape
     * conditionTreeOrDefault()/migration 054's backfill both produce, i.e.
     * "the admin never customized the tree"). For anything beyond that (a
     * `group`/`point_threshold`/`sub_challenge_threshold` root, a windowed or
     * already-subset-scoped event_threshold root, multiple leaf nodes, etc.)
     * this method does NOT silently rewrite the tree - guessing which nodes
     * an admin-customized tree "should" follow a flat-list edit would be
     * wrong more often than right. Instead, `hydrate()` always exposes
     * `conditionTreeMatchesEvents` (see below), a plain descriptive flag (not
     * an alarm) so callers - including E6.3's future full-tree evaluator and
     * the admin UI - can see at a glance whether the tree currently mirrors
     * the flat list, and treat a customized tree's "false" as expected rather
     * than stale.
     */
    public function update(string $id, array $data): ?array
    {
        InputRules::check($data, self::INPUT_RULES, false);
        if (array_key_exists('conditionTree', $data)) {
            $this->assertValidTree($id, (array) $data['conditionTree']);
        }

        $eventsChangingWithoutExplicitTree = array_key_exists('events', $data) && !array_key_exists('conditionTree', $data);
        $oldFlatEventIds = null;
        $oldTree = null;
        if ($eventsChangingWithoutExplicitTree) {
            $oldFlatEventIds = array_column($this->find($id)['events'] ?? [], 'eventId');
            $oldTree = $this->getConditionTree($id);
        }

        $this->pdo->beginTransaction();
        try {
            $this->updateById('challenges', $id, $this->challengeData($id, $data, false));
            if (array_key_exists('events', $data)) {
                $this->replaceEvents($id, $data['events'] ?? []);
            }
            if (array_key_exists('products', $data) || array_key_exists('rewards', $data)) {
                $this->replaceProducts($id, $data['products'] ?? $data['rewards'] ?? []);
            }
            if (array_key_exists('conditionTree', $data)) {
                $this->saveConditionTreeInternal($id, (array) $data['conditionTree']);
            } elseif ($eventsChangingWithoutExplicitTree && $this->isDefaultEventThresholdTree($oldTree, $oldFlatEventIds ?? [])) {
                $newEvents = array_values($data['events'] ?? []);
                $this->saveConditionTreeInternal($id, [
                    'type' => 'event_threshold',
                    'requiredCount' => count($newEvents),
                    'events' => $newEvents,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->find($id);
    }

    /**
     * True only when $tree is exactly the "still the default" shape: a
     * single root `event_threshold` node, no time window, whose linked
     * events are precisely $flatEventIds (as a set) with requiredCount equal
     * to that count. See update()'s doc comment for why only this exact
     * shape is safe to auto-resync.
     *
     * @param array<string, mixed>|null $tree
     * @param array<int, string> $flatEventIds
     */
    private function isDefaultEventThresholdTree(?array $tree, array $flatEventIds): bool
    {
        if ($tree === null || ($tree['type'] ?? null) !== 'event_threshold') {
            return false;
        }
        if (($tree['windowStart'] ?? null) !== null || ($tree['windowEnd'] ?? null) !== null) {
            return false;
        }
        $treeEventIds = array_column($tree['events'] ?? [], 'eventId');
        sort($treeEventIds);
        $flat = $flatEventIds;
        sort($flat);
        return $treeEventIds === $flat && (int) ($tree['requiredCount'] ?? -1) === count($flat);
    }

    /**
     * E6.1: reads a challenge's condition tree back as nested JSON (see
     * hydrateNode() for the exact shape). Returns null only if the challenge
     * has no root node at all, which should not happen for any challenge
     * created after this migration (create() always seeds one) — a legacy
     * row that somehow lacks one falls back to null rather than throwing, so
     * a stale read never 500s.
     */
    public function getConditionTree(string $challengeId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, parent_node_id AS parentNodeId, node_type AS nodeType, required_count AS requiredCount,
                    points_required AS pointsRequired, best_results_count AS bestResultsCount,
                    window_start AS windowStart, window_end AS windowEnd, sort_order AS sortOrder
             FROM challenge_condition_nodes WHERE challenge_id = :id ORDER BY sort_order ASC'
        );
        $stmt->execute(['id' => $challengeId]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return null;
        }
        $byId = [];
        $root = null;
        foreach ($rows as $row) {
            $byId[(string) $row['id']] = $row;
            if ($row['parentNodeId'] === null) {
                $root = $row;
            }
        }
        return $root === null ? null : $this->hydrateNode($root, $byId);
    }

    /**
     * E6.1: replaces a challenge's entire condition tree. Public entry point
     * used by the dedicated tree endpoint; create()/update() go through the
     * *Internal variant below because they already hold the transaction and
     * (for update()) already ran assertValidTree() before starting it.
     *
     * @param array<string, mixed> $tree
     */
    public function saveConditionTree(string $challengeId, array $tree): ?array
    {
        $this->assertValidTree($challengeId, $tree);
        $this->pdo->beginTransaction();
        try {
            $this->saveConditionTreeInternal($challengeId, $tree);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->getConditionTree($challengeId);
    }

    /** @param array<string, mixed> $tree */
    private function assertValidTree(string $challengeId, array $tree): void
    {
        if ($this->find($challengeId) === null) {
            throw new \InvalidArgumentException('Wyzwanie nie istnieje.');
        }
        $subChallengeIds = [];
        $this->collectSubChallengeIds($tree, $subChallengeIds);
        foreach (array_unique($subChallengeIds) as $subChallengeId) {
            if ($subChallengeId === $challengeId) {
                throw new \InvalidArgumentException('Węzeł progu pod-Wyzwań nie może odwoływać się do własnego Wyzwania.');
            }
            if ($this->find($subChallengeId) === null) {
                throw new \InvalidArgumentException("Wskazane pod-Wyzwanie ({$subChallengeId}) nie istnieje lub nie zostało jeszcze zapisane.");
            }
            // Spec invariant: references only go down (a sub-challenge is
            // always created/saved before the challenge that composes it),
            // so a cycle can only appear if the referenced sub-challenge's
            // own (already-saved) tree transitively references back up to
            // $challengeId. Walk it defensively rather than trust the
            // invariant blindly.
            if (in_array($challengeId, $this->collectReferencedChallengeIds($subChallengeId, []), true)) {
                throw new \InvalidArgumentException('Wykryto cykl w drzewie warunków ukończenia Wyzwania.');
            }
        }
    }

    /** @param array<string, mixed> $node @param array<int, string> $ids */
    private function collectSubChallengeIds(array $node, array &$ids): void
    {
        $type = (string) ($node['type'] ?? '');
        if ($type === 'sub_challenge_threshold') {
            foreach (array_values($node['subChallenges'] ?? []) as $sub) {
                $subId = is_array($sub) ? (string) ($sub['challengeId'] ?? '') : (string) $sub;
                if ($subId !== '') {
                    $ids[] = $subId;
                }
            }
        }
        if ($type === 'group') {
            foreach (array_values($node['children'] ?? []) as $child) {
                if (is_array($child)) {
                    $this->collectSubChallengeIds($child, $ids);
                }
            }
        }
    }

    /** @param array<int, string> $seen @return array<int, string> */
    private function collectReferencedChallengeIds(string $challengeId, array $seen): array
    {
        if (in_array($challengeId, $seen, true)) {
            return $seen;
        }
        $seen[] = $challengeId;
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT sc.sub_challenge_id AS subChallengeId
             FROM challenge_condition_node_sub_challenges sc
             INNER JOIN challenge_condition_nodes n ON n.id = sc.node_id
             WHERE n.challenge_id = :challengeId'
        );
        $stmt->execute(['challengeId' => $challengeId]);
        foreach ($stmt->fetchAll() as $row) {
            $seen = $this->collectReferencedChallengeIds((string) $row['subChallengeId'], $seen);
        }
        return $seen;
    }

    /** @param array<string, mixed> $tree */
    private function saveConditionTreeInternal(string $challengeId, array $tree): void
    {
        $this->pdo->prepare('DELETE FROM challenge_condition_nodes WHERE challenge_id = :id')->execute(['id' => $challengeId]);
        $this->insertNode($challengeId, null, $tree, 0);
    }

    /** @param array<string, mixed> $node */
    private function insertNode(string $challengeId, ?string $parentId, array $node, int $sortOrder): void
    {
        $type = (string) ($node['type'] ?? '');
        if (!in_array($type, self::NODE_TYPES, true)) {
            throw new \InvalidArgumentException("Nieprawidłowy typ węzła warunku ukończenia: \"{$type}\".");
        }

        $id = $this->id();
        $this->insert('challenge_condition_nodes', [
            'id' => $id,
            'challenge_id' => $challengeId,
            'parent_node_id' => $parentId,
            'node_type' => $type,
            'required_count' => isset($node['requiredCount']) ? max(0, (int) $node['requiredCount']) : null,
            'points_required' => isset($node['pointsRequired']) ? max(0, (int) $node['pointsRequired']) : null,
            'best_results_count' => isset($node['bestResultsCount']) ? max(0, (int) $node['bestResultsCount']) : null,
            'window_start' => $node['windowStart'] ?? null,
            'window_end' => $node['windowEnd'] ?? null,
            'sort_order' => $sortOrder,
        ]);

        if (in_array($type, self::LEAF_EVENT_NODE_TYPES, true)) {
            foreach (array_values($node['events'] ?? []) as $index => $event) {
                $eventId = is_array($event) ? (string) ($event['eventId'] ?? '') : (string) $event;
                if ($eventId === '') {
                    continue;
                }
                $this->insert('challenge_condition_node_events', ['node_id' => $id, 'event_id' => $eventId, 'sort_order' => $index]);
            }
        }

        if ($type === 'sub_challenge_threshold') {
            foreach (array_values($node['subChallenges'] ?? []) as $index => $sub) {
                $subId = is_array($sub) ? (string) ($sub['challengeId'] ?? '') : (string) $sub;
                if ($subId === '') {
                    continue;
                }
                $this->insert('challenge_condition_node_sub_challenges', ['node_id' => $id, 'sub_challenge_id' => $subId, 'sort_order' => $index]);
            }
        }

        if ($type === 'group') {
            $children = array_values($node['children'] ?? []);
            if ($children === []) {
                throw new \InvalidArgumentException('Węzeł grupujący (AND/próg) musi mieć co najmniej jedno dziecko.');
            }
            foreach ($children as $index => $child) {
                if (!is_array($child)) {
                    throw new \InvalidArgumentException('Nieprawidłowa definicja dziecka węzła grupującego.');
                }
                $this->insertNode($challengeId, $id, $child, $index);
            }
        }
    }

    /**
     * Default tree for create(): a single root event_threshold node mirroring
     * the flat `events` list ("ukończ wszystkie"), exactly the shape the
     * 054 migration backfills onto pre-existing challenges — keeps the flat
     * creation path (no `conditionTree` in the payload, still what the
     * existing admin UI/tests send) producing a valid, equivalent tree.
     *
     * @param array<string, mixed> $data @return array<string, mixed>
     */
    private function conditionTreeOrDefault(array $data): array
    {
        if (isset($data['conditionTree']) && is_array($data['conditionTree'])) {
            return $data['conditionTree'];
        }
        $events = array_values($data['events'] ?? []);
        return [
            'type' => 'event_threshold',
            'requiredCount' => count($events),
            'events' => $events,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $byId
     * @return array<string, mixed>
     */
    private function hydrateNode(array $row, array $byId): array
    {
        $node = [
            'type' => $row['nodeType'],
            'requiredCount' => $row['requiredCount'] !== null ? (int) $row['requiredCount'] : null,
        ];

        if ($row['nodeType'] === 'point_threshold') {
            $node['pointsRequired'] = $row['pointsRequired'] !== null ? (int) $row['pointsRequired'] : null;
            $node['bestResultsCount'] = $row['bestResultsCount'] !== null ? (int) $row['bestResultsCount'] : null;
        }

        if (in_array($row['nodeType'], [...self::LEAF_EVENT_NODE_TYPES, 'sub_challenge_threshold'], true)) {
            $node['windowStart'] = $row['windowStart'];
            $node['windowEnd'] = $row['windowEnd'];
        }

        if (in_array($row['nodeType'], self::LEAF_EVENT_NODE_TYPES, true)) {
            $events = $this->pdo->prepare(
                'SELECT cne.event_id AS eventId, e.name AS eventName, e.date AS eventDate
                 FROM challenge_condition_node_events cne LEFT JOIN events e ON e.id = cne.event_id
                 WHERE cne.node_id = :id ORDER BY cne.sort_order ASC'
            );
            $events->execute(['id' => $row['id']]);
            $node['events'] = array_map(static fn (array $e): array => [
                'eventId' => $e['eventId'],
                'eventName' => $e['eventName'] ?? $e['eventId'],
                'eventDate' => $e['eventDate'] ?? '',
            ], $events->fetchAll());
        }

        if ($row['nodeType'] === 'sub_challenge_threshold') {
            $subs = $this->pdo->prepare(
                'SELECT sc.sub_challenge_id AS challengeId, c.name
                 FROM challenge_condition_node_sub_challenges sc LEFT JOIN challenges c ON c.id = sc.sub_challenge_id
                 WHERE sc.node_id = :id ORDER BY sc.sort_order ASC'
            );
            $subs->execute(['id' => $row['id']]);
            $node['subChallenges'] = array_map(static fn (array $s): array => [
                'challengeId' => $s['challengeId'],
                'name' => $s['name'] ?? $s['challengeId'],
            ], $subs->fetchAll());
        }

        if ($row['nodeType'] === 'group') {
            $children = array_values(array_filter($byId, static fn (array $r): bool => $r['parentNodeId'] === $row['id']));
            usort($children, static fn (array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder']);
            $node['children'] = array_map(fn (array $child): array => $this->hydrateNode($child, $byId), $children);
        }

        return $node;
    }

    public function archive(string $id): ?array
    {
        $this->updateById('challenges', $id, ['status' => 'archived']);
        return $this->find($id);
    }

    private const PARTICIPATION_SELECT = "SELECT cp.id, cp.challenge_id AS challengeId, cp.user_id AS userId, cp.competitor_id AS competitorId, TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS competitorName, cp.user_name AS userName, cp.joined_at AS joinedAt, cp.reward_granted AS rewardGranted, cp.reward_granted_at AS rewardGrantedAt, cp.selected_rewards AS selectedRewards, cp.assigned_number AS assignedNumber FROM challenge_participations cp LEFT JOIN competitors c ON c.id = cp.competitor_id";

    /**
     * R-06 (zakres P2): participations are per Zawodnik (`competitor_id`); rows the migration
     * could not map keep `competitor_id` NULL and are read by `user_id` (fallback).
     *
     * $userId filters the Konto's own participations (own Zawodnik, or rows with no Zawodnik);
     * with $includeCare it also returns every ward under its Opieka, whoever joined them
     * ("my challenges" - one row per Zawodnik, each with `competitorName`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function participations(?string $challengeId = null, ?string $userId = null, ?string $competitorId = null, bool $includeCare = false): array
    {
        $where = [];
        $params = [];
        if ($challengeId) {
            $where[] = 'cp.challenge_id = :challengeId';
            $params['challengeId'] = $challengeId;
        }
        if ($competitorId) {
            $where[] = 'cp.competitor_id = :competitorId';
            $params['competitorId'] = $competitorId;
        }
        if ($userId) {
            $careIds = $includeCare ? $this->accountCompetitorIds($userId) : [];
            if ($careIds === []) {
                // Without $includeCare: the Konto's OWN participations - never the wards' rows it joined.
                $own = $this->selfCompetitorId($userId);
                $where[] = 'cp.user_id = :userId AND (cp.competitor_id IS NULL' . ($own !== null ? ' OR cp.competitor_id = :ownCompetitorId' : '') . ')';
                if ($own !== null) {
                    $params['ownCompetitorId'] = $own;
                }
            } else {
                $marks = [];
                foreach ($careIds as $i => $careId) {
                    $marks[] = ':care' . $i;
                    $params['care' . $i] = $careId;
                }
                $where[] = '(cp.user_id = :userId OR cp.competitor_id IN (' . implode(',', $marks) . '))';
            }
            $params['userId'] = $userId;
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->pdo->prepare(self::PARTICIPATION_SELECT . $whereSql . ' ORDER BY cp.joined_at DESC');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if ($challengeId && ($userId || $competitorId)) {
            foreach ($rows as $row) {
                $this->syncResultEntries((string) $row['challengeId'], (string) $row['userId'], $row['competitorId'] !== null ? (string) $row['competitorId'] : null);
            }
        }
        return array_map(fn (array $row): array => $this->hydrateParticipation($row), $rows);
    }

    /** @return array<string, mixed>|null */
    private function participationById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(self::PARTICIPATION_SELECT . ' WHERE cp.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrateParticipation($row) : null;
    }

    /** The Zawodnik profile of a Konto (`competitors.user_id`), if it has one. */
    private function selfCompetitorId(string $userId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT id FROM competitors WHERE user_id = :userId');
        $stmt->execute(['userId' => $userId]);
        $id = $stmt->fetchColumn();
        return $id === false || $id === null ? null : (string) $id;
    }

    /**
     * Own Zawodnik profile plus wards under Opieka (care_relations) of a Konto.
     *
     * @return list<string>
     */
    private function accountCompetitorIds(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM competitors WHERE user_id = :userId1 UNION SELECT zawodnik_id FROM care_relations WHERE konto_id = :userId2'
        );
        $stmt->execute(['userId1' => $userId, 'userId2' => $userId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The participation of $userId's own Zawodnik, or - with $competitorId - of that Zawodnik.
     * A row with NULL `competitor_id` (could not be mapped by migration 120) is matched by
     * `user_id`, but only for the Konto's own profile, never for a ward.
     *
     * @return array<string, mixed>|null id, competitorId, rewardGranted, completionNotifiedAt
     */
    private function findParticipationRow(string $challengeId, string $userId, ?string $competitorId): ?array
    {
        $self = $this->selfCompetitorId($userId);
        $target = $competitorId ?? $self;
        $select = 'SELECT id, competitor_id AS competitorId, reward_granted AS rewardGranted, completion_notified_at AS completionNotifiedAt FROM challenge_participations WHERE challenge_id = :challengeId AND ';
        if ($target !== null) {
            $sql = $select . '(competitor_id = :competitorId' . ($target === $self ? ' OR (competitor_id IS NULL AND user_id = :userId)' : '') . ') ORDER BY competitor_id IS NULL ASC LIMIT 1';
            $params = ['challengeId' => $challengeId, 'competitorId' => $target];
            if ($target === $self) {
                $params['userId'] = $userId;
            }
        } else {
            $sql = $select . 'user_id = :userId AND competitor_id IS NULL LIMIT 1';
            $params = ['challengeId' => $challengeId, 'userId' => $userId];
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Joins $competitorId (a ward under the caller's Opieka - the caller checks that) or, when
     * null, the Konto's own Zawodnik. `user_id` stays the Konto that joined (mail recipient).
     *
     * @param array<string, mixed> $user
     */
    public function join(string $challengeId, array $user, ?string $competitorId = null): array
    {
        $userId = (string) $user['id'];
        $competitors = new CompetitorRepository($this->pdo);
        $displayName = (string) $user['name'];
        if ($competitorId === null) {
            $competitorId = $this->selfCompetitorId($userId);
        } else {
            $competitor = $competitors->find($competitorId);
            if ($competitor === null) {
                throw new \RuntimeException('competitor_not_found');
            }
            if (($competitor['userId'] ?? null) !== $userId) {
                $displayName = trim($competitor['firstName'] . ' ' . $competitor['lastName']);
            }
        }

        $existing = $this->findParticipationRow($challengeId, $userId, $competitorId);
        if ($existing !== null) {
            if ($existing['competitorId'] === null && $competitorId !== null) {
                $this->updateById('challenge_participations', (string) $existing['id'], ['competitor_id' => $competitorId]);
            }
            $this->syncResultEntries($challengeId, $userId, $competitorId);
            return $this->participationById((string) $existing['id']);
        }
        $id = $this->id();
        $this->insert('challenge_participations', [
            'id' => $id,
            'challenge_id' => $challengeId,
            'user_id' => $userId,
            'competitor_id' => $competitorId,
            'user_name' => $displayName,
            'joined_at' => date(DATE_ATOM),
            'reward_granted' => 0,
            'selected_rewards' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $this->syncResultEntries($challengeId, $userId, $competitorId);
        return $this->participationById($id);
    }
    /**
     * E6.5 (issue 15 §4-5): claims this challenge's reward Product for
     * $userId — "Uczestnictwo odblokowuje możliwość zakupu powiązanego
     * Produktu". Gated on the SAME completion check
     * hydrateParticipation()/checkCompletion() use
     * (isChallengeCompletedForUser(), E6.3's real condition-tree evaluator —
     * never a flat rule). Reuses OrderRepository::create() (E5.2/E5.6) for
     * the actual order: price, stock/variant-limit enforcement, delivery and
     * payment validation, and the zero-price path (a free reward is simply a
     * Product with price 0 — same order flow, no special case here) all stay
     * the shop's responsibility, not duplicated here.
     *
     * Double-claim guard (§4-5 leaves the mechanism unspecified — judgment
     * call, documented here): `reward_granted` is flipped 0->1 with a single
     * conditional UPDATE ("claim lock") BEFORE the shop order is created.
     * ChallengeRepository and OrderRepository/ShopRepository don't share one
     * transaction here — OrderRepository::create() opens and manages its own,
     * and nesting a second beginTransaction() on the same PDO connection
     * isn't supported — so true single-transaction atomicity across both
     * domains would need a larger refactor outside this ticket's scope.
     * Instead: the conditional UPDATE's affected-row count acts as an atomic
     * compare-and-set lock (two concurrent claims can never both flip 0->1),
     * and if OrderRepository::create() then throws for ANY reason (out of
     * stock, invalid product/method/address, etc.) the lock is released
     * (reverted back to 0) before rethrowing, so a failed purchase never
     * leaves a phantom "reward granted" with no order behind it. The
     * challenge's own completion status — computed separately by
     * isChallengeCompletedForUser(), never written by this method — is
     * completely unaffected by a failed or successful claim either way, per
     * issue 15 §5 ("ukończenie Wyzwania nie znika").
     *
     * @param array<string, mixed> $item deliveryMethod/paymentMethod/shippingAddress/invoiceData/size/color, same shape OrderRepository::create() expects for a single-item cart
     * @return array<string, mixed> the updated participation (see hydrateParticipation())
     */
    public function claimReward(string $challengeId, string $productId, array $item, string $userId, ?string $competitorId = null): array
    {
        $challenge = $this->find($challengeId);
        if ($challenge === null) {
            throw new \RuntimeException('challenge_not_found');
        }

        $participation = $this->findParticipationRow($challengeId, $userId, $competitorId);
        if ($participation === null) {
            throw new \RuntimeException('challenge_not_joined');
        }
        $rowCompetitorId = $participation['competitorId'] !== null ? (string) $participation['competitorId'] : null;

        if (!$this->isChallengeCompletedForUser($challenge, $userId, $rowCompetitorId)) {
            throw new \RuntimeException('challenge_not_completed');
        }

        $isRewardProduct = false;
        foreach ((array) ($challenge['products'] ?? []) as $product) {
            if (($product['productId'] ?? null) === $productId && ($product['purpose'] ?? null) === 'reward') {
                $isRewardProduct = true;
                break;
            }
        }
        if (!$isRewardProduct) {
            throw new \RuntimeException('challenge_invalid_reward_product');
        }

        $participationId = (string) $participation['id'];
        $claimedAt = date(DATE_ATOM);
        $claim = $this->pdo->prepare(
            'UPDATE challenge_participations SET reward_granted = 1, reward_granted_at = :at WHERE id = :id AND reward_granted = 0'
        );
        $claim->execute(['at' => $claimedAt, 'id' => $participationId]);
        if ($claim->rowCount() !== 1) {
            throw new \RuntimeException('challenge_reward_already_claimed');
        }

        try {
            $order = $this->orders()->create([
                'items' => [[
                    'productId' => $productId,
                    'quantity' => 1,
                    'size' => $item['size'] ?? null,
                    'color' => $item['color'] ?? null,
                ]],
                'deliveryMethod' => $item['deliveryMethod'] ?? null,
                'paymentMethod' => $item['paymentMethod'] ?? null,
                'shippingAddress' => $item['shippingAddress'] ?? null,
                'invoiceData' => $item['invoiceData'] ?? null,
            ], $userId);
        } catch (\Throwable $e) {
            $this->pdo->prepare(
                'UPDATE challenge_participations SET reward_granted = 0, reward_granted_at = NULL WHERE id = :id'
            )->execute(['id' => $participationId]);
            throw $e;
        }

        $this->pdo->prepare('UPDATE challenge_participations SET selected_rewards = :selectedRewards WHERE id = :id')
            ->execute([
                'selectedRewards' => json_encode([[
                    'productId' => $productId,
                    'orderId' => $order['id'],
                    'size' => $item['size'] ?? null,
                    'color' => $item['color'] ?? null,
                    'claimedAt' => $claimedAt,
                ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'id' => $participationId,
            ]);

        return $this->participationById($participationId);
    }

    /** Lazily builds the E6.5 order-creation collaborator — see its property doc comment. */
    private function orders(): OrderRepository
    {
        if ($this->orders === null) {
            $this->orders = new OrderRepository($this->pdo, null, $this->onlineGatewayConfigured);
        }
        return $this->orders;
    }

    /** @param array<string, mixed> $data */
    public function submitEntry(array $data, array $actor): array
    {
        $challengeId = (string) ($data['challengeId'] ?? '');
        $userId = (string) ($data['userId'] ?? $actor['sub'] ?? '');
        $eventId = (string) ($data['eventId'] ?? '');
        // R-06: a manual entry belongs to a Zawodnik - the caller's own, or a ward (the route checks Opieka).
        $competitorId = isset($data['competitorId']) && $data['competitorId'] !== '' ? (string) $data['competitorId'] : $this->selfCompetitorId($userId);
        $existing = $this->entryFor($challengeId, $userId, $competitorId, $eventId);
        $payload = [
            'challenge_id' => $challengeId,
            'user_id' => $userId,
            'competitor_id' => $competitorId,
            'event_id' => $eventId,
            'status' => 'pending',
            'submitted_time' => $data['submittedTime'] ?? null,
            'submitted_position' => $data['submittedPosition'] ?? null,
            'proof_url' => $data['proofUrl'] ?? null,
            'submitted_at' => date(DATE_ATOM),
            'reviewed_at' => null,
            'reviewed_by' => null,
            'rejection_reason' => null,
            'source' => 'manual',
        ];
        if ($existing) {
            $this->updateById('challenge_entries', (string) $existing['id'], $payload);
            return $this->entry((string) $existing['id']);
        }
        $id = $this->id();
        $this->insert('challenge_entries', ['id' => $id, ...$payload]);
        return $this->entry($id);
    }

    public function reviewEntry(string $id, string $status, ?string $reason, string $actorId): ?array
    {
        $this->updateById('challenge_entries', $id, [
            'status' => in_array($status, self::ENTRY_STATUSES, true) ? $status : 'pending',
            'reviewed_at' => date(DATE_ATOM),
            'reviewed_by' => $actorId,
            'rejection_reason' => $reason,
        ]);
        return $this->entry($id);
    }

    /**
     * E1.10 mail #13 ("Ukończenie Wyzwania", 17 §1 row 13). Challenge completion
     * has no discrete write of its own anywhere in this codebase —
     * hydrateParticipation() only *computes* isCompleted on every read (see
     * isChallengeCompletedForUser() below, shared by both). This is the one
     * materialized moment: called right after an entry review (approve) or a
     * result-derived auto-approval (syncResultEntries()), it checks whether
     * the participant has JUST become completed for the first time and, if
     * so, marks `completion_notified_at` so it is never reported "just
     * completed" twice for the same participation — the caller (routes.php)
     * is responsible for actually sending the mail with whatever it gets
     * back here.
     *
     * E6.3 (issue 15 §3): "completed" is now determined by the real
     * condition tree (ChallengeConditionTreeEvaluator) when the challenge has
     * one, not the legacy flat `rule.minCompleted` count — see
     * isChallengeCompletedForUser()'s doc comment for the fallback rule and
     * why it still exists.
     *
     * @return array{challengeId: string, userId: string, competitorId: ?string, challengeName: string}|null
     */
    public function checkCompletion(string $challengeId, string $userId, ?string $competitorId = null): ?array
    {
        $challenge = $this->find($challengeId);
        if ($challenge === null) {
            return null;
        }

        $participation = $this->findParticipationRow($challengeId, $userId, $competitorId);
        if ($participation === null || $participation['completionNotifiedAt'] !== null) {
            return null;
        }
        $rowCompetitorId = $participation['competitorId'] !== null ? (string) $participation['competitorId'] : null;

        if (!$this->isChallengeCompletedForUser($challenge, $userId, $rowCompetitorId)) {
            return null;
        }

        $this->updateById('challenge_participations', (string) $participation['id'], [
            'completion_notified_at' => date(DATE_ATOM),
        ]);

        return ['challengeId' => $challengeId, 'userId' => $userId, 'competitorId' => $rowCompetitorId, 'challengeName' => (string) $challenge['name']];
    }

    /**
     * E6.3 (issue 15 §3): the single source of truth for "has $userId
     * completed $challenge", shared by checkCompletion() (the mail-gate) and
     * hydrateParticipation() (the `isCompleted` field returned on every
     * participation read).
     *
     * Uses the full recursive condition-tree evaluation
     * (ChallengeConditionTreeEvaluator) when the challenge HAS a tree — which
     * is every challenge created/updated through ChallengeRepository::create()
     * (it always seeds a default tree) and every pre-E6.1 challenge touched
     * by migration 054's backfill. Falls back to the legacy flat
     * `completedCount >= rule.minCompleted` check ONLY when
     * `getConditionTree()` returns null — a genuinely tree-less row, which in
     * practice only happens for rows inserted directly via SQL bypassing the
     * repository (as several of this repo's own integration test fixtures
     * do). This fallback is deliberate, not a workaround: a challenge with no
     * tree at all has nothing for the evaluator to walk, and refusing to
     * ever mark it complete would be a silent regression for that legacy
     * shape rather than the ticket's intended "the tree becomes the real
     * rule".
     *
     * Stale-tree handling (E6.2's `conditionTreeMatchesEvents` flag, issue 15
     * §3 point 3): a `false` flag does NOT block automatic evaluation here —
     * most of the time it correctly means "deliberately customized tree"
     * (a `group`/`point_threshold`/`sub_challenge_threshold` root, or an
     * event_threshold root with a time window), which is completely expected
     * and must still drive completion normally. The one case worth an admin's
     * attention is a root that is STILL shaped like the plain default
     * (`event_threshold`, no window) but whose linked events no longer match
     * the challenge's current flat `events` list — that specific shape is
     * logged as a warning (not blocked) so completions still compute
     * correctly for everyone else while the drift is visible in the logs.
     *
     * @param array<string, mixed> $challenge as returned by find()/hydrate()
     */
    private function isChallengeCompletedForUser(array $challenge, string $userId, ?string $competitorId = null): bool
    {
        $tree = $challenge['conditionTree'] ?? null;
        if ($tree === null) {
            $completedCount = count(array_filter(
                $this->entries((string) $challenge['id'], $userId, $competitorId),
                static fn (array $entry): bool => $entry['status'] === 'approved'
            ));
            return $completedCount >= (int) ($challenge['rule']['minCompleted'] ?? 1);
        }

        if (
            ($challenge['conditionTreeMatchesEvents'] ?? true) === false
            && ($tree['type'] ?? null) === 'event_threshold'
            && ($tree['windowStart'] ?? null) === null
            && ($tree['windowEnd'] ?? null) === null
        ) {
            error_log(sprintf(
                '[ChallengeConditionTreeEvaluator] Challenge %s: condition tree looks like the plain default '
                . '(event_threshold, no window) but no longer matches the flat events list (conditionTreeMatchesEvents=false) '
                . '— evaluating against the tree as-is; an admin may want to re-check this challenge\'s tree.',
                (string) $challenge['id']
            ));
        }

        $memo = [];
        try {
            return $this->treeEvaluator()->evaluateNode($tree, $userId, $memo, 0, (string) $challenge['id'], $competitorId);
        } catch (\RuntimeException $e) {
            error_log('[ChallengeConditionTreeEvaluator] ' . $e->getMessage());
            return false;
        }
    }

    /** Lazily builds the E6.3 tree evaluator — see its property doc comment. */
    private function treeEvaluator(): ChallengeConditionTreeEvaluator
    {
        if ($this->treeEvaluator === null) {
            $pointsSettings = new ChallengePointsSettingsRepository($this->pdo);
            $scorer = new ChallengePointThresholdScorer($this->pdo, $pointsSettings);
            $this->treeEvaluator = new ChallengeConditionTreeEvaluator($this->pdo, $this, $scorer);
        }
        return $this->treeEvaluator;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        $row['rule'] = [
            'minCompleted' => (int) ($row['minCompleted'] ?? 1),
            'maxTime' => $row['maxTime'] ?: null,
        ];
        unset($row['minCompleted'], $row['maxTime']);

        $events = $this->pdo->prepare('SELECT ce.event_id AS eventId, e.name AS eventName, e.date AS eventDate FROM challenge_events ce LEFT JOIN events e ON e.id = ce.event_id WHERE ce.challenge_id = :id ORDER BY ce.sort_order ASC');
        $events->execute(['id' => $row['id']]);
        $row['events'] = array_map(static fn (array $event): array => [
            'eventId' => $event['eventId'],
            'eventName' => $event['eventName'] ?? $event['eventId'],
            'eventDate' => $event['eventDate'] ?? '',
        ], $events->fetchAll());

        $products = $this->pdo->prepare('SELECT cp.product_id AS productId, cp.purpose, cp.sort_order AS sortOrder, p.name, p.price, p.sizes, p.description FROM challenge_products cp LEFT JOIN shop_products p ON p.id = cp.product_id WHERE cp.challenge_id = :id ORDER BY cp.sort_order ASC');
        $products->execute(['id' => $row['id']]);
        $row['products'] = [];
        $row['rewards'] = [];
        foreach ($products->fetchAll() as $product) {
            $row['products'][] = [
                'productId' => $product['productId'],
                'purpose' => $product['purpose'],
                'sortOrder' => (int) $product['sortOrder'],
            ];
            if ($product['purpose'] === 'reward') {
                $row['rewards'][] = [
                    'id' => $product['productId'],
                    'productId' => $product['productId'],
                    'name' => $product['name'] ?? $product['productId'],
                    'type' => ((float) ($product['price'] ?? 0)) > 0 ? 'product' : 'free',
                    'price' => (float) ($product['price'] ?? 0),
                    'sizes' => $product['sizes'] ? json_decode((string) $product['sizes'], true) : [],
                    'description' => $product['description'] ?? null,
                ];
            }
        }
        $tree = $this->getConditionTree((string) $row['id']);
        $row['conditionTree'] = $tree;
        // E6.2 resync-gap guard (see update()'s doc comment): purely
        // descriptive - true means the tree is still the plain
        // "ukoncz wszystkie flat Wydarzenia" default shape, false means
        // either it was deliberately customized (expected) or it has
        // genuinely drifted from an `events` edit this repository couldn't
        // safely auto-resync (worth a human glance in the admin UI).
        $row['conditionTreeMatchesEvents'] = $this->isDefaultEventThresholdTree(
            $tree,
            array_column($row['events'], 'eventId')
        );
        return $row;
    }

    /** @param array<string, mixed> $row */
    private function hydrateParticipation(array $row): array
    {
        $row['rewardGranted'] = (bool) $row['rewardGranted'];
        $row['selectedRewards'] = $row['selectedRewards'] ? json_decode((string) $row['selectedRewards'], true) : [];
        $row['assignedNumber'] = $row['assignedNumber'] !== null ? (int) $row['assignedNumber'] : null;
        $competitorId = $row['competitorId'] !== null ? (string) $row['competitorId'] : null;
        $row['competitorId'] = $competitorId;
        $row['competitorName'] = $competitorId !== null && trim((string) ($row['competitorName'] ?? '')) !== '' ? trim((string) $row['competitorName']) : null;
        $entries = $this->entries((string) $row['challengeId'], (string) $row['userId'], $competitorId);
        $row['entries'] = $entries;
        $row['completedCount'] = count(array_filter($entries, static fn (array $entry): bool => $entry['status'] === 'approved'));
        $challenge = $this->find((string) $row['challengeId']);
        // E6.3: real tree-based completion (falls back to the flat
        // completedCount >= minCompleted rule for tree-less legacy rows —
        // see isChallengeCompletedForUser()'s doc comment).
        $row['isCompleted'] = $challenge !== null && $this->isChallengeCompletedForUser($challenge, (string) $row['userId'], $competitorId);
        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    private function entries(string $challengeId, string $userId, ?string $competitorId = null): array
    {
        $who = $competitorId !== null ? '(competitor_id = :competitorId OR (competitor_id IS NULL AND user_id = :userId))' : 'user_id = :userId';
        $stmt = $this->pdo->prepare('SELECT id, challenge_id AS challengeId, user_id AS userId, competitor_id AS competitorId, event_id AS eventId, status, submitted_time AS submittedTime, submitted_position AS submittedPosition, proof_url AS proofUrl, submitted_at AS submittedAt, reviewed_at AS reviewedAt, reviewed_by AS reviewedBy, rejection_reason AS rejectionReason FROM challenge_entries WHERE challenge_id = :challengeId AND ' . $who . ' ORDER BY submitted_at ASC, id ASC');
        $stmt->execute($competitorId !== null ? ['challengeId' => $challengeId, 'competitorId' => $competitorId, 'userId' => $userId] : ['challengeId' => $challengeId, 'userId' => $userId]);
        return array_map(static fn (array $entry): array => [
            ...$entry,
            'submittedPosition' => $entry['submittedPosition'] !== null ? (int) $entry['submittedPosition'] : null,
        ], $stmt->fetchAll());
    }

    private function entry(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, challenge_id AS challengeId, user_id AS userId, competitor_id AS competitorId, event_id AS eventId, status, submitted_time AS submittedTime, submitted_position AS submittedPosition, proof_url AS proofUrl, submitted_at AS submittedAt, reviewed_at AS reviewedAt, reviewed_by AS reviewedBy, rejection_reason AS rejectionReason FROM challenge_entries WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['submittedPosition'] = $row['submittedPosition'] !== null ? (int) $row['submittedPosition'] : null;
        return $row;
    }

    /**
     * G-02 (spec 15 §3): result-derived progress follows the results. Called by the write paths
     * that publish or change results of an Event (new result, correction, delete, preliminary /
     * official publication, import commit). Recomputes - for every participant of every Wyzwanie
     * that contains the Event and has a result there (or a result-derived entry left from one) -
     * the result-sourced entries, and returns those participations so the caller can run the
     * idempotent completion check (checkCompletion() + mail #13).
     *
     * R-06: a participation with a Zawodnik is matched through ChallengeResultOwnership (so a ward's
     * result touches the ward's row, not the caregiver's own); a row without one by `user_id`.
     *
     * @return array<int, array{challengeId: string, userId: string, competitorId: ?string}>
     */
    public function syncForEvent(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT cp.challenge_id AS challengeId, cp.user_id AS userId, cp.competitor_id AS competitorId
             FROM challenge_participations cp
             INNER JOIN challenge_events ce ON ce.challenge_id = cp.challenge_id AND ce.event_id = :eventId1
             WHERE (cp.competitor_id IS NULL AND EXISTS (SELECT 1 FROM results r WHERE r.event_id = ce.event_id AND r.user_id = cp.user_id))
                OR (cp.competitor_id IS NOT NULL AND EXISTS (SELECT 1 FROM results r' . ChallengeResultOwnership::JOINS . ' WHERE r.event_id = ce.event_id AND ' . ChallengeResultOwnership::COMPETITOR_EXPR . ' = cp.competitor_id))
                OR EXISTS (SELECT 1 FROM challenge_entries e WHERE e.challenge_id = cp.challenge_id AND (e.competitor_id = cp.competitor_id OR (e.competitor_id IS NULL AND e.user_id = cp.user_id)) AND e.event_id = ce.event_id AND e.source = \'result\')'
        );
        $stmt->execute(['eventId1' => $eventId]);
        $touched = [];
        foreach ($stmt->fetchAll() as $row) {
            $competitorId = $row['competitorId'] !== null ? (string) $row['competitorId'] : null;
            $this->syncResultEntries((string) $row['challengeId'], (string) $row['userId'], $competitorId);
            $touched[] = ['challengeId' => (string) $row['challengeId'], 'userId' => (string) $row['userId'], 'competitorId' => $competitorId];
        }

        return $touched;
    }

    private function entryFor(string $challengeId, string $userId, ?string $competitorId, string $eventId): ?array
    {
        // A Zawodnik's entry; a legacy row (NULL competitor) counts only for the Konto's own profile.
        if ($competitorId !== null && $competitorId !== $this->selfCompetitorId($userId)) {
            $stmt = $this->pdo->prepare('SELECT id FROM challenge_entries WHERE challenge_id = :challengeId AND competitor_id = :competitorId AND event_id = :eventId');
            $stmt->execute(['challengeId' => $challengeId, 'competitorId' => $competitorId, 'eventId' => $eventId]);
        } elseif ($competitorId !== null) {
            $stmt = $this->pdo->prepare('SELECT id FROM challenge_entries WHERE challenge_id = :challengeId AND (competitor_id = :competitorId OR (competitor_id IS NULL AND user_id = :userId)) AND event_id = :eventId ORDER BY competitor_id IS NULL ASC LIMIT 1');
            $stmt->execute(['challengeId' => $challengeId, 'competitorId' => $competitorId, 'userId' => $userId, 'eventId' => $eventId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT id FROM challenge_entries WHERE challenge_id = :challengeId AND user_id = :userId AND competitor_id IS NULL AND event_id = :eventId');
            $stmt->execute(['challengeId' => $challengeId, 'userId' => $userId, 'eventId' => $eventId]);
        }
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * E1.10 note: this auto-approval path can also cross a challenge's
     * completion threshold, but checkCompletion() is deliberately not called
     * from *this* method — it also runs from participations(), a GET
     * endpoint, and a mail send should never be a side effect of a read.
     * Instead, the write path that can trigger a result-derived completion —
     * join(), which calls this method directly — checks completion itself
     * right after syncing (see routes.php's $notifyChallengeCompletion,
     * used by both the join and manual-review routes). checkCompletion()'s
     * own idempotency (completion_notified_at) makes it safe to call from
     * multiple write paths without risk of double-sending mail #13.
     */
    private function syncResultEntries(string $challengeId, string $userId, ?string $competitorId = null): void
    {
        // R-06: with a Zawodnik, progress comes from that Zawodnik's results (ChallengeResultOwnership);
        // without one (Konto with no profile) from the Konto's own results, as before.
        if ($competitorId !== null) {
            $stmt = $this->pdo->prepare("SELECT r.event_id AS eventId, r.time, r.position FROM results r" . ChallengeResultOwnership::JOINS . " INNER JOIN challenge_events ce ON ce.event_id = r.event_id WHERE ce.challenge_id = :challengeId AND " . ChallengeResultOwnership::COMPETITOR_EXPR . " = :competitorId AND r.status = 'finished'");
            $stmt->execute(['challengeId' => $challengeId, 'competitorId' => $competitorId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT r.event_id AS eventId, r.time, r.position FROM results r INNER JOIN challenge_events ce ON ce.event_id = r.event_id WHERE ce.challenge_id = :challengeId AND r.user_id = :userId AND r.status = 'finished'");
            $stmt->execute(['challengeId' => $challengeId, 'userId' => $userId]);
        }
        foreach ($stmt->fetchAll() as $result) {
            $existing = $this->entryFor($challengeId, $userId, $competitorId, (string) $result['eventId']);
            if ($existing) {
                continue;
            }
            $this->insert('challenge_entries', [
                'id' => $this->id(),
                'challenge_id' => $challengeId,
                'user_id' => $userId,
                'competitor_id' => $competitorId,
                'event_id' => $result['eventId'],
                'status' => 'approved',
                'submitted_time' => $result['time'],
                'submitted_position' => $result['position'],
                'proof_url' => null,
                'submitted_at' => date(DATE_ATOM),
                'reviewed_at' => date(DATE_ATOM),
                'reviewed_by' => 'system',
                'source' => 'result',
            ]);
        }
        // G-02: a correction (or deletion) can take a result out of "finished"; the entry it
        // produced must go with it. Only system-derived entries are touched - a manual entry
        // approved by a reviewer stays.
        if ($competitorId !== null) {
            $legacy = $competitorId === $this->selfCompetitorId($userId) ? ' OR (e.competitor_id IS NULL AND e.user_id = :userId)' : '';
            $params = ['challengeId' => $challengeId, 'competitorId' => $competitorId, 'competitorId2' => $competitorId];
            if ($legacy !== '') {
                $params['userId'] = $userId;
            }
            $this->pdo->prepare(
                "DELETE e FROM challenge_entries e
                 WHERE e.challenge_id = :challengeId AND (e.competitor_id = :competitorId{$legacy}) AND e.source = 'result'
                   AND NOT EXISTS (SELECT 1 FROM results r" . ChallengeResultOwnership::JOINS . " WHERE r.event_id = e.event_id AND " . ChallengeResultOwnership::COMPETITOR_EXPR . " = :competitorId2 AND r.status = 'finished')"
            )->execute($params);
            return;
        }
        $this->pdo->prepare(
            "DELETE e FROM challenge_entries e
             WHERE e.challenge_id = :challengeId AND e.user_id = :userId AND e.competitor_id IS NULL AND e.source = 'result'
               AND NOT EXISTS (SELECT 1 FROM results r WHERE r.event_id = e.event_id AND r.user_id = e.user_id AND r.status = 'finished')"
        )->execute(['challengeId' => $challengeId, 'userId' => $userId]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function challengeData(string $id, array $data, bool $creating): array
    {
        $out = $creating ? ['id' => $id, 'status' => 'upcoming'] : [];
        foreach ([
            'name' => 'name',
            'slug' => 'slug',
            'description' => 'description',
            'imageUrl' => 'image_url',
            'seriesId' => 'series_id',
            'startDate' => 'start_date',
            'endDate' => 'end_date',
            'status' => 'status',
            'createdBy' => 'created_by',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $out[$column] = $input === 'status' ? $this->status((string) $data[$input]) : $data[$input];
            }
        }
        if (isset($data['rule']) && is_array($data['rule'])) {
            $out['min_completed'] = max(1, (int) ($data['rule']['minCompleted'] ?? 1));
            $out['max_time'] = $data['rule']['maxTime'] ?? null;
        }
        if (array_key_exists('minCompleted', $data)) {
            $out['min_completed'] = max(1, (int) $data['minCompleted']);
        }
        if (array_key_exists('maxTime', $data)) {
            $out['max_time'] = $data['maxTime'];
        }
        return $out;
    }

    /** @param array<int, mixed> $events */
    private function replaceEvents(string $challengeId, array $events): void
    {
        $this->pdo->prepare('DELETE FROM challenge_events WHERE challenge_id = :id')->execute(['id' => $challengeId]);
        foreach (array_values($events) as $index => $event) {
            $eventId = is_array($event) ? (string) ($event['eventId'] ?? '') : (string) $event;
            if ($eventId === '') {
                continue;
            }
            $this->insert('challenge_events', [
                'challenge_id' => $challengeId,
                'event_id' => $eventId,
                'sort_order' => $index,
            ]);
        }
    }

    /** @param array<int, mixed> $products */
    private function replaceProducts(string $challengeId, array $products): void
    {
        $this->pdo->prepare('DELETE FROM challenge_products WHERE challenge_id = :id')->execute(['id' => $challengeId]);
        foreach (array_values($products) as $index => $product) {
            $productId = is_array($product) ? (string) ($product['productId'] ?? $product['id'] ?? '') : (string) $product;
            if ($productId === '') {
                continue;
            }
            $purpose = is_array($product) && ($product['purpose'] ?? null) === 'addon' ? 'addon' : 'reward';
            $this->insert('challenge_products', [
                'challenge_id' => $challengeId,
                'product_id' => $productId,
                'purpose' => $purpose,
                'sort_order' => is_array($product) ? (int) ($product['sortOrder'] ?? $index) : $index,
            ]);
        }
    }

    private function status(string $status): string
    {
        return in_array($status, self::STATUSES, true) ? $status : 'upcoming';
    }
}

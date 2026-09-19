<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Challenges;

use ZmierzymyCzas\Domain\Repository;

final class ChallengeRepository extends Repository
{
    private const COLUMNS = 'id, name, slug, description, image_url AS imageUrl, series_id AS seriesId, min_completed AS minCompleted, max_time AS maxTime, start_date AS startDate, end_date AS endDate, status, created_by AS createdBy';
    private const STATUSES = ['active', 'completed', 'upcoming', 'archived'];
    private const ENTRY_STATUSES = ['pending', 'approved', 'rejected'];
    /** E6.1 condition-tree node types (issue 15 §1). */
    private const NODE_TYPES = ['group', 'event_threshold', 'point_threshold', 'sub_challenge_threshold'];
    private const LEAF_EVENT_NODE_TYPES = ['event_threshold', 'point_threshold'];

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

    /** @return array<int, array<string, mixed>> */
    public function participations(?string $challengeId = null, ?string $userId = null): array
    {
        if ($challengeId && $userId) {
            $this->syncResultEntries($challengeId, $userId);
        }
        $where = [];
        $params = [];
        if ($challengeId) {
            $where[] = 'challenge_id = :challengeId';
            $params['challengeId'] = $challengeId;
        }
        if ($userId) {
            $where[] = 'user_id = :userId';
            $params['userId'] = $userId;
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->pdo->prepare("SELECT id, challenge_id AS challengeId, user_id AS userId, user_name AS userName, joined_at AS joinedAt, reward_granted AS rewardGranted, reward_granted_at AS rewardGrantedAt, selected_rewards AS selectedRewards, assigned_number AS assignedNumber FROM challenge_participations{$whereSql} ORDER BY joined_at DESC");
        $stmt->execute($params);
        return array_map(fn (array $row): array => $this->hydrateParticipation($row), $stmt->fetchAll());
    }

    /** @param array<string, mixed> $user */
    public function join(string $challengeId, array $user): array
    {
        $existing = $this->participations($challengeId, (string) $user['id']);
        if ($existing !== []) {
            return $existing[0];
        }
        $id = $this->id();
        $this->insert('challenge_participations', [
            'id' => $id,
            'challenge_id' => $challengeId,
            'user_id' => $user['id'],
            'user_name' => $user['name'],
            'joined_at' => date(DATE_ATOM),
            'reward_granted' => 0,
            'selected_rewards' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $this->syncResultEntries($challengeId, (string) $user['id']);
        return $this->participations($challengeId, (string) $user['id'])[0];
    }

    /** @param array<string, mixed> $data */
    public function submitEntry(array $data, array $actor): array
    {
        $challengeId = (string) ($data['challengeId'] ?? '');
        $userId = (string) ($data['userId'] ?? $actor['sub'] ?? '');
        $eventId = (string) ($data['eventId'] ?? '');
        $existing = $this->entryFor($challengeId, $userId, $eventId);
        $payload = [
            'challenge_id' => $challengeId,
            'user_id' => $userId,
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
     * hydrateParticipation() only *computes* isCompleted on every read from the
     * live entries list (see its `completedCount >= minCompleted` check above).
     * This is the one materialized moment: called right after an entry review
     * (approve) or a result-derived auto-approval (syncResultEntries()), it
     * checks whether the just-updated entry count crosses the threshold for
     * the first time and, if so, marks `completion_notified_at` so it is never
     * reported "just completed" twice for the same participation — the caller
     * (routes.php) is responsible for actually sending the mail with whatever
     * it gets back here.
     *
     * @return array{challengeId: string, userId: string, challengeName: string}|null
     */
    public function checkCompletion(string $challengeId, string $userId): ?array
    {
        $challenge = $this->find($challengeId);
        if ($challenge === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, completion_notified_at AS completionNotifiedAt FROM challenge_participations WHERE challenge_id = :challengeId AND user_id = :userId'
        );
        $stmt->execute(['challengeId' => $challengeId, 'userId' => $userId]);
        $participation = $stmt->fetch();
        if ($participation === false || $participation['completionNotifiedAt'] !== null) {
            return null;
        }

        $completedCount = count(array_filter(
            $this->entries($challengeId, $userId),
            static fn (array $entry): bool => $entry['status'] === 'approved'
        ));
        if ($completedCount < (int) ($challenge['rule']['minCompleted'] ?? 1)) {
            return null;
        }

        $this->updateById('challenge_participations', (string) $participation['id'], [
            'completion_notified_at' => date(DATE_ATOM),
        ]);

        return ['challengeId' => $challengeId, 'userId' => $userId, 'challengeName' => (string) $challenge['name']];
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
        $entries = $this->entries((string) $row['challengeId'], (string) $row['userId']);
        $row['entries'] = $entries;
        $row['completedCount'] = count(array_filter($entries, static fn (array $entry): bool => $entry['status'] === 'approved'));
        $challenge = $this->find((string) $row['challengeId']);
        $row['isCompleted'] = $challenge ? $row['completedCount'] >= (int) ($challenge['rule']['minCompleted'] ?? 1) : false;
        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    private function entries(string $challengeId, string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, challenge_id AS challengeId, user_id AS userId, event_id AS eventId, status, submitted_time AS submittedTime, submitted_position AS submittedPosition, proof_url AS proofUrl, submitted_at AS submittedAt, reviewed_at AS reviewedAt, reviewed_by AS reviewedBy, rejection_reason AS rejectionReason FROM challenge_entries WHERE challenge_id = :challengeId AND user_id = :userId ORDER BY submitted_at ASC, id ASC');
        $stmt->execute(['challengeId' => $challengeId, 'userId' => $userId]);
        return array_map(static fn (array $entry): array => [
            ...$entry,
            'submittedPosition' => $entry['submittedPosition'] !== null ? (int) $entry['submittedPosition'] : null,
        ], $stmt->fetchAll());
    }

    private function entry(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, challenge_id AS challengeId, user_id AS userId, event_id AS eventId, status, submitted_time AS submittedTime, submitted_position AS submittedPosition, proof_url AS proofUrl, submitted_at AS submittedAt, reviewed_at AS reviewedAt, reviewed_by AS reviewedBy, rejection_reason AS rejectionReason FROM challenge_entries WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['submittedPosition'] = $row['submittedPosition'] !== null ? (int) $row['submittedPosition'] : null;
        return $row;
    }

    private function entryFor(string $challengeId, string $userId, string $eventId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM challenge_entries WHERE challenge_id = :challengeId AND user_id = :userId AND event_id = :eventId');
        $stmt->execute(['challengeId' => $challengeId, 'userId' => $userId, 'eventId' => $eventId]);
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
    private function syncResultEntries(string $challengeId, string $userId): void
    {
        $stmt = $this->pdo->prepare("SELECT r.event_id AS eventId, r.time, r.position FROM results r INNER JOIN challenge_events ce ON ce.event_id = r.event_id WHERE ce.challenge_id = :challengeId AND r.user_id = :userId AND r.status = 'finished'");
        $stmt->execute(['challengeId' => $challengeId, 'userId' => $userId]);
        foreach ($stmt->fetchAll() as $result) {
            if ($this->entryFor($challengeId, $userId, (string) $result['eventId'])) {
                continue;
            }
            $this->insert('challenge_entries', [
                'id' => $this->id(),
                'challenge_id' => $challengeId,
                'user_id' => $userId,
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

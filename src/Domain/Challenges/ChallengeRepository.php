<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Challenges;

use ZmierzymyCzas\Domain\Repository;

final class ChallengeRepository extends Repository
{
    private const COLUMNS = 'id, name, slug, description, image_url AS imageUrl, series_id AS seriesId, min_completed AS minCompleted, max_time AS maxTime, start_date AS startDate, end_date AS endDate, status, created_by AS createdBy';
    private const STATUSES = ['active', 'completed', 'upcoming', 'archived'];
    private const ENTRY_STATUSES = ['pending', 'approved', 'rejected'];

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
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->find($id);
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $this->updateById('challenges', $id, $this->challengeData($id, $data, false));
            if (array_key_exists('events', $data)) {
                $this->replaceEvents($id, $data['events'] ?? []);
            }
            if (array_key_exists('products', $data) || array_key_exists('rewards', $data)) {
                $this->replaceProducts($id, $data['products'] ?? $data['rewards'] ?? []);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->find($id);
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
     * here — this method runs from participations(), a GET endpoint, and a
     * mail send should never be a side effect of a read. The manual moderation
     * path (reviewEntry()) is where mail #13 is actually wired; a result-driven
     * completion is picked up the next time that Zawodnik's entries are
     * reviewed or participations() is polled from a write-adjacent action.
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

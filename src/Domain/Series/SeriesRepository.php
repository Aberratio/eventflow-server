<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Series;

use ZmierzymyCzas\Domain\Repository;

final class SeriesRepository extends Repository
{
    private const COLUMNS = 'id, name, slug, type, description, organizer_id AS organizerId, organizer_name AS organizerName, rules, start_date AS startDate, end_date AS endDate, status';
    private const STATUSES = ['active', 'completed', 'upcoming', 'archived'];

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, pageSize: int}
     */
    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        foreach (['type' => 'type', 'status' => 'status'] as $input => $column) {
            if (!empty($filters[$input])) {
                $where[] = "{$column} = :{$input}";
                $params[$input] = $filters[$input];
            }
        }
        if (empty($filters['includeArchived'])) {
            $where[] = "status <> 'archived'";
        }
        if (!empty($filters['search'])) {
            $where[] = 'name LIKE :search';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pagination = $this->pagination($filters);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM series{$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . " FROM series{$whereSql} ORDER BY start_date DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $pagination['pageSize'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $pagination['offset'], \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll()),
            'total' => $total,
            'page' => $pagination['page'],
            'pageSize' => $pagination['pageSize'],
        ];
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM series WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare("SELECT " . self::COLUMNS . " FROM series WHERE slug = :slug AND status <> 'archived'");
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, array $actor): array
    {
        $id = $this->id();
        $payload = $this->data($id, $data, $actor, true);
        $this->pdo->beginTransaction();
        try {
            $this->insert('series', $payload);
            $this->replaceEvents($id, $data['eventIds'] ?? []);
            $this->replaceProducts($id, $data['products'] ?? []);
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
            $this->updateById('series', $id, $this->data($id, $data, [], false));
            if (array_key_exists('eventIds', $data)) {
                $this->replaceEvents($id, $data['eventIds'] ?? []);
            }
            if (array_key_exists('products', $data)) {
                $this->replaceProducts($id, $data['products'] ?? []);
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
        $this->updateById('series', $id, ['status' => 'archived']);
        return $this->find($id);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        $stmt = $this->pdo->prepare('SELECT event_id FROM series_events WHERE series_id = :id ORDER BY sort_order ASC');
        $stmt->execute(['id' => $row['id']]);
        $row['eventIds'] = array_column($stmt->fetchAll(), 'event_id');
        $products = $this->pdo->prepare('SELECT product_id AS productId, purpose, sort_order AS sortOrder FROM series_products WHERE series_id = :id ORDER BY sort_order ASC');
        $products->execute(['id' => $row['id']]);
        $row['products'] = array_map(static fn (array $product): array => [
            'productId' => $product['productId'],
            'purpose' => $product['purpose'],
            'sortOrder' => (int) $product['sortOrder'],
        ], $products->fetchAll());
        return $row;
    }

    /** @param array<string, mixed> $data */
    private function data(string $id, array $data, array $actor, bool $creating): array
    {
        $out = $creating ? [
            'id' => $id,
            'organizer_id' => (string) ($data['organizerId'] ?? $actor['sub'] ?? ''),
            'organizer_name' => (string) ($data['organizerName'] ?? $actor['email'] ?? 'ZmierzymyCzas'),
            'status' => 'upcoming',
        ] : [];

        foreach ([
            'name' => 'name',
            'slug' => 'slug',
            'type' => 'type',
            'description' => 'description',
            'organizerId' => 'organizer_id',
            'organizerName' => 'organizer_name',
            'rules' => 'rules',
            'startDate' => 'start_date',
            'endDate' => 'end_date',
            'status' => 'status',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $out[$column] = $input === 'status' ? $this->status((string) $data[$input]) : $data[$input];
            }
        }
        return $out;
    }

    /**
     * TD.23: batched into one multi-row INSERT and one multi-row UPDATE
     * instead of two queries per event — same 0-based `sort_order`
     * (unchanged from the original `$index`), same `events.series_id`
     * backfill for every listed event, same no-op on an empty list (beyond
     * the DELETE, which always runs). Both statements execute inside the
     * same transaction create()/update() already wrap this call in, same as
     * before.
     *
     * Native prepares (PDO::ATTR_EMULATE_PREPARES => false, see Connection)
     * reject a named placeholder used more than once in the same query, so
     * `:seriesId` needs its own binding per VALUES group in the INSERT
     * (same pattern as ShopRepository/FormFieldRepository::reorder()) — the
     * single UPDATE only uses `:seriesId` once, so it doesn't need this.
     *
     * @param array<int, string> $eventIds
     */
    private function replaceEvents(string $seriesId, array $eventIds): void
    {
        $this->pdo->prepare('DELETE FROM series_events WHERE series_id = :id')->execute(['id' => $seriesId]);

        $ids = array_values($eventIds);
        if ($ids === []) {
            return;
        }

        $eventIdParams = [];
        foreach ($ids as $index => $eventId) {
            $eventIdParams['eventId' . $index] = (string) $eventId;
        }

        $insertGroups = [];
        $insertParams = [];
        foreach (array_keys($eventIdParams) as $index => $paramName) {
            $seriesIdParam = 'seriesId' . $index;
            $insertGroups[] = "(:{$seriesIdParam}, :{$paramName}, :sortOrder{$index})";
            $insertParams[$seriesIdParam] = $seriesId;
            $insertParams[$paramName] = $eventIdParams[$paramName];
            $insertParams['sortOrder' . $index] = $index;
        }
        $this->pdo->prepare(
            'INSERT INTO series_events (series_id, event_id, sort_order) VALUES ' . implode(', ', $insertGroups)
        )->execute($insertParams);

        $eventPlaceholders = implode(', ', array_map(
            static fn (string $param): string => ':' . $param,
            array_keys($eventIdParams)
        ));
        $this->pdo->prepare('UPDATE events SET series_id = :seriesId WHERE id IN (' . $eventPlaceholders . ')')
            ->execute(['seriesId' => $seriesId] + $eventIdParams);
    }

    /** @param array<int, array<string, mixed>> $products */
    private function replaceProducts(string $seriesId, array $products): void
    {
        $this->pdo->prepare('DELETE FROM series_products WHERE series_id = :id')->execute(['id' => $seriesId]);
        foreach (array_values($products) as $index => $product) {
            $productId = (string) ($product['productId'] ?? '');
            if ($productId === '') {
                continue;
            }
            $this->insert('series_products', [
                'series_id' => $seriesId,
                'product_id' => $productId,
                'purpose' => in_array(($product['purpose'] ?? 'reward'), ['reward', 'addon'], true) ? $product['purpose'] : 'reward',
                'sort_order' => (int) ($product['sortOrder'] ?? $index),
            ]);
        }
    }

    private function status(string $status): string
    {
        return in_array($status, self::STATUSES, true) ? $status : 'upcoming';
    }
}

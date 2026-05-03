<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Series;

use ZmierzymyCzas\Domain\Repository;

final class SeriesRepository extends Repository
{
    private const COLUMNS = 'id, name, slug, type, description, organizer_id AS organizerId, organizer_name AS organizerName, rules, start_date AS startDate, end_date AS endDate, status';

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
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM series WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        $stmt = $this->pdo->prepare('SELECT event_id FROM series_events WHERE series_id = :id ORDER BY sort_order ASC');
        $stmt->execute(['id' => $row['id']]);
        $row['eventIds'] = array_column($stmt->fetchAll(), 'event_id');
        return $row;
    }
}

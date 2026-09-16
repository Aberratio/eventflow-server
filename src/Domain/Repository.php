<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain;

use PDO;
use ZmierzymyCzas\Support\Uuid;

abstract class Repository
{
    public function __construct(protected readonly PDO $pdo)
    {
    }

    protected function id(): string
    {
        return Uuid::v4();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $jsonFields
     * @return array<string, mixed>
     */
    protected function encode(array $data, array $jsonFields = []): array
    {
        foreach ($jsonFields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && !is_string($data[$field])) {
                $data[$field] = json_encode($data[$field], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed>|false|null $row
     * @param array<int, string> $jsonFields
     * @return array<string, mixed>|null
     */
    protected function decode(array|false|null $row, array $jsonFields = []): ?array
    {
        if (!$row) {
            return null;
        }

        foreach ($jsonFields as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $row[$field] !== null && $row[$field] !== ''
                    ? json_decode((string) $row[$field], true)
                    : null;
            }
        }

        foreach ($row as $key => $value) {
            if (is_int($value) && str_starts_with($key, 'is_')) {
                $row[$key] = (bool) $value;
            }
            if (is_int($value) && in_array($key, ['featured', 'published', 'showInFooter', 'showInHeader'], true)) {
                $row[$key] = (bool) $value;
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function insert(string $table, array $data): void
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns)),
            implode(', ', $placeholders)
        );

        $this->pdo->prepare($sql)->execute($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function updateById(string $table, string $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $assignments = array_map(static fn (string $column): string => "`{$column}` = :{$column}", array_keys($data));
        $data['id'] = $id;
        $sql = sprintf('UPDATE %s SET %s WHERE id = :id', $table, implode(', ', $assignments));

        $this->pdo->prepare($sql)->execute($data);
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, string> $allowed
     * @return array{where: string, params: array<string, mixed>}
     */
    protected function exactFilters(array $filters, array $allowed): array
    {
        $where = [];
        $params = [];
        foreach ($allowed as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $where[] = "{$field} = :{$field}";
                $params[$field] = $filters[$field];
            }
        }

        return ['where' => $where ? ' WHERE ' . implode(' AND ', $where) : '', 'params' => $params];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{page: int, pageSize: int, offset: int}
     */
    protected function pagination(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($filters['pageSize'] ?? 25)));

        return ['page' => $page, 'pageSize' => $pageSize, 'offset' => ($page - 1) * $pageSize];
    }
}

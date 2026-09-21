<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Events;

use ZmierzymyCzas\Domain\Repository;

final class EventBackgroundRepository extends Repository
{
    private const COLUMNS = 'id, name, image_url AS imageUrl, thumbnail_url AS thumbnailUrl, sort_order AS sortOrder, created_at AS createdAt';

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        $stmt = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM event_backgrounds ORDER BY sort_order ASC, name ASC');
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM event_backgrounds WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data): array
    {
        $id = $this->id();
        $this->insert('event_backgrounds', [
            'id' => $id,
            'name' => trim((string) ($data['name'] ?? '')),
            'image_url' => trim((string) ($data['imageUrl'] ?? '')),
            'thumbnail_url' => $this->nullableString($data['thumbnailUrl'] ?? null),
            'sort_order' => (int) ($data['sortOrder'] ?? $this->nextSortOrder()),
        ]);
        return $this->find($id) ?? [];
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|null */
    public function update(string $id, array $data): ?array
    {
        $mapped = [];
        if (array_key_exists('name', $data)) {
            $mapped['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('imageUrl', $data)) {
            $mapped['image_url'] = trim((string) $data['imageUrl']);
        }
        if (array_key_exists('thumbnailUrl', $data)) {
            $mapped['thumbnail_url'] = $this->nullableString($data['thumbnailUrl']);
        }
        if (array_key_exists('sortOrder', $data)) {
            $mapped['sort_order'] = (int) $data['sortOrder'];
        }
        $this->updateById('event_backgrounds', $id, $mapped);
        return $this->find($id);
    }

    /**
     * Returns list of events using this background.
     * @return array<int, array<string, mixed>>
     */
    public function usages(string $id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, name, slug, publication_status AS status FROM events WHERE background_id = :id ORDER BY date ASC"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetchAll();
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM event_backgrounds WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function nextSortOrder(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM event_backgrounds')->fetchColumn();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}

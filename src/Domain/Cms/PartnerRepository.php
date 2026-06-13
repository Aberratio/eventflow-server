<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Cms;

use ZmierzymyCzas\Domain\Repository;

final class PartnerRepository extends Repository
{
    private const COLUMNS = 'id, name, logo_url AS logoUrl, logo_dark_url AS logoDarkUrl, website_url AS websiteUrl, featured';

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        $stmt = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM partners ORDER BY sort_order ASC, name ASC');
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function featured(): array
    {
        $stmt = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM partners WHERE featured = 1 ORDER BY sort_order ASC, name ASC');
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM partners WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data): array
    {
        $id = $data['id'] ?? $this->id();
        $sortOrder = $data['sortOrder'] ?? $this->nextSortOrder();
        $this->insert('partners', [
            'id' => $id,
            'name' => trim((string) ($data['name'] ?? '')),
            'logo_url' => trim((string) ($data['logoUrl'] ?? '')),
            'logo_dark_url' => $this->nullableString($data['logoDarkUrl'] ?? null),
            'website_url' => $this->nullableString($data['websiteUrl'] ?? null),
            'featured' => !empty($data['featured']) ? 1 : 0,
            'sort_order' => (int) $sortOrder,
        ]);

        return $this->find((string) $id) ?? [];
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|null */
    public function update(string $id, array $data): ?array
    {
        $mapped = [];
        if (array_key_exists('name', $data)) {
            $mapped['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('logoUrl', $data)) {
            $mapped['logo_url'] = trim((string) $data['logoUrl']);
        }
        if (array_key_exists('logoDarkUrl', $data)) {
            $mapped['logo_dark_url'] = $this->nullableString($data['logoDarkUrl']);
        }
        if (array_key_exists('websiteUrl', $data)) {
            $mapped['website_url'] = $this->nullableString($data['websiteUrl']);
        }
        if (array_key_exists('featured', $data)) {
            $mapped['featured'] = !empty($data['featured']) ? 1 : 0;
        }
        if (array_key_exists('sortOrder', $data)) {
            $mapped['sort_order'] = (int) $data['sortOrder'];
        }

        $this->updateById('partners', $id, $mapped);
        return $this->find($id);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM partners WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        $row['featured'] = (bool) $row['featured'];
        return $row;
    }

    private function nextSortOrder(): int
    {
        return ((int) $this->pdo->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM partners')->fetchColumn());
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}

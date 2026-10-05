<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Cms;

use ZmierzymyCzas\Domain\Repository;
use ZmierzymyCzas\Support\InputRules;

final class InfoPageRepository extends Repository
{
    /** PP.76: request-body rules for POST/PATCH /info-pages (see InputRules). */
    private const INPUT_RULES = [
        'title' => [InputRules::TEXT_REQUIRED, 'Tytuł strony'],
        'slug' => [InputRules::TEXT_REQUIRED, 'Adres URL (slug) strony'],
        'icon' => [InputRules::TEXT, 'Ikona strony'],
        'description' => [InputRules::TEXT, 'Opis strony'],
        'published' => [InputRules::BOOL, 'Opublikowana'],
        'showInFooter' => [InputRules::BOOL, 'Pokaż w stopce'],
        'showInHeader' => [InputRules::BOOL, 'Pokaż w nagłówku'],
        'order' => [InputRules::INT, 'Kolejność'],
    ];

    private const COLUMNS = 'id, title, slug, icon, description, content, published, show_in_footer AS showInFooter, show_in_header AS showInHeader, sort_order AS `order`, created_at AS createdAt, updated_at AS updatedAt';

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        $stmt = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM info_pages ORDER BY sort_order ASC');
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM info_pages WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM info_pages WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        InputRules::check($data, self::INPUT_RULES, true);
        $id = $this->id();
        $this->insert('info_pages', [
            'id' => $id,
            'title' => $data['title'],
            'slug' => $data['slug'],
            'icon' => $data['icon'] ?? 'FileText',
            'description' => $data['description'] ?? '',
            'content' => json_encode($data['content'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'published' => (int) ($data['published'] ?? false),
            'show_in_footer' => (int) ($data['showInFooter'] ?? false),
            'show_in_header' => (int) ($data['showInHeader'] ?? false),
            'sort_order' => $data['order'] ?? 99,
            'created_at' => date(DATE_ATOM),
            'updated_at' => date(DATE_ATOM),
        ]);
        return $this->find($id);
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): ?array
    {
        InputRules::check($data, self::INPUT_RULES, false);
        $mapped = [];
        foreach ([
            'title' => 'title',
            'slug' => 'slug',
            'icon' => 'icon',
            'description' => 'description',
            'published' => 'published',
            'showInFooter' => 'show_in_footer',
            'showInHeader' => 'show_in_header',
            'order' => 'sort_order',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $mapped[$column] = is_bool($data[$input]) ? (int) $data[$input] : $data[$input];
            }
        }
        if (array_key_exists('content', $data)) {
            $mapped['content'] = json_encode($data['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $mapped['updated_at'] = date(DATE_ATOM);
        $this->updateById('info_pages', $id, $mapped);
        return $this->find($id);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM info_pages WHERE id = :id')->execute(['id' => $id]);
    }

    /** @param array<int, string> $ids */
    public function reorder(array $ids): void
    {
        foreach ($ids as $index => $id) {
            $this->pdo->prepare('UPDATE info_pages SET sort_order = :sortOrder, updated_at = :updatedAt WHERE id = :id')
                ->execute(['sortOrder' => $index, 'updatedAt' => date(DATE_ATOM), 'id' => $id]);
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        $row['content'] = $row['content'] ? json_decode((string) $row['content'], true) : [];
        $row['published'] = (bool) $row['published'];
        $row['showInFooter'] = (bool) $row['showInFooter'];
        $row['showInHeader'] = (bool) $row['showInHeader'];
        return $row;
    }
}

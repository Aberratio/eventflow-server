<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Shop;

use ZmierzymyCzas\Domain\Repository;

final class ShopRepository extends Repository
{
    private const PRODUCT_COLUMNS = <<<'SQL'
id, name, slug, description, price, original_price AS originalPrice, category, brand,
sizes, colors, image_url AS imageUrl, images, in_stock AS inStock, stock_count AS stockCount,
rating, review_count AS reviewCount, featured, is_service AS isService, service_details AS serviceDetails,
validity_days AS validityDays, visible_to_roles AS visibleToRoles, service_kind AS serviceKind, status
SQL;

    private const PRODUCT_JSON = ['sizes', 'colors', 'images', 'visibleToRoles'];

    /** @param array<string, mixed> $filters @return array{data: array<int, array<string, mixed>>, total: int, page: int, pageSize: int} */
    public function products(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (empty($filters['includeArchived'])) {
            $where[] = "status = 'active'";
        }
        foreach (['category' => 'category'] as $input => $column) {
            if (!empty($filters[$input])) {
                $where[] = "{$column} = :{$input}";
                $params[$input] = $filters[$input];
            }
        }
        if (array_key_exists('inStock', $filters) && $filters['inStock'] !== '') {
            $where[] = 'in_stock = :inStock';
            $params['inStock'] = filter_var($filters['inStock'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (!empty($filters['minPrice'])) {
            $where[] = 'price >= :minPrice';
            $params['minPrice'] = $filters['minPrice'];
        }
        if (!empty($filters['maxPrice'])) {
            $where[] = 'price <= :maxPrice';
            $params['maxPrice'] = $filters['maxPrice'];
        }
        if (!empty($filters['search'])) {
            // Native prepares (PDO::ATTR_EMULATE_PREPARES => false, see
            // Connection) reject a named placeholder used more than once in
            // the same query — each occurrence needs its own binding even
            // though the value is identical.
            $where[] = '(name LIKE :search1 OR description LIKE :search2 OR brand LIKE :search3)';
            $searchTerm = '%' . $filters['search'] . '%';
            $params['search1'] = $searchTerm;
            $params['search2'] = $searchTerm;
            $params['search3'] = $searchTerm;
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pagination = $this->pagination($filters);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM shop_products{$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT ' . self::PRODUCT_COLUMNS . " FROM shop_products{$whereSql} ORDER BY featured DESC, name ASC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $pagination['pageSize'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $pagination['offset'], \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => array_map(fn (array $row): array => $this->hydrateProduct($row), $stmt->fetchAll()),
            'total' => $total,
            'page' => $pagination['page'],
            'pageSize' => $pagination['pageSize'],
        ];
    }

    public function findProduct(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::PRODUCT_COLUMNS . ' FROM shop_products WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrateProduct($row) : null;
    }

    /**
     * E5.2: locks a shop_products row (SELECT ... FOR UPDATE) for the rest of
     * the caller's transaction — mirrors
     * RegistrationRepository::lockDistanceForRegistration() for the analogous
     * overselling race, here on a shop order's stock decrement instead of a
     * Dystans's registeredCount. Must only be called inside an already-open
     * PDO transaction (OrderRepository::create()).
     */
    public function lockProductForOrder(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::PRODUCT_COLUMNS . ' FROM shop_products WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrateProduct($row) : null;
    }

    public function findProductBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare("SELECT " . self::PRODUCT_COLUMNS . " FROM shop_products WHERE slug = :slug AND status = 'active'");
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ? $this->hydrateProduct($row) : null;
    }

    /** @param array<string, mixed> $data */
    public function createProduct(array $data): array
    {
        $id = $this->id();
        $this->insert('shop_products', $this->productData($id, $data, true));
        return $this->findProduct($id);
    }

    /** @param array<string, mixed> $data */
    public function updateProduct(string $id, array $data): ?array
    {
        $this->updateById('shop_products', $id, $this->productData($id, $data, false));
        return $this->findProduct($id);
    }

    public function archiveProduct(string $id): void
    {
        $this->updateById('shop_products', $id, ['status' => 'archived']);
    }

    /** @return array<int, array{id: string, name: string, sortOrder: int, status: string}> */
    public function categories(bool $includeArchived = false): array
    {
        $where = $includeArchived ? '' : " WHERE status = 'active'";
        $stmt = $this->pdo->query("SELECT id, name, sort_order AS sortOrder, status FROM shop_categories{$where} ORDER BY sort_order ASC, name ASC");
        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'name' => $row['name'],
            'sortOrder' => (int) $row['sortOrder'],
            'status' => $row['status'],
        ], $stmt->fetchAll());
    }

    /** @param array<string, mixed> $data */
    public function createCategory(array $data): array
    {
        $id = (string) ($data['id'] ?? '');
        if ($id === '') {
            $id = strtolower((string) preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($data['name'] ?? $this->id())));
        }
        $this->insert('shop_categories', [
            'id' => $id,
            'name' => (string) ($data['name'] ?? $id),
            'sort_order' => (int) ($data['sortOrder'] ?? 99),
            'status' => 'active',
        ]);
        return ['id' => $id, 'name' => (string) ($data['name'] ?? $id), 'sortOrder' => (int) ($data['sortOrder'] ?? 99), 'status' => 'active'];
    }

    /** @param array<string, mixed> $data */
    public function updateCategory(string $id, array $data): array
    {
        $mapped = [];
        if (array_key_exists('name', $data)) {
            $mapped['name'] = $data['name'];
        }
        if (array_key_exists('sortOrder', $data)) {
            $mapped['sort_order'] = (int) $data['sortOrder'];
        }
        if (array_key_exists('status', $data)) {
            $mapped['status'] = $data['status'] === 'archived' ? 'archived' : 'active';
        }
        if ($mapped !== []) {
            $this->pdo->prepare('UPDATE shop_categories SET ' . implode(', ', array_map(static fn (string $column): string => "{$column} = :{$column}", array_keys($mapped))) . ' WHERE id = :id')
                ->execute([...$mapped, 'id' => $id]);
        }
        foreach ($this->categories(true) as $category) {
            if ($category['id'] === $id) {
                return $category;
            }
        }
        return ['id' => $id, 'name' => $id, 'sortOrder' => 99, 'status' => 'archived'];
    }

    public function archiveCategory(string $id): void
    {
        $this->pdo->prepare("UPDATE shop_categories SET status = 'archived' WHERE id = :id")->execute(['id' => $id]);
    }

    /** @param array<string, mixed> $row */
    private function hydrateProduct(array $row): array
    {
        $product = $this->decode($row, self::PRODUCT_JSON);
        foreach (['inStock', 'featured', 'isService'] as $field) {
            $product[$field] = (bool) ($product[$field] ?? false);
        }
        foreach (['price', 'originalPrice', 'rating'] as $field) {
            if (array_key_exists($field, $product) && $product[$field] !== null) {
                $product[$field] = (float) $product[$field];
            }
        }
        if (array_key_exists('stockCount', $product) && $product['stockCount'] !== null) {
            $product['stockCount'] = (int) $product['stockCount'];
        }
        if (array_key_exists('reviewCount', $product) && $product['reviewCount'] !== null) {
            $product['reviewCount'] = (int) $product['reviewCount'];
        }
        return $product;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function productData(string $id, array $data, bool $creating): array
    {
        $out = $creating ? ['id' => $id, 'status' => 'active'] : [];
        foreach ([
            'name' => 'name',
            'slug' => 'slug',
            'description' => 'description',
            'price' => 'price',
            'originalPrice' => 'original_price',
            'category' => 'category',
            'brand' => 'brand',
            'sizes' => 'sizes',
            'colors' => 'colors',
            'imageUrl' => 'image_url',
            'images' => 'images',
            'inStock' => 'in_stock',
            'stockCount' => 'stock_count',
            'rating' => 'rating',
            'reviewCount' => 'review_count',
            'featured' => 'featured',
            'isService' => 'is_service',
            'serviceDetails' => 'service_details',
            'validityDays' => 'validity_days',
            'visibleToRoles' => 'visible_to_roles',
            'serviceKind' => 'service_kind',
            'status' => 'status',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $out[$column] = is_bool($data[$input]) ? (int) $data[$input] : $data[$input];
            }
        }
        foreach (['sizes', 'colors', 'images', 'visible_to_roles'] as $jsonField) {
            if (array_key_exists($jsonField, $out) && $out[$jsonField] !== null && !is_string($out[$jsonField])) {
                $out[$jsonField] = json_encode($out[$jsonField], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return $out;
    }
}

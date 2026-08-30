<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;
use RuntimeException;

/**
 * Loads the shop fixtures from database/seeders/data/shop.json.
 *
 * `001_demo.sql` seeds five products, which is enough to prove the shop renders and not
 * much else — no footwear, no electronics, nothing out of stock, no discounted price to
 * strike through, and no orders at all, so the order history and admin order screens had
 * nothing to show. These fixtures came out of the frontend's old shop mock and cover those
 * cases.
 *
 * Products are matched on both id and slug before inserting, so re-running alongside the
 * SQL seeder never collides and never duplicates a product under a second id.
 */
final class ShopFixtureImporter
{
    /** Categories the fixtures use that `001_demo.sql` does not create. */
    private const EXTRA_CATEGORIES = [
        'footwear' => ['name' => 'Obuwie', 'sortOrder' => 20],
        'electronics' => ['name' => 'Elektronika', 'sortOrder' => 30],
    ];

    /**
     * Order fixtures were written against the frontend's own participant ids. This is the
     * same merge the event importer performs, kept here so the two files stay independent.
     */
    private const USER_ALIASES = [
        'p1' => 'u-participant',
        'p2' => 'u-anna',
        'p3' => 'u-piotr',
        'p4' => 'u-kasia',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function dataFile(): string
    {
        return dirname(__DIR__, 2) . '/database/seeders/data/shop.json';
    }

    /**
     * @return array<string, int> rows written per table
     */
    public function import(?string $file = null): array
    {
        $file ??= self::dataFile();
        if (!is_file($file)) {
            throw new RuntimeException("Shop fixture file not found: {$file}");
        }

        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException("Shop fixture file is not a JSON object: {$file}");
        }

        $written = [];
        $written['shop_categories'] = $this->importCategories();
        $written['shop_products'] = $this->importProducts($data['products'] ?? []);
        $written += $this->importOrders($data['orders'] ?? []);

        return array_filter($written, static fn (int $count): bool => $count > 0);
    }

    private function importCategories(): int
    {
        $existing = $this->column('SELECT id FROM shop_categories');

        $count = 0;
        foreach (self::EXTRA_CATEGORIES as $id => $category) {
            if (isset($existing[$id])) {
                continue;
            }
            $this->insert('shop_categories', [
                'id' => $id,
                'name' => $category['name'],
                'sort_order' => $category['sortOrder'],
                'status' => 'active',
            ]);
            $count++;
        }

        return $count;
    }

    /** @param array<int, array<string, mixed>> $products */
    private function importProducts(array $products): int
    {
        $ids = $this->column('SELECT id FROM shop_products');
        $slugs = $this->column('SELECT slug FROM shop_products');

        $count = 0;
        foreach ($products as $product) {
            $id = (string) $product['id'];
            $slug = (string) ($product['slug'] ?? $id);
            if (isset($ids[$id]) || isset($slugs[$slug])) {
                continue;
            }
            $ids[$id] = true;
            $slugs[$slug] = true;

            $this->insert('shop_products', [
                'id' => $id,
                'name' => (string) ($product['name'] ?? ''),
                'slug' => $slug,
                'description' => (string) ($product['description'] ?? ''),
                'price' => $product['price'] ?? 0,
                'original_price' => $product['originalPrice'] ?? null,
                'category' => (string) ($product['category'] ?? 'accessories'),
                'brand' => (string) ($product['brand'] ?? 'ZmierzymyCzas'),
                'sizes' => $this->json($product['sizes'] ?? []),
                'colors' => $this->json($product['colors'] ?? []),
                'image_url' => (string) ($product['imageUrl'] ?? ''),
                'images' => $this->json($product['images'] ?? []),
                'in_stock' => (int) (bool) ($product['inStock'] ?? true),
                'stock_count' => $product['stockCount'] ?? null,
                'rating' => $product['rating'] ?? null,
                'review_count' => $product['reviewCount'] ?? null,
                'featured' => (int) (bool) ($product['featured'] ?? false),
                'is_service' => (int) (bool) ($product['isService'] ?? false),
                'status' => 'active',
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<string, int>
     */
    private function importOrders(array $orders): array
    {
        $counts = ['shop_orders' => 0, 'shop_order_items' => 0];

        $existingOrders = $this->column('SELECT id FROM shop_orders');
        $existingNumbers = $this->column('SELECT order_number FROM shop_orders');
        $users = $this->column('SELECT id FROM users');

        foreach ($orders as $order) {
            $id = (string) $order['id'];
            $number = (string) ($order['orderNumber'] ?? $id);
            if (isset($existingOrders[$id]) || isset($existingNumbers[$number])) {
                continue;
            }

            $userId = (string) ($order['userId'] ?? '');
            $userId = self::USER_ALIASES[$userId] ?? $userId;
            if (!isset($users[$userId])) {
                // The buyer does not exist here; an order without one is not worth showing.
                continue;
            }

            $this->insert('shop_orders', [
                'id' => $id,
                'order_number' => $number,
                'user_id' => $userId,
                'status' => (string) ($order['status'] ?? 'pending'),
                'product_total' => $order['productTotal'] ?? 0,
                'delivery_price' => $order['deliveryPrice'] ?? 0,
                'payment_surcharge' => $order['paymentSurcharge'] ?? 0,
                'price_adjustment' => $order['priceAdjustment'] ?? 0,
                'total_price' => $order['totalPrice'] ?? 0,
                'delivery_method' => $this->json($order['deliveryMethod'] ?? []),
                'payment_method' => $this->json($order['paymentMethod'] ?? []),
                'shipping_address' => $this->json($order['shippingAddress'] ?? null),
                'tracking_number' => $order['trackingNumber'] ?? null,
                'estimated_delivery' => $order['estimatedDelivery'] ?? null,
                'history' => $this->json($order['history'] ?? []),
            ]);
            $counts['shop_orders']++;

            foreach (array_values($order['items'] ?? []) as $index => $item) {
                $this->insert('shop_order_items', [
                    'id' => substr(sha1($id . '|' . $index), 0, 32),
                    'order_id' => $id,
                    'product_id' => (string) ($item['productId'] ?? ''),
                    'product_name' => (string) ($item['productName'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'price' => $item['price'] ?? 0,
                    'size' => $item['size'] ?? null,
                    'color' => $item['color'] ?? null,
                ]);
                $counts['shop_order_items']++;
            }
        }

        return $counts;
    }

    /** @return array<string, true> */
    private function column(string $sql): array
    {
        $values = [];
        foreach ($this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $values[(string) $value] = true;
        }

        return $values;
    }

    private function json(mixed $value): ?string
    {
        return $value === null ? null : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        );

        $this->pdo->prepare($sql)->execute($row);
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Shop;

use ZmierzymyCzas\Domain\Repository;

final class OrderRepository extends Repository
{
    private const JSON_FIELDS = ['deliveryMethod', 'paymentMethod', 'shippingAddress', 'history'];

    /**
     * E5.2: authoritative delivery-method catalog, mirrored from the hardcoded
     * list the GET /shop/delivery-methods route returns (routes.php, ~line
     * 2080) — kept as a second copy on purpose rather than reaching into
     * routes.php from a Domain class; the TODO above that route already
     * documents the real fix (move both catalogs into the database), at which
     * point this becomes a repository call instead of a constant. A checkout
     * request's client-submitted price for its chosen method is never trusted
     * — only the `id` is read; the price always comes from here.
     */
    private const DELIVERY_METHODS = [
        'courier' => ['id' => 'courier', 'name' => 'Kurier', 'price' => 15.99, 'estimatedDays' => '1-2 dni'],
        'pickup' => ['id' => 'pickup', 'name' => 'Odbior osobisty', 'price' => 0.0, 'estimatedDays' => 'w dniu zawodow'],
    ];

    /** @see self::DELIVERY_METHODS — same story, mirrors GET /shop/payment-methods. */
    private const PAYMENT_METHODS = [
        'card' => ['id' => 'card', 'name' => 'Karta platnicza', 'surcharge' => 0.0],
        'blik' => ['id' => 'blik', 'name' => 'BLIK', 'surcharge' => 0.0],
        'bank' => ['id' => 'bank', 'name' => 'Przelew bankowy', 'surcharge' => 0.0],
    ];

    private ShopRepository $shop;

    public function __construct(\PDO $pdo, ?ShopRepository $shop = null)
    {
        parent::__construct($pdo);
        $this->shop = $shop ?? new ShopRepository($pdo);
    }

    /** @param array<string, mixed> $filters @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['userId'])) {
            $where[] = 'o.user_id = :userId';
            $params['userId'] = $filters['userId'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'o.status = :status';
            $params['status'] = $filters['status'];
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->pdo->prepare(
            "SELECT o.id, o.order_number AS orderNumber, o.user_id AS userId, o.status,
                    o.product_total AS productTotal, o.delivery_price AS deliveryPrice,
                    o.payment_surcharge AS paymentSurcharge, o.price_adjustment AS priceAdjustment,
                    o.total_price AS totalPrice, o.delivery_method AS deliveryMethod,
                    o.payment_method AS paymentMethod, o.shipping_address AS shippingAddress,
                    o.tracking_number AS trackingNumber, o.admin_notes AS adminNotes,
                    o.cancel_reason AS cancelReason, o.estimated_delivery AS estimatedDelivery,
                    o.history, o.created_at AS createdAt, o.updated_at AS updatedAt
             FROM shop_orders o{$whereSql}
             ORDER BY o.created_at DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return array_map(fn (array $row): array => $this->hydrateOrder($row), $rows);
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT o.id, o.order_number AS orderNumber, o.user_id AS userId, o.status,
                    o.product_total AS productTotal, o.delivery_price AS deliveryPrice,
                    o.payment_surcharge AS paymentSurcharge, o.price_adjustment AS priceAdjustment,
                    o.total_price AS totalPrice, o.delivery_method AS deliveryMethod,
                    o.payment_method AS paymentMethod, o.shipping_address AS shippingAddress,
                    o.tracking_number AS trackingNumber, o.admin_notes AS adminNotes,
                    o.cancel_reason AS cancelReason, o.estimated_delivery AS estimatedDelivery,
                    o.history, o.created_at AS createdAt, o.updated_at AS updatedAt
             FROM shop_orders o WHERE o.id = :id"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->withItems($this->hydrateOrder($row)) : null;
    }

    /**
     * E5.2: closes the "client controls its own order" bug — `$userId` must
     * come from the caller's JWT (routes.php passes `auth.sub`, never
     * anything the request body says under `userId`), and every price in the
     * persisted order is recomputed here from the authoritative catalog:
     * `items[].price` from ShopRepository, delivery/payment prices from the
     * hardcoded catalogs above, `priceAdjustment` always 0 (staff-only, set
     * later via update() — a customer checkout never gets one). Mirrors
     * RegistrationRepository::create() (E1.3) for the same
     * lock-check-decrement-inside-one-transaction shape, just with a shop
     * product's stock_count standing in for a Dystans's registeredCount.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data, string $userId): array
    {
        $id = $this->id();
        $orderNumber = 'ORD-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        $deliveryMethod = $this->resolveMethod((array) ($data['deliveryMethod'] ?? []), self::DELIVERY_METHODS, 'order_invalid_delivery_method');
        $paymentMethod = $this->resolveMethod((array) ($data['paymentMethod'] ?? []), self::PAYMENT_METHODS, 'order_invalid_payment_method');
        $deliveryPrice = (float) $deliveryMethod['price'];
        $paymentSurcharge = (float) ($paymentMethod['surcharge'] ?? 0);

        $requestedItems = array_map(static fn ($item): array => (array) $item, (array) ($data['items'] ?? []));

        $this->pdo->beginTransaction();
        try {
            $productTotal = 0.0;
            $resolvedItems = [];
            foreach ($requestedItems as $item) {
                $productId = (string) ($item['productId'] ?? '');
                $quantity = max(1, (int) ($item['quantity'] ?? 1));

                // E1.3-style row lock: locks this shop_products row for the rest of
                // this transaction so a concurrent order against the same product
                // can never race the stock check/decrement below.
                $product = $this->shop->lockProductForOrder($productId);
                if ($product === null || ($product['status'] ?? 'active') !== 'active') {
                    throw new \RuntimeException('order_invalid_product');
                }
                // inStock=false is a hard stop regardless of stockCount — distinct
                // code from the stockCount check below so the client can tell "this
                // item is off the shelf entirely" from "you asked for more than we
                // have left".
                if (!$product['inStock']) {
                    throw new \RuntimeException('order_out_of_stock');
                }
                if ($product['stockCount'] !== null) {
                    if ($quantity > (int) $product['stockCount']) {
                        throw new \RuntimeException('order_insufficient_stock');
                    }
                    $this->decrementStock($productId, $quantity);
                }

                $price = (float) $product['price'];
                $productTotal += $price * $quantity;
                $resolvedItems[] = [
                    'product_id' => $productId,
                    'product_name' => (string) $product['name'],
                    'quantity' => $quantity,
                    'price' => $price,
                    'size' => $item['size'] ?? null,
                    'color' => $item['color'] ?? null,
                ];
            }

            $this->insert('shop_orders', [
                'id' => $id,
                'order_number' => $orderNumber,
                'user_id' => $userId,
                'status' => 'pending',
                'product_total' => $productTotal,
                'delivery_price' => $deliveryPrice,
                'payment_surcharge' => $paymentSurcharge,
                // Customers don't set price adjustments at checkout (E5.2 scope) —
                // that lever stays staff-only, applied afterwards via update().
                'price_adjustment' => 0.0,
                'total_price' => $productTotal + $deliveryPrice + $paymentSurcharge,
                'delivery_method' => json_encode($deliveryMethod, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'payment_method' => json_encode($paymentMethod, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'shipping_address' => isset($data['shippingAddress']) ? json_encode($data['shippingAddress'], JSON_UNESCAPED_UNICODE) : null,
                'estimated_delivery' => $data['estimatedDelivery'] ?? null,
                'history' => '[]',
            ]);

            foreach ($resolvedItems as $item) {
                $this->insert('shop_order_items', [
                    'id' => $this->id(),
                    'order_id' => $id,
                    ...$item,
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->find($id) ?? [];
    }

    /**
     * Validates a client-submitted delivery/payment method against the real
     * catalog by `id` only — the rest of whatever the client sent (price
     * included) is discarded in favour of the authoritative catalog entry.
     *
     * @param array<string, mixed> $submitted
     * @param array<string, array<string, mixed>> $catalog
     * @return array<string, mixed>
     */
    private function resolveMethod(array $submitted, array $catalog, string $errorCode): array
    {
        $methodId = (string) ($submitted['id'] ?? '');
        if ($methodId === '' || !isset($catalog[$methodId])) {
            throw new \RuntimeException($errorCode);
        }

        return $catalog[$methodId];
    }

    /**
     * Atomic, affected-rows-guarded stock decrement — the write-side half of
     * the transactional stock check (the read side is
     * ShopRepository::lockProductForOrder() + the stockCount comparison in
     * create()). The WHERE clause is redundant with the row lock already held
     * by the caller under normal operation, but matches E1.3's
     * incrementRegisteredCount() belt-and-braces pattern.
     */
    private function decrementStock(string $productId, int $quantity): void
    {
        // Native prepares (PDO::ATTR_EMULATE_PREPARES => false, see Connection)
        // reject a named placeholder used more than once in the same query —
        // same reason ShopRepository::products() binds :search1/:search2/:search3
        // separately for one identical value.
        $stmt = $this->pdo->prepare(
            'UPDATE shop_products SET stock_count = stock_count - :qty1 WHERE id = :id AND stock_count >= :qty2'
        );
        $stmt->execute(['qty1' => $quantity, 'id' => $productId, 'qty2' => $quantity]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('order_insufficient_stock');
        }
    }

    public function updateStatus(string $id, string $status, ?string $trackingNumber = null): ?array
    {
        $fields = ['status' => $status];
        if ($trackingNumber !== null) {
            $fields['tracking_number'] = $trackingNumber;
        }
        $this->updateById('shop_orders', $id, $fields);
        return $this->find($id);
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|null */
    public function update(string $id, array $data): ?array
    {
        $allowed = ['status', 'tracking_number', 'admin_notes', 'cancel_reason', 'price_adjustment', 'history'];
        $fields = [];
        $map = [
            'status' => 'status',
            'trackingNumber' => 'tracking_number',
            'adminNotes' => 'admin_notes',
            'cancelReason' => 'cancel_reason',
            'priceAdjustment' => 'price_adjustment',
            'history' => 'history',
        ];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $data)) {
                $value = $data[$camel];
                $fields[$snake] = in_array($camel, ['history'], true) && !is_string($value)
                    ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : $value;
            }
        }
        if ($fields !== []) {
            $this->updateById('shop_orders', $id, $fields);
        }

        if (!empty($data['items'])) {
            $this->pdo->prepare('DELETE FROM shop_order_items WHERE order_id = :id')->execute(['id' => $id]);
            foreach ((array) $data['items'] as $item) {
                $item = (array) $item;
                $this->insert('shop_order_items', [
                    'id' => $this->id(),
                    'order_id' => $id,
                    'product_id' => (string) ($item['productId'] ?? ''),
                    'product_name' => (string) ($item['productName'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'price' => (float) ($item['price'] ?? 0),
                    'size' => $item['size'] ?? null,
                    'color' => $item['color'] ?? null,
                ]);
            }
        }

        return $this->find($id);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrateOrder(array $row): array
    {
        foreach (self::JSON_FIELDS as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $row[$field] = json_decode($row[$field], true) ?: [];
            }
        }
        return $row;
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function withItems(array $order): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, product_id AS productId, product_name AS productName, quantity, price, size, color
             FROM shop_order_items WHERE order_id = :id'
        );
        $stmt->execute(['id' => $order['id']]);
        $order['items'] = $stmt->fetchAll();
        return $order;
    }
}

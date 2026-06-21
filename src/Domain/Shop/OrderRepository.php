<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Shop;

use ZmierzymyCzas\Domain\Repository;

final class OrderRepository extends Repository
{
    private const JSON_FIELDS = ['deliveryMethod', 'paymentMethod', 'shippingAddress', 'history'];

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

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data): array
    {
        $id = $this->id();
        $orderNumber = 'ORD-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $this->insert('shop_orders', [
            'id' => $id,
            'order_number' => $orderNumber,
            'user_id' => (string) ($data['userId'] ?? ''),
            'status' => 'pending',
            'product_total' => (float) ($data['productTotal'] ?? 0),
            'delivery_price' => (float) ($data['deliveryPrice'] ?? 0),
            'payment_surcharge' => (float) ($data['paymentSurcharge'] ?? 0),
            'price_adjustment' => (float) ($data['priceAdjustment'] ?? 0),
            'total_price' => (float) ($data['totalPrice'] ?? 0),
            'delivery_method' => json_encode($data['deliveryMethod'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payment_method' => json_encode($data['paymentMethod'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'shipping_address' => isset($data['shippingAddress']) ? json_encode($data['shippingAddress'], JSON_UNESCAPED_UNICODE) : null,
            'estimated_delivery' => $data['estimatedDelivery'] ?? null,
            'history' => '[]',
        ]);

        foreach ((array) ($data['items'] ?? []) as $item) {
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

        return $this->find($id) ?? [];
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

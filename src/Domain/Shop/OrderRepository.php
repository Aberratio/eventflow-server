<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Shop;

use ZmierzymyCzas\Domain\Repository;

final class OrderRepository extends Repository
{
    private const JSON_FIELDS = ['deliveryMethod', 'paymentMethod', 'shippingAddress', 'invoiceData', 'history'];

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
        'paczkomat' => ['id' => 'paczkomat', 'name' => 'Paczkomat', 'price' => 12.99, 'estimatedDays' => '1-2 dni'],
        'pickup' => ['id' => 'pickup', 'name' => 'Odbior osobisty', 'price' => 0.0, 'estimatedDays' => 'w dniu zawodow'],
    ];

    /**
     * E5.3: the two delivery methods that ship to a physical shipping
     * address (spec issue 16 §5 — "kurier/paczkomat (adres z koszyka)").
     * `pickup` (odbior osobisty w Biurze Zawodow, ticket E5.4) never needs
     * one, and an organizer-package purchase (a digital product — spec §5,
     * "nie przechodzi przez zadna z nich") isn't wired to a delivery method
     * at all yet, so this check is deliberately keyed off the *selected
     * delivery method*, not the product type: it only ever fires for an
     * order that actually chose courier/paczkomat, never unconditionally.
     */
    private const DELIVERY_METHODS_REQUIRING_ADDRESS = ['courier', 'paczkomat'];

    /** @see self::DELIVERY_METHODS — same story, mirrors GET /shop/payment-methods. */
    private const PAYMENT_METHODS = [
        'card' => ['id' => 'card', 'name' => 'Karta platnicza', 'surcharge' => 0.0],
        'blik' => ['id' => 'blik', 'name' => 'BLIK', 'surcharge' => 0.0],
        'bank' => ['id' => 'bank', 'name' => 'Przelew bankowy', 'surcharge' => 0.0],
    ];

    /**
     * E5.6 (issue 16 §2): the "pakiet organizatora" digital product is a real
     * `shop_products` catalog row (ShopRepository, E5.1) flagged via the
     * pre-existing `service_kind` column (001_init.sql, previously unused by
     * any application logic) rather than a new column — a service-shaped
     * product ("no stock, no shipping") already fit the concept exactly.
     * Ordering one grants `quantity` credits on the buyer's
     * `users.organizer_package_credits` counter (migration 053) instead of/
     * alongside a physical line item; it never carries a delivery method
     * (§5, "nie przechodzi przez zadna z nich" — see isDigitalOnlyCart()
     * below).
     */
    private const SERVICE_KIND_ORGANIZER_PACKAGE = 'organizer_package';

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
                    o.invoice_data AS invoiceData,
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
                    o.invoice_data AS invoiceData,
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

        $requestedItems = array_map(static fn ($item): array => (array) $item, (array) ($data['items'] ?? []));

        // E5.6: a cart made up entirely of digital (isService) products — e.g. the
        // "pakiet organizatora" — never goes through a delivery method at all
        // (issue 16 §2/§5), so the usual "delivery method is required" check
        // (below) doesn't apply to it. This is an unlocked pre-check purely to
        // decide *whether* a delivery method is required; the authoritative
        // product data used for pricing/stock/credit-granting is always the
        // locked read inside the transaction below.
        $isDigitalOnlyCart = $requestedItems !== [] && $this->isDigitalOnlyCart($requestedItems);

        $deliveryMethod = null;
        $deliveryPrice = 0.0;
        $paymentMethod = $this->resolveMethod((array) ($data['paymentMethod'] ?? []), self::PAYMENT_METHODS, 'order_invalid_payment_method');
        $paymentSurcharge = (float) ($paymentMethod['surcharge'] ?? 0);

        if (!$isDigitalOnlyCart) {
            $deliveryMethod = $this->resolveMethod((array) ($data['deliveryMethod'] ?? []), self::DELIVERY_METHODS, 'order_invalid_delivery_method');
            $deliveryPrice = (float) $deliveryMethod['price'];

            if (in_array($deliveryMethod['id'], self::DELIVERY_METHODS_REQUIRING_ADDRESS, true)) {
                $this->validateShippingAddress((array) ($data['shippingAddress'] ?? []), (string) $deliveryMethod['id']);
            }
        }

        // E5.5: "chce fakture" is signalled by sending invoiceData at all —
        // same pattern as shippingAddress, no separate boolean column. An
        // order that omits it entirely (the pre-E5.5 default: plain
        // receipt) never touches this and stays byte-for-byte backward
        // compatible with E5.2/E5.3/E5.4.
        if (isset($data['invoiceData'])) {
            $this->validateInvoiceData((array) $data['invoiceData']);
        }

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

                // E5.6: the credit granted here is the seam E5.7 (self-service
                // purchase -> Organizer role) is meant to build on — deliberately
                // just the counter, no role-granting logic, same transaction as
                // the stock decrement above so a purchase can never charge the
                // buyer's card (well, "succeed" here) without also crediting them.
                if (($product['serviceKind'] ?? null) === self::SERVICE_KIND_ORGANIZER_PACKAGE) {
                    $this->grantOrganizerPackageCredits($userId, $quantity);
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
                // `delivery_method` is NOT NULL (019_shop_orders.sql, default '{}')
                // — a digital-only order (no delivery method at all, E5.6) persists
                // the same empty-object sentinel the column already defaults to,
                // rather than widening the column to nullable.
                'delivery_method' => json_encode($deliveryMethod ?? (object) [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'payment_method' => json_encode($paymentMethod, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'shipping_address' => isset($data['shippingAddress']) ? json_encode($data['shippingAddress'], JSON_UNESCAPED_UNICODE) : null,
                'invoice_data' => isset($data['invoiceData']) ? json_encode($data['invoiceData'], JSON_UNESCAPED_UNICODE) : null,
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
     * E5.6: true only when every requested item resolves to an active,
     * `isService` product (an unlocked read — see create()'s call site for why
     * that's fine here). An empty/all-invalid cart is never treated as
     * "digital-only" by this method; create()'s caller already guards that
     * case separately so a genuinely empty `items[]` still goes through the
     * normal delivery-method validation and fails with the existing
     * `order_invalid_product`/`order_invalid_delivery_method` errors instead
     * of silently skipping delivery entirely.
     *
     * @param array<int, array<string, mixed>> $requestedItems
     */
    private function isDigitalOnlyCart(array $requestedItems): bool
    {
        foreach ($requestedItems as $item) {
            $product = $this->shop->findProduct((string) ($item['productId'] ?? ''));
            if ($product === null || ($product['status'] ?? 'active') !== 'active' || !($product['isService'] ?? false)) {
                return false;
            }
        }
        return true;
    }

    /**
     * E5.6: grants `count` "pakiet organizatora" credits to the buyer — the
     * write-side half of the counter EventRepository::submit()/reject() spend
     * and refund. Same affected-rows-free "just add" shape as
     * EventRepository::refundOrganizerPackageCredit(); a purchase has no
     * upper bound to guard against, unlike the decrement at submit time.
     */
    private function grantOrganizerPackageCredits(string $userId, int $count): void
    {
        $this->pdo->prepare(
            'UPDATE users SET organizer_package_credits = organizer_package_credits + :count WHERE id = :userId'
        )->execute(['count' => $count, 'userId' => $userId]);
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
     * E5.3: courier/paczkomat require a shipping address in the same shape
     * as the client's `ShopOrderAddress` (fullName/street/city/postalCode,
     * optional phone; paczkomatId in place of street/city for a paczkomat
     * pickup point). Mirrors the client's own mirrored validation — this is
     * the authoritative check, the client one is UX only.
     *
     * @param array<string, mixed> $address
     */
    private function validateShippingAddress(array $address, string $deliveryMethodId): void
    {
        $required = ['fullName', 'street', 'city', 'postalCode'];
        foreach ($required as $field) {
            if (trim((string) ($address[$field] ?? '')) === '') {
                throw new \RuntimeException('order_invalid_address');
            }
        }
        if (!preg_match('/^\d{2}-\d{3}$/', (string) $address['postalCode'])) {
            throw new \RuntimeException('order_invalid_address');
        }
        if ($deliveryMethodId === 'paczkomat' && trim((string) ($address['paczkomatId'] ?? '')) === '') {
            throw new \RuntimeException('order_invalid_address');
        }
    }

    /**
     * E5.5: invoice data (spec issue 16 pkt 1 — "dane adresowe, faktura z
     * NIP"; SS6 — "dane do faktury" is part of the minimal required shop
     * scope). Data capture only, no document is generated here (explicitly
     * out of scope, see issue 16). Required whenever the client sends
     * invoiceData at all — companyName, a well-formed NIP, and a billing
     * address in the same street/city/postalCode shape as
     * validateShippingAddress() above, for the same reason: one address
     * shape across the order, not two independently-evolving ones.
     *
     * NIP format gets its own error code (order_invalid_nip) per the
     * ticket's request for "a clear error code on malformed NIP" — every
     * other missing/invalid invoice field falls under order_invalid_invoice_data,
     * mirroring how order_invalid_address covers the whole shipping-address
     * shape in E5.3.
     *
     * @param array<string, mixed> $invoice
     */
    private function validateInvoiceData(array $invoice): void
    {
        if (trim((string) ($invoice['companyName'] ?? '')) === '') {
            throw new \RuntimeException('order_invalid_invoice_data');
        }

        $required = ['street', 'city', 'postalCode'];
        foreach ($required as $field) {
            if (trim((string) ($invoice[$field] ?? '')) === '') {
                throw new \RuntimeException('order_invalid_invoice_data');
            }
        }
        if (!preg_match('/^\d{2}-\d{3}$/', (string) $invoice['postalCode'])) {
            throw new \RuntimeException('order_invalid_invoice_data');
        }

        $this->validateNip((string) ($invoice['nip'] ?? ''));
    }

    /**
     * Polish NIP: 10 digits (dashes/spaces allowed in the submitted form,
     * stripped before checking), with a checksum over the first 9 digits
     * using the standard NIP weights; the 10th digit must equal the
     * weighted sum mod 11 (a mod-11 result of 10 is itself invalid — no
     * real NIP produces it).
     */
    private function validateNip(string $nip): void
    {
        $digits = preg_replace('/[\s-]/', '', $nip) ?? '';
        if (!preg_match('/^\d{10}$/', $digits)) {
            throw new \RuntimeException('order_invalid_nip');
        }

        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $weights[$i] * (int) $digits[$i];
        }
        $checksum = $sum % 11;
        if ($checksum === 10 || $checksum !== (int) $digits[9]) {
            throw new \RuntimeException('order_invalid_nip');
        }
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

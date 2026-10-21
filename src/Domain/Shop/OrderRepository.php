<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Shop;

use ZmierzymyCzas\Domain\Errors\Conflict;
use ZmierzymyCzas\Domain\Errors\InvalidInput;
use ZmierzymyCzas\Domain\Errors\NotFound;
use ZmierzymyCzas\Domain\Repository;

final class OrderRepository extends Repository
{
    /** Column list shared by the buyer-scoped reads (same as find()). */
    private const ORDER_COLUMNS = "o.id, o.order_number AS orderNumber, o.user_id AS userId, o.status,
        o.product_total AS productTotal, o.delivery_price AS deliveryPrice,
        o.payment_surcharge AS paymentSurcharge, o.price_adjustment AS priceAdjustment,
        o.total_price AS totalPrice, o.delivery_method AS deliveryMethod,
        o.payment_method AS paymentMethod, o.shipping_address AS shippingAddress,
        o.invoice_data AS invoiceData,
        o.tracking_number AS trackingNumber, o.admin_notes AS adminNotes,
        o.cancel_reason AS cancelReason, o.estimated_delivery AS estimatedDelivery,
        o.history, o.created_at AS createdAt, o.updated_at AS updatedAt";

    private const JSON_FIELDS = ['deliveryMethod', 'paymentMethod', 'shippingAddress', 'invoiceData', 'history'];

    /**
     * Authoritative delivery-method catalog, mirrored from the hardcoded
     * list the GET /shop/delivery-methods route returns (routes.php, ~line
     * 2080) — kept as a second copy on purpose rather than reaching into
     * routes.php from a Domain class. Design note: both catalogs belong in the
     * database eventually, at which point this becomes a repository call
     * instead of a constant. A checkout
     * request's client-submitted price for its chosen method is never trusted
     * — only the `id` is read; the price always comes from here.
     */
    private const DELIVERY_METHODS = [
        'courier' => ['id' => 'courier', 'name' => 'Kurier', 'price' => 15.99, 'estimatedDays' => '1-2 dni'],
        'paczkomat' => ['id' => 'paczkomat', 'name' => 'Paczkomat', 'price' => 12.99, 'estimatedDays' => '1-2 dni'],
        'pickup' => ['id' => 'pickup', 'name' => 'Odbior osobisty', 'price' => 0.0, 'estimatedDays' => 'w dniu zawodow'],
    ];

    /**
     * The two delivery methods that ship to a physical shipping
     * address ("kurier/paczkomat", address taken from the cart).
     * `pickup` (personal pickup at the Biuro Zawodów) never needs
     * one, and an organizer-package purchase (a digital product that
     * goes through none of them) isn't wired to a delivery method
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
     * The "pakiet organizatora" digital product is a real
     * `shop_products` catalog row (ShopRepository) flagged via the
     * pre-existing `service_kind` column (001_init.sql, previously unused by
     * any application logic) rather than a new column — a service-shaped
     * product ("no stock, no shipping") already fit the concept exactly.
     * Ordering one grants `quantity` credits on the buyer's
     * `users.organizer_package_credits` counter (migration 053) instead of/
     * alongside a physical line item; it never carries a delivery method
     * (it goes through no delivery method — see isDigitalOnlyCart()
     * below).
     */
    private const SERVICE_KIND_ORGANIZER_PACKAGE = 'organizer_package';

    /**
     * An organizer-package order line may hold at most this many
     * packages. The raw request value is validated, so 1000 can never be clamped into a grant.
     */
    private const MAX_ORGANIZER_PACKAGES_PER_LINE = 10;

    /** Every value of the `shop_orders.status` ENUM (019_shop_orders.sql). */
    private const ORDER_STATUSES = ['pending', 'paid', 'shipped', 'delivered', 'cancelled', 'refunded'];

    /**
     * The server-side truth for shop order status moves (matches the moderator UI
     * pending -> paid -> shipped -> delivered, plus cancel/refund). `cancelled` and `refunded`
     * are terminal. A digital-only order may go paid -> delivered directly.
     */
    private const STATUS_TRANSITIONS = [
        'pending' => ['paid', 'cancelled'],
        'paid' => ['shipped', 'delivered', 'cancelled', 'refunded'],
        'shipped' => ['delivered', 'refunded'],
        'delivered' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
    ];

    private const ROLE_ORGANIZER = 'organizer';

    private ShopRepository $shop;

    /**
     * @param bool $onlineGatewayConfigured Whether Tpay or PayNow is configured; when false,
     *        create() refuses card/BLIK exactly as GET /shop/payment-methods hides them. Defaults to
     *        true so internal callers that build their own repository (the challenge-reward order)
     *        keep the whole catalog; the HTTP wiring in routes.php passes the real value.
     */
    public function __construct(\PDO $pdo, ?ShopRepository $shop = null, private readonly bool $onlineGatewayConfigured = true)
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
        $orders = array_map(fn (array $row): array => $this->hydrateOrder($row), $stmt->fetchAll());

        // The staff list (the only UI path to mark an order paid) needs items[] like every other
        // order read; one query for the whole page instead of one per order.
        if ($orders !== []) {
            $ids = array_map(static fn (array $order): string => (string) $order['id'], $orders);
            $itemStmt = $this->pdo->prepare(
                'SELECT order_id AS orderId, id, product_id AS productId, product_name AS productName, quantity, price, size, color
                 FROM shop_order_items WHERE order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
            );
            $itemStmt->execute($ids);
            $byOrder = [];
            foreach ($itemStmt->fetchAll() as $item) {
                $orderId = (string) $item['orderId'];
                unset($item['orderId']);
                $byOrder[$orderId][] = $item;
            }
            foreach ($orders as &$order) {
                $order['items'] = $byOrder[(string) $order['id']] ?? [];
            }
            unset($order);
        }

        return $orders;
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

    /** The caller's own orders, newest first, each with items[]. $userId MUST be auth.sub. */
    public function listForUser(string $userId): array
    {
        if ($userId === '') {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ORDER_COLUMNS . ' FROM shop_orders o
             WHERE o.user_id = :userId ORDER BY o.created_at DESC, o.id DESC'
        );
        $stmt->execute(['userId' => $userId]);
        return array_map(fn (array $row): array => $this->withItems($this->hydrateOrder($row)), $stmt->fetchAll());
    }

    /** One order iff it belongs to $userId; a foreign id and a missing id are both null (no oracle). */
    public function findForUser(string $id, string $userId): ?array
    {
        if ($userId === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ORDER_COLUMNS . ' FROM shop_orders o
             WHERE o.id = :id AND o.user_id = :userId'
        );
        $stmt->execute(['id' => $id, 'userId' => $userId]);
        $row = $stmt->fetch();
        return $row ? $this->withItems($this->hydrateOrder($row)) : null;
    }

    /**
     * Closes the "client controls its own order" bug — `$userId` must
     * come from the caller's JWT (routes.php passes `auth.sub`, never
     * anything the request body says under `userId`), and every price in the
     * persisted order is recomputed here from the authoritative catalog:
     * `items[].price` from ShopRepository, delivery/payment prices from the
     * hardcoded catalogs above, `priceAdjustment` always 0 (staff-only, set
     * later via update() — a customer checkout never gets one). Mirrors
     * RegistrationRepository::create() for the same
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

        // A cart made up entirely of digital (isService) products — e.g. the
        // "pakiet organizatora" — never goes through a delivery method at all
        //, so the usual "delivery method is required" check
        // (below) doesn't apply to it. This is an unlocked pre-check purely to
        // decide *whether* a delivery method is required; the authoritative
        // product data used for pricing/stock/credit-granting is always the
        // locked read inside the transaction below.
        $isDigitalOnlyCart = $requestedItems !== [] && $this->isDigitalOnlyCart($requestedItems);

        $deliveryMethod = null;
        $deliveryPrice = 0.0;
        // Only a method the shop really offers (availablePaymentMethods()), not the whole catalog.
        $offered = array_column($this->availablePaymentMethods($this->onlineGatewayConfigured), 'id');
        $paymentMethod = $this->resolveMethod(
            (array) ($data['paymentMethod'] ?? []),
            array_intersect_key(self::PAYMENT_METHODS, array_flip($offered)),
            'order_invalid_payment_method'
        );
        $paymentSurcharge = (float) ($paymentMethod['surcharge'] ?? 0);

        if (!$isDigitalOnlyCart) {
            $deliveryMethod = $this->resolveMethod((array) ($data['deliveryMethod'] ?? []), self::DELIVERY_METHODS, 'order_invalid_delivery_method');
            $deliveryPrice = (float) $deliveryMethod['price'];

            if (in_array($deliveryMethod['id'], self::DELIVERY_METHODS_REQUIRING_ADDRESS, true)) {
                $this->validateShippingAddress((array) ($data['shippingAddress'] ?? []), (string) $deliveryMethod['id']);
            }
        }

        // "chce fakture" is signalled by sending invoiceData at all —
        // same pattern as shippingAddress, no separate boolean column. An
        // order that omits it entirely (the default: plain
        // receipt) never touches this and stays byte-for-byte backward
        // compatible.
        if (isset($data['invoiceData'])) {
            $this->validateInvoiceData((array) $data['invoiceData']);
        }

        $this->pdo->beginTransaction();
        try {
            $productTotal = 0.0;
            $resolvedItems = [];
            foreach ($requestedItems as $item) {
                $productId = (string) ($item['productId'] ?? '');
                $rawQuantity = $item['quantity'] ?? 1;
                $quantity = max(1, (int) $rawQuantity);

                // Row lock (same pattern as registrations): locks this shop_products row for the rest of
                // this transaction so a concurrent order against the same product
                // can never race the stock check/decrement below.
                $product = $this->shop->lockProductForOrder($productId);
                if ($product === null || ($product['status'] ?? 'active') !== 'active') {
                    throw new InvalidInput('order_invalid_product');
                }
                // inStock=false is a hard stop regardless of stockCount — distinct
                // code from the stockCount check below so the client can tell "this
                // item is off the shelf entirely" from "you asked for more than we
                // have left".
                if (!$product['inStock']) {
                    throw new Conflict('order_out_of_stock');
                }
                // An organizer package line is capped on the RAW request value (before the
                // max(1, ...) clamp above), so 0 / -5 / 1000 / "abc" never turn into a grant.
                $isPackage = ($product['serviceKind'] ?? null) === self::SERVICE_KIND_ORGANIZER_PACKAGE;
                if ($isPackage && !$this->isValidPackageQuantity($rawQuantity)) {
                    throw new InvalidInput('order_invalid_quantity');
                }
                if ($product['stockCount'] !== null) {
                    if ($quantity > (int) $product['stockCount']) {
                        throw new Conflict('order_insufficient_stock');
                    }
                    $this->decrementStock($productId, $quantity);
                }

                // Nothing is granted here. An organizer package grants credits, the role and an
                // event slot only when the order becomes `paid` (changeStatus()).

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
                // Customers don't set price adjustments at checkout —
                // that lever stays staff-only, applied afterwards via update().
                'price_adjustment' => 0.0,
                'total_price' => $productTotal + $deliveryPrice + $paymentSurcharge,
                // `delivery_method` is NOT NULL (019_shop_orders.sql, default '{}')
                // — a digital-only order (no delivery method at all) persists
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
     * True only when every requested item resolves to an active,
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

    /** @param mixed $raw the unmodified `quantity` from the request */
    private function isValidPackageQuantity(mixed $raw): bool
    {
        if (is_string($raw) && preg_match('/^\d{1,6}$/', $raw) === 1) {
            $raw = (int) $raw;
        }

        return is_int($raw) && $raw >= 1 && $raw <= self::MAX_ORGANIZER_PACKAGES_PER_LINE;
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
    /**
     * The payment methods the shop can really take, as `{id, name}` rows.
     * Card and BLIK are online-gateway methods, so they are offered only when a
     * gateway (Tpay or PayNow) is configured; bank transfer needs no gateway.
     * Same catalog as create() accepts (PAYMENT_METHODS).
     *
     *  list<array{id: string, name: string}>
     */
    public function availablePaymentMethods(bool $onlineGatewayConfigured): array
    {
        $methods = [];
        foreach (self::PAYMENT_METHODS as $id => $method) {
            if (!$onlineGatewayConfigured && $id !== 'bank') {
                continue;
            }
            $methods[] = ['id' => $method['id'], 'name' => $method['name']];
        }

        return $methods;
    }

    private function resolveMethod(array $submitted, array $catalog, string $errorCode): array
    {
        $methodId = (string) ($submitted['id'] ?? '');
        if ($methodId === '' || !isset($catalog[$methodId])) {
            throw new InvalidInput($errorCode);
        }

        return $catalog[$methodId];
    }

    /**
     * Courier/paczkomat require a shipping address in the same shape
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
                throw new InvalidInput('order_invalid_address');
            }
        }
        if (!preg_match('/^\d{2}-\d{3}$/', (string) $address['postalCode'])) {
            throw new InvalidInput('order_invalid_address');
        }
        if ($deliveryMethodId === 'paczkomat' && trim((string) ($address['paczkomatId'] ?? '')) === '') {
            throw new InvalidInput('order_invalid_address');
        }
    }

    /**
     * Invoice data ("dane adresowe, faktura z
     * NIP"; "dane do faktury" is part of the minimal required shop
     * scope). Data capture only, no document is generated here (explicitly
     * out of scope). Required whenever the client sends
     * invoiceData at all — companyName, a well-formed NIP, and a billing
     * address in the same street/city/postalCode shape as
     * validateShippingAddress() above, for the same reason: one address
     * shape across the order, not two independently-evolving ones.
     *
     * NIP format gets its own error code (order_invalid_nip) as a
     * clear error code on malformed NIP — every
     * other missing/invalid invoice field falls under order_invalid_invoice_data,
     * mirroring how order_invalid_address covers the whole shipping-address
     * shape.
     *
     * @param array<string, mixed> $invoice
     */
    private function validateInvoiceData(array $invoice): void
    {
        if (trim((string) ($invoice['companyName'] ?? '')) === '') {
            throw new InvalidInput('order_invalid_invoice_data');
        }

        $required = ['street', 'city', 'postalCode'];
        foreach ($required as $field) {
            if (trim((string) ($invoice[$field] ?? '')) === '') {
                throw new InvalidInput('order_invalid_invoice_data');
            }
        }
        if (!preg_match('/^\d{2}-\d{3}$/', (string) $invoice['postalCode'])) {
            throw new InvalidInput('order_invalid_invoice_data');
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
            throw new InvalidInput('order_invalid_nip');
        }

        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $weights[$i] * (int) $digits[$i];
        }
        $checksum = $sum % 11;
        if ($checksum === 10 || $checksum !== (int) $digits[9]) {
            throw new InvalidInput('order_invalid_nip');
        }
    }

    /**
     * Atomic, affected-rows-guarded stock decrement — the write-side half of
     * the transactional stock check (the read side is
     * ShopRepository::lockProductForOrder() + the stockCount comparison in
     * create()). The WHERE clause is redundant with the row lock already held
     * by the caller under normal operation, but matches the
     * registrations incrementRegisteredCount() belt-and-braces pattern.
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
            throw new Conflict('order_insufficient_stock');
        }
    }

    /**
     * THE ONLY DOOR INTO `paid` (and into cancelled/refunded) for shop orders. A future
     * payment gateway webhook must call this method too. Until then "paid" is a staff confirmation
     * (PATCH /shop/orders/{id}/status, or the `status` key of PATCH /shop/orders/{id}).
     *
     * One transaction, order row locked: status change + (on `paid`) organizer package grant, or
     * (on cancelled/refunded of a granted order) reversal of the unused part. The
     * `organizer_credits_granted` marker makes a double grant impossible.
     *
     * @return array<string, mixed> the order after the change
     * @throws \RuntimeException order_invalid_status | order_not_found | order_invalid_transition
     */
    public function changeStatus(string $id, string $newStatus, ?string $trackingNumber = null): array
    {
        if (!in_array($newStatus, self::ORDER_STATUSES, true)) {
            throw new InvalidInput('order_invalid_status');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT status, user_id, organizer_credits_granted FROM shop_orders WHERE id = :id FOR UPDATE'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                throw new NotFound('order_not_found');
            }

            $current = (string) $row['status'];
            $allowed = in_array($newStatus, self::STATUS_TRANSITIONS[$current] ?? [], true)
                // shipped -> shipped keeps the "numer przesyłki" correction flow working.
                || ($current === 'shipped' && $newStatus === 'shipped' && $trackingNumber !== null);
            if (!$allowed) {
                throw new Conflict('order_invalid_transition');
            }

            $fields = 'status = :status';
            $params = ['status' => $newStatus, 'id' => $id];
            if ($trackingNumber !== null) {
                $fields .= ', tracking_number = :tracking';
                $params['tracking'] = $trackingNumber;
            }
            $this->pdo->prepare('UPDATE shop_orders SET ' . $fields . ' WHERE id = :id')->execute($params);

            $userId = (string) $row['user_id'];
            $granted = (int) $row['organizer_credits_granted'];
            if ($newStatus === 'paid' && $granted === 0) {
                $quantity = $this->organizerPackageQuantity($id);
                if ($quantity > 0) {
                    $this->applyOrganizerPackageGrant($userId, $quantity);
                    $this->pdo->prepare('UPDATE shop_orders SET organizer_credits_granted = :granted WHERE id = :id')
                        ->execute(['granted' => $quantity, 'id' => $id]);
                }
            } elseif (in_array($newStatus, ['cancelled', 'refunded'], true) && $granted > 0) {
                $this->revokeUnusedOrganizerPackageGrant($userId, $granted);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($id) ?? [];
    }

    private function organizerPackageQuantity(string $orderId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(i.quantity), 0) FROM shop_order_items i
             JOIN shop_products p ON p.id = i.product_id
             WHERE i.order_id = :orderId AND p.service_kind = :kind'
        );
        $stmt->execute(['orderId' => $orderId, 'kind' => self::SERVICE_KIND_ORGANIZER_PACKAGE]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * What a paid organizer package gives the buyer, all inside changeStatus()'s transaction:
     * `quantity` credits (spent at Draft -> Submitted), the Organizator role for a plain participant
     * (role + token_version bump in ONE statement: the old-role session ends and
     * the buyer logs in again), and `quantity` event slots (`users.event_limit`, only meaningful for
     * role organizer - "prawo do jednego wydarzenia", drafts are free). Staff roles and
     * existing organizers keep their role and session; staff get credits only.
     */
    private function applyOrganizerPackageGrant(string $userId, int $quantity): void
    {
        $this->pdo->prepare(
            'UPDATE users SET organizer_package_credits = organizer_package_credits + :credits WHERE id = :userId'
        )->execute(['credits' => $quantity, 'userId' => $userId]);

        $this->pdo->prepare(
            'UPDATE users SET role = :newRole, token_version = token_version + 1 WHERE id = :userId AND role = :oldRole'
        )->execute(['newRole' => self::ROLE_ORGANIZER, 'userId' => $userId, 'oldRole' => 'participant']);

        $this->pdo->prepare(
            'UPDATE users SET event_limit = event_limit + :slots WHERE id = :userId AND role = :role'
        )->execute(['slots' => $quantity, 'userId' => $userId, 'role' => self::ROLE_ORGANIZER]);
    }

    /**
     * Cancel/refund of a paid, granted order takes back only what is
     * still unused - credits (a spent credit is a submitted event) and event slots (never below the
     * events the organizer already has). The role is never demoted automatically.
     */
    private function revokeUnusedOrganizerPackageGrant(string $userId, int $granted): void
    {
        $this->pdo->prepare(
            'UPDATE users SET organizer_package_credits = organizer_package_credits - LEAST(organizer_package_credits, :revoke)
             WHERE id = :userId'
        )->execute(['revoke' => $granted, 'userId' => $userId]);

        $stmt = $this->pdo->prepare('SELECT event_limit FROM users WHERE id = :userId AND role = :role FOR UPDATE');
        $stmt->execute(['userId' => $userId, 'role' => self::ROLE_ORGANIZER]);
        $limit = $stmt->fetchColumn();
        if ($limit === false) {
            return;
        }

        // Same definition of "used" as EventRepository::activeEventCount() (all non-deleted events).
        $used = $this->pdo->prepare('SELECT COUNT(*) FROM events WHERE organizer_id = :userId AND deleted_at IS NULL');
        $used->execute(['userId' => $userId]);
        $limit = (int) $limit;
        $newLimit = min($limit, max((int) $used->fetchColumn(), $limit - $granted));
        $this->pdo->prepare('UPDATE users SET event_limit = :newLimit WHERE id = :userId')
            ->execute(['newLimit' => $newLimit, 'userId' => $userId]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|null */
    public function update(string $id, array $data): ?array
    {
        $fields = [];
        $map = [
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

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Shop;

/**
 * PP.17: the only shape a shop order has when returned to its buyer (GET /me/orders*).
 * Allowlist: a key that is not listed here (including any column added later) is dropped.
 */
final class OwnOrderPresenter
{
    public const OWN_KEYS = [
        'id', 'orderNumber', 'userId', 'status',
        'productTotal', 'deliveryPrice', 'paymentSurcharge', 'priceAdjustment', 'totalPrice',
        'deliveryMethod', 'paymentMethod', 'shippingAddress', 'invoiceData',
        'trackingNumber', 'cancelReason', 'estimatedDelivery',
        'createdAt', 'updatedAt', 'items',
    ];

    /** Staff-only keys, dropped. Documentation + the completeness unit test; not used at runtime. */
    public const WITHHELD_KEYS = ['adminNotes', 'history'];

    public const DELIVERY_METHOD_KEYS = ['id', 'name', 'price', 'estimatedDays'];
    public const PAYMENT_METHOD_KEYS = ['id', 'name', 'surcharge'];
    public const ITEM_KEYS = ['productId', 'productName', 'quantity', 'price', 'size', 'color'];

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public static function present(array $order): array
    {
        $out = [];
        foreach (self::OWN_KEYS as $key) {
            if (array_key_exists($key, $order)) {
                $out[$key] = $order[$key];
            }
        }
        if (isset($out['deliveryMethod']) && is_array($out['deliveryMethod'])) {
            $out['deliveryMethod'] = self::pick($out['deliveryMethod'], self::DELIVERY_METHOD_KEYS);
        }
        if (isset($out['paymentMethod']) && is_array($out['paymentMethod'])) {
            $out['paymentMethod'] = self::pick($out['paymentMethod'], self::PAYMENT_METHOD_KEYS);
        }
        if (isset($out['items']) && is_array($out['items'])) {
            $out['items'] = array_values(array_map(
                static fn ($item): array => is_array($item) ? self::pick($item, self::ITEM_KEYS) : [],
                $out['items']
            ));
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $orders
     * @return list<array<string, mixed>>
     */
    public static function presentList(array $orders): array
    {
        return array_values(array_map([self::class, 'present'], $orders));
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function pick(array $source, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $out[$key] = $source[$key];
            }
        }
        return $out;
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Shop;

use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ZmierzymyCzas\Domain\Shop\OrderRepository;
use ZmierzymyCzas\Domain\Shop\OwnOrderPresenter;

final class OwnOrderPresenterTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fullOrder(): array
    {
        $order = [];
        foreach (array_merge(OwnOrderPresenter::OWN_KEYS, OwnOrderPresenter::WITHHELD_KEYS) as $key) {
            $order[$key] = 'v-' . $key;
        }
        $order['deliveryMethod'] = ['id' => 'courier', 'name' => 'Kurier', 'price' => 15.99, 'estimatedDays' => '1-2'];
        $order['paymentMethod'] = ['id' => 'blik', 'name' => 'BLIK', 'surcharge' => 0];
        $order['items'] = [['id' => 'i1', 'orderId' => 'o1', 'productId' => 'p1', 'productName' => 'Koszulka',
            'quantity' => 1, 'price' => '50.00', 'size' => null, 'color' => null]];
        return $order;
    }

    public function testWithheldKeysAreDropped(): void
    {
        $out = OwnOrderPresenter::present(self::fullOrder());
        foreach (OwnOrderPresenter::WITHHELD_KEYS as $key) {
            self::assertArrayNotHasKey($key, $out);
        }
        self::assertSame('v-orderNumber', $out['orderNumber']);
    }

    public function testUnknownKeysAreDropped(): void
    {
        $order = self::fullOrder();
        $order['futureSecretColumn'] = 'x';
        self::assertArrayNotHasKey('futureSecretColumn', OwnOrderPresenter::present($order));
    }

    public function testNestedMethodsAreReducedToCatalogKeys(): void
    {
        $order = self::fullOrder();
        $order['paymentMethod']['transactionId'] = 'tx-1';
        $order['deliveryMethod']['internalCode'] = 'c-1';
        $out = OwnOrderPresenter::present($order);
        self::assertSame(['id', 'name', 'surcharge'], array_keys($out['paymentMethod']));
        self::assertSame(['id', 'name', 'price', 'estimatedDays'], array_keys($out['deliveryMethod']));

        $order['deliveryMethod'] = [];
        self::assertSame([], OwnOrderPresenter::present($order)['deliveryMethod']);
    }

    public function testItemsAreProjected(): void
    {
        $out = OwnOrderPresenter::present(self::fullOrder());
        self::assertSame(OwnOrderPresenter::ITEM_KEYS, array_keys($out['items'][0]));
    }

    public function testNullValuedOwnKeysArePreserved(): void
    {
        $order = self::fullOrder();
        $order['trackingNumber'] = null;
        $out = OwnOrderPresenter::present($order);
        self::assertArrayHasKey('trackingNumber', $out);
        self::assertNull($out['trackingNumber']);
    }

    public function testPresentListReindexes(): void
    {
        self::assertCount(2, OwnOrderPresenter::presentList([5 => self::fullOrder(), 9 => self::fullOrder()]));
    }

    public function testEveryRepositoryColumnIsClassified(): void
    {
        $columns = (new ReflectionClassConstant(OrderRepository::class, 'ORDER_COLUMNS'))->getValue();
        self::assertIsString($columns);
        $aliases = [];
        foreach (explode(',', $columns) as $part) {
            $part = trim($part);
            $aliases[] = preg_match('/\sAS\s+(\w+)$/i', $part, $m) === 1 ? $m[1] : preg_replace('/^o\./', '', $part);
        }
        $aliases[] = 'items';
        self::assertCount(21, $aliases);
        $classified = array_merge(OwnOrderPresenter::OWN_KEYS, OwnOrderPresenter::WITHHELD_KEYS);
        foreach ($aliases as $alias) {
            self::assertContains($alias, $classified, "Column '$alias' is not classified in OwnOrderPresenter");
        }
    }
}

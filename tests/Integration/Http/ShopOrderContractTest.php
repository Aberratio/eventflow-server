<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Shop\ShopRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

final class ShopOrderContractTest extends HttpTestCase
{
    private UserRepository $users;
    private ShopRepository $shop;
    private string $prefix;
    private array $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->shop = new ShopRepository($this->pdo);
        $this->prefix = 'http-order-' . bin2hex(random_bytes(4));

        $this->buyer = $this->users->create([
            'name' => 'Order Buyer',
            'email' => $this->prefix . '-buyer@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $this->pdo->prepare('DELETE FROM shop_order_items WHERE product_id IN (SELECT id FROM shop_products WHERE slug LIKE :slug)')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM shop_orders WHERE user_id = :id')->execute(['id' => $this->buyer['id']]);
        $this->pdo->prepare('DELETE FROM shop_products WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testCreatingAnOrderPersistsItAndCanBeReadBack(): void
    {
        $product = $this->createProduct(['price' => 50, 'stockCount' => 10]);

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $this->orderPayload($product, 2),
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        $created = $this->json($response)['data'];
        self::assertNotEmpty($created['id']);

        $fetched = $this->json($this->request(
            'GET',
            '/api/v1/shop/orders/' . $created['id'],
            [],
            $this->bearer($this->buyer['id'], 'admin', $this->buyer['email'])
        ))['data'];
        self::assertSame($created['id'], $fetched['id']);
        self::assertSame(100.0, (float) $fetched['productTotal']);
    }

    public function testForgedClientPricesAreIgnoredInFavourOfTheCatalog(): void
    {
        $product = $this->createProduct(['price' => 50, 'stockCount' => 10]);

        $payload = $this->orderPayload($product, 1);
        $payload['items'][0]['price'] = 1;
        $payload['productTotal'] = 1;
        $payload['totalPrice'] = 1;
        $payload['deliveryMethod']['price'] = 0.01;

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        $created = $this->json($response)['data'];
        self::assertSame(50.0, (float) $created['items'][0]['price']);
        self::assertSame(50.0, (float) $created['productTotal']);
        // courier delivery price from the real catalog (15.99), not the forged 0.01.
        self::assertSame(65.99, (float) $created['totalPrice']);
    }

    public function testForgedUserIdIsIgnoredAndOrderIsAttributedToTheCaller(): void
    {
        $product = $this->createProduct(['price' => 20, 'stockCount' => 5]);
        $otherUser = $this->users->create([
            'name' => 'Someone Else',
            'email' => $this->prefix . '-other@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);

        $payload = $this->orderPayload($product, 1);
        $payload['userId'] = $otherUser['id'];

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $payload,
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        $created = $this->json($response)['data'];
        self::assertSame($this->buyer['id'], $created['userId']);
        self::assertNotSame($otherUser['id'], $created['userId']);
    }

    public function testOrderingMoreThanStockRejectsAndLeavesNoTraceOrDecrement(): void
    {
        $product = $this->createProduct(['price' => 30, 'stockCount' => 2]);

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $this->orderPayload($product, 5),
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('order_insufficient_stock', $this->json($response)['error']['code']);

        self::assertSame(0, $this->countOrdersForBuyer());
        self::assertSame(2, $this->currentStockCount($product['id']));
    }

    public function testOrderingAnArchivedProductIsRejected(): void
    {
        $product = $this->createProduct(['price' => 10, 'stockCount' => 10]);
        $this->pdo->prepare("UPDATE shop_products SET status = 'archived' WHERE id = :id")->execute(['id' => $product['id']]);

        $response = $this->request(
            'POST',
            '/api/v1/shop/orders',
            $this->orderPayload($product, 1),
            $this->bearer($this->buyer['id'], 'participant', $this->buyer['email'])
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('order_invalid_product', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countOrdersForBuyer());
    }

    private function countOrdersForBuyer(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM shop_orders WHERE user_id = :id');
        $stmt->execute(['id' => $this->buyer['id']]);
        return (int) $stmt->fetchColumn();
    }

    private function currentStockCount(string $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT stock_count FROM shop_products WHERE id = :id');
        $stmt->execute(['id' => $productId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function orderPayload(array $product, int $quantity): array
    {
        return [
            'items' => [
                [
                    'productId' => $product['id'],
                    'productName' => $product['name'],
                    'quantity' => $quantity,
                    'price' => 999999,
                ],
            ],
            'deliveryMethod' => ['id' => 'courier', 'name' => 'Kurier', 'price' => 999999],
            'paymentMethod' => ['id' => 'blik', 'name' => 'BLIK'],
        ];
    }

    /** @return array<string, mixed> */
    private function createProduct(array $overrides = []): array
    {
        return $this->shop->createProduct(array_merge([
            'name' => $this->prefix . ' Product',
            'slug' => $this->prefix . '-product-' . bin2hex(random_bytes(3)),
            'description' => 'Test description',
            'price' => 99.99,
            'category' => 'apparel',
            'brand' => 'Test Brand',
            'imageUrl' => 'https://example.test/product.jpg',
            'inStock' => true,
            'stockCount' => 10,
        ], $overrides));
    }
}

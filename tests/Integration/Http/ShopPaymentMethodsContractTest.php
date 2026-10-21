<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * GET /shop/payment-methods lists the methods the shop can really take.
 * Bank transfer is always offered; card and BLIK are online-gateway methods and
 * appear only when Tpay or PayNow is configured in the environment.
 */
final class ShopPaymentMethodsContractTest extends HttpTestCase
{
    private const KEYS = ['TPAY_CLIENT_ID', 'TPAY_CLIENT_SECRET', 'PAYNOW_API_KEY', 'PAYNOW_SIGNATURE_KEY'];

    protected function setUp(): void
    {
        // Gateway config is read when the routes are registered, so set it before the app is built.
        foreach (self::KEYS as $key) {
            Env::set($key, '');
        }
        if (str_contains($this->name(), 'Tpay')) {
            Env::set('TPAY_CLIENT_ID', 'phpunit-id');
            Env::set('TPAY_CLIENT_SECRET', 'phpunit-secret');
        }
        if (str_contains($this->name(), 'PayNow')) {
            Env::set('PAYNOW_API_KEY', 'phpunit-key');
            Env::set('PAYNOW_SIGNATURE_KEY', 'phpunit-sig');
        }
        parent::setUp();
    }

    /** @var list<string> */
    private array $createdUsers = [];

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec("DELETE FROM shop_order_items WHERE product_id IN (SELECT id FROM shop_products WHERE slug LIKE 'g06b-%')");
            foreach ($this->createdUsers as $userId) {
                $this->pdo->prepare('DELETE FROM shop_orders WHERE user_id = :id')->execute(['id' => $userId]);
                $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
            }
            $this->pdo->exec("DELETE FROM shop_products WHERE slug LIKE 'g06b-%'");
        }
        foreach (self::KEYS as $key) {
            Env::restore($key);
        }
    }

    /** @return list<string> */
    private function ids(): array
    {
        $response = $this->request('GET', '/api/v1/shop/payment-methods');
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        foreach ($data as $method) {
            self::assertSame(['id', 'name'], array_keys($method));
        }
        return array_column($data, 'id');
    }

    public function testWithoutAnyGatewayOnlyBankTransferIsOffered(): void
    {
        self::assertSame(['bank'], $this->ids());
    }

    public function testWithTpayConfiguredOnlineMethodsAreOffered(): void
    {
        self::assertSame(['card', 'blik', 'bank'], $this->ids());
    }

    public function testWithPayNowConfiguredOnlineMethodsAreOffered(): void
    {
        self::assertSame(['card', 'blik', 'bank'], $this->ids());
    }

    /** @return array<string, mixed> */
    private function orderBody(string $methodId): array
    {
        $shop = new \ZmierzymyCzas\Domain\Shop\ShopRepository($this->pdo);
        $product = $shop->createProduct([
            'name' => 'G06b product', 'slug' => 'g06b-' . bin2hex(random_bytes(4)), 'description' => 'd', 'price' => 10,
            'category' => 'apparel', 'brand' => 'b', 'imageUrl' => 'https://example.test/p.jpg', 'inStock' => true, 'stockCount' => 10,
        ]);

        return [
            'items' => [['productId' => $product['id'], 'productName' => $product['name'], 'quantity' => 1, 'price' => 10]],
            'deliveryMethod' => ['id' => 'courier', 'name' => 'Kurier', 'price' => 0],
            'paymentMethod' => ['id' => $methodId],
            'shippingAddress' => ['fullName' => 'Jan Kowalski', 'street' => 'Testowa 1', 'city' => 'Warszawa', 'postalCode' => '00-001'],
        ];
    }

    private function postOrder(string $methodId): \Psr\Http\Message\ResponseInterface
    {
        $user = (new \ZmierzymyCzas\Domain\Users\UserRepository($this->pdo))->create([
            'name' => 'G06b Buyer', 'email' => 'g06b-' . bin2hex(random_bytes(4)) . '@example.test', 'password' => 'demo1234', 'role' => 'participant',
        ]);
        $this->createdUsers[] = $user['id'];

        return $this->request('POST', '/api/v1/shop/orders', $this->orderBody($methodId), $this->bearer($user['id'], 'participant', $user['email']));
    }

    public function testOrderWithAnOnlineMethodIsRejectedWhenNoGatewayIsConfigured(): void
    {
        foreach (['card', 'blik'] as $method) {
            $response = $this->postOrder($method);
            self::assertSame(422, $response->getStatusCode(), $method);
            self::assertSame('order_invalid_payment_method', $this->json($response)['error']['code']);
        }
    }

    public function testBankTransferOrderIsAcceptedWithoutAGateway(): void
    {
        self::assertSame(201, $this->postOrder('bank')->getStatusCode());
    }

    public function testOnlineMethodsAreAcceptedWhenTpayIsConfigured(): void
    {
        foreach (['card', 'blik', 'bank'] as $method) {
            self::assertSame(201, $this->postOrder($method)->getStatusCode(), $method);
        }
    }
}

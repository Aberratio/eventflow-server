<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Shop\OrderRepository;
use ZmierzymyCzas\Domain\Shop\ShopRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;

final class OrderRepositoryTest extends TestCase
{
    private PDO $pdo;
    private ShopRepository $shop;
    private OrderRepository $orders;
    private UserRepository $users;
    private string $prefix;
    private array $buyer;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->prefix = 'phporder-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->shop = new ShopRepository($this->pdo);
            $this->orders = new OrderRepository($this->pdo, $this->shop);
            $this->users = new UserRepository($this->pdo);
            $this->buyer = $this->users->create([
                'name' => 'Concurrency Buyer',
                'email' => $this->prefix . '-buyer@example.test',
                'password' => 'demo1234',
                'role' => 'participant',
            ]);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $this->pdo->prepare('DELETE FROM shop_order_items WHERE product_id IN (SELECT id FROM shop_products WHERE slug LIKE :slug)')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM shop_orders WHERE user_id = :id')->execute(['id' => $this->buyer['id'] ?? '']);
        $this->pdo->prepare('DELETE FROM shop_products WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    /**
     * E5.2: mirrors
     * RegistrationRepositoryTest::testConcurrentCreatesForSameDistanceSerializeOnTheRowLock()
     * (E1.3) exactly, with a shop product's stock_count standing in for a
     * Dystans's registered_count — proves two simultaneous orders against the
     * same limited-stock product can't jointly oversell because the second
     * connection blocks on the first's row lock (SELECT ... FOR UPDATE) rather
     * than racing the stock check.
     */
    public function testConcurrentCreatesForSameProductSerializeOnTheRowLock(): void
    {
        $product = $this->shop->createProduct([
            'name' => $this->prefix . ' Concurrency Product',
            'slug' => $this->prefix . '-concurrency',
            'description' => 'Test',
            'price' => 40,
            'category' => 'apparel',
            'brand' => 'Test Brand',
            'imageUrl' => 'https://example.test/product.jpg',
            'inStock' => true,
            'stockCount' => 3,
        ]);

        $connectionA = Connection::fromEnv();
        $connectionA->beginTransaction();
        $connectionA->prepare('SELECT stock_count FROM shop_products WHERE id = :id FOR UPDATE')
            ->execute(['id' => $product['id']]);

        $connectionB = Connection::fromEnv();
        $connectionB->exec('SET innodb_lock_wait_timeout = 1');
        $ordersB = new OrderRepository($connectionB, new ShopRepository($connectionB));

        try {
            $ordersB->create($this->orderPayload($product, 1), $this->buyer['id']);
            self::fail('Expected connection B to block on connection A\'s row lock and time out.');
        } catch (\PDOException $e) {
            self::assertStringContainsStringIgnoringCase('lock wait timeout', $e->getMessage());
        } finally {
            $connectionA->rollBack();
        }

        self::assertSame(3, $this->rawStockCount($product['id']));
    }

    /**
     * PP.105: the grant lives in changeStatus('paid') now - status, credits, role and
     * event_limit change in ONE transaction, so a failure inside it (here: the event_limit
     * increment overflows the INT column under the strict session mode) leaves nothing behind.
     */
    public function testFailureInsideTheGrantRollsBackStatusCreditsAndRoleTogether(): void
    {
        $organizerPackage = $this->shop->createProduct([
            'name' => $this->prefix . ' Pakiet Organizatora',
            'slug' => $this->prefix . '-organizer-package',
            'description' => 'Prawo do utworzenia jednego Wydarzenia.',
            'price' => 499.0,
            'category' => 'organizer',
            'brand' => 'Test Brand',
            'imageUrl' => 'https://example.test/product.jpg',
            'inStock' => true,
            'stockCount' => null,
            'isService' => true,
            'serviceKind' => 'organizer_package',
        ]);
        $order = $this->orders->create([
            'items' => [['productId' => $organizerPackage['id'], 'productName' => $organizerPackage['name'], 'quantity' => 1]],
            'paymentMethod' => ['id' => 'card', 'name' => 'Karta platnicza'],
        ], $this->buyer['id']);
        self::assertSame('pending', $order['status']);
        self::assertSame(0, $this->organizerPackageCredits($this->buyer['id']));

        $this->pdo->prepare("UPDATE users SET role = 'organizer', event_limit = 2147483647 WHERE id = :id")
            ->execute(['id' => $this->buyer['id']]);

        try {
            $this->orders->changeStatus((string) $order['id'], 'paid', null);
            self::fail('Expected the event_limit overflow to abort the transition.');
        } catch (\PDOException) {
            // strict INT overflow
        }

        $stmt = $this->pdo->prepare('SELECT status FROM shop_orders WHERE id = :id');
        $stmt->execute(['id' => $order['id']]);
        self::assertSame('pending', $stmt->fetchColumn());
        self::assertSame(0, $this->organizerPackageCredits($this->buyer['id']));
    }

    private function organizerPackageCredits(string $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT organizer_package_credits FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function orderPayload(array $product, int $quantity): array
    {
        return [
            'items' => [
                ['productId' => $product['id'], 'productName' => $product['name'], 'quantity' => $quantity],
            ],
            'deliveryMethod' => ['id' => 'pickup', 'name' => 'Odbior osobisty'],
            'paymentMethod' => ['id' => 'card', 'name' => 'Karta platnicza'],
        ];
    }

    private function rawStockCount(string $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT stock_count FROM shop_products WHERE id = :id');
        $stmt->execute(['id' => $productId]);
        return (int) $stmt->fetchColumn();
    }
}

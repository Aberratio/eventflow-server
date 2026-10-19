<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * G-05: GET /admin/sales-stats — the shape the admin overview expects
 * (monthlyRevenue, topProducts, ordersByStatus). Revenue counts only paid
 * orders (paid / shipped / delivered); pending, cancelled and refunded are
 * excluded from revenue but still listed in ordersByStatus.
 */
final class AdminSalesStatsContractTest extends HttpTestCase
{
    private UserRepository $users;
    private string $prefix;
    private array $admin;
    private array $moderator;
    private array $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'sstat-' . bin2hex(random_bytes(4));
        $this->admin = $this->makeUser('admin', 'admin');
        $this->moderator = $this->makeUser('mod', 'moderator');
        $this->buyer = $this->makeUser('buyer', 'participant');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM shop_orders WHERE order_number LIKE :p')->execute(['p' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    private function makeUser(string $key, string $role): array
    {
        return $this->users->create([
            'name' => 'Sales Stats ' . $key,
            'email' => $this->prefix . '-' . $key . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ]);
    }

    private function insertOrder(string $n, string $status, float $total, string $productId, string $productName, int $qty, float $price): void
    {
        $id = bin2hex(random_bytes(16));
        $id = substr($id, 0, 8) . '-' . substr($id, 8, 4) . '-' . substr($id, 12, 4) . '-' . substr($id, 16, 4) . '-' . substr($id, 20, 12);
        $this->pdo->prepare(
            'INSERT INTO shop_orders (id, order_number, user_id, status, total_price, created_at) VALUES (:id, :n, :u, :s, :t, NOW())'
        )->execute(['id' => $id, 'n' => $this->prefix . '-' . $n, 'u' => $this->buyer['id'], 's' => $status, 't' => $total]);
        $this->pdo->prepare(
            'INSERT INTO shop_order_items (id, order_id, product_id, product_name, quantity, price) VALUES (:i, :o, :p, :pn, :q, :pr)'
        )->execute(['i' => substr(bin2hex(random_bytes(16)), 0, 32), 'o' => $id, 'p' => $productId, 'pn' => $productName, 'q' => $qty, 'pr' => $price]);
    }

    private function fetch(): array
    {
        $response = $this->request('GET', '/api/v1/admin/sales-stats', [], $this->bearer($this->admin['id'], 'admin', $this->admin['email']));
        self::assertSame(200, $response->getStatusCode());
        return $this->json($response)['data'];
    }

    public function testReturnsTheShapeTheAdminOverviewExpects(): void
    {
        $stats = $this->fetch();

        self::assertArrayHasKey('monthlyRevenue', $stats);
        self::assertArrayHasKey('topProducts', $stats);
        self::assertArrayHasKey('ordersByStatus', $stats);
        self::assertCount(12, $stats['monthlyRevenue']);
        self::assertSame(['month', 'revenue'], array_keys($stats['monthlyRevenue'][0]));
        self::assertSame(date('Y-m'), end($stats['monthlyRevenue'])['month']);
    }

    public function testRevenueCountsOnlyPaidOrders(): void
    {
        $before = $this->fetch();
        $revenueBefore = (float) end($before['monthlyRevenue'])['revenue'];

        $pid = 'sstat-prod-' . $this->prefix;
        $this->insertOrder('1', 'paid', 100.0, $pid, 'Stat Product', 2, 50.0);
        $this->insertOrder('2', 'delivered', 30.0, $pid, 'Stat Product', 1, 30.0);
        $this->insertOrder('3', 'pending', 999.0, $pid, 'Stat Product', 1, 999.0);
        $this->insertOrder('4', 'cancelled', 888.0, $pid, 'Stat Product', 1, 888.0);

        $after = $this->fetch();
        self::assertEqualsWithDelta($revenueBefore + 130.0, (float) end($after['monthlyRevenue'])['revenue'], 0.001);

        $top = array_values(array_filter($after['topProducts'], static fn (array $p): bool => $p['id'] === $pid));
        self::assertCount(1, $top);
        self::assertSame('Stat Product', $top[0]['name']);
        self::assertSame(3, $top[0]['sold']);
        self::assertEqualsWithDelta(130.0, (float) $top[0]['revenue'], 0.001);

        foreach ($after['ordersByStatus'] as $row) {
            self::assertSame(['status', 'count', 'fill'], array_keys($row));
            self::assertGreaterThan(0, $row['count']);
            self::assertNotSame('', $row['fill']);
        }
    }

    public function testOnlyAdminsMayRead(): void
    {
        self::assertSame(401, $this->request('GET', '/api/v1/admin/sales-stats')->getStatusCode());
        self::assertSame(403, $this->request('GET', '/api/v1/admin/sales-stats', [], $this->bearer($this->moderator['id'], 'moderator', $this->moderator['email']))->getStatusCode());
        self::assertSame(403, $this->request('GET', '/api/v1/admin/sales-stats', [], $this->bearer($this->buyer['id'], 'participant', $this->buyer['email']))->getStatusCode());
    }
}

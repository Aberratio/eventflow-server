<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Shop\OrderRepository;
use ZmierzymyCzas\Domain\Shop\ShopRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;

/**
 * Migration 067 adds shop_orders.organizer_credits_granted (the grant marker),
 * backfills it for orders that the old code already granted at checkout, and renames the
 * legacy `organizer_license` service kind to the canonical `organizer_package`.
 */
final class PP105OrganizerPackageMigrationTest extends TestCase
{
    private const FILE = '067_pp105_organizer_package_grant.sql';

    private PDO $pdo;
    private string $prefix;
    /** @var list<string> */
    private array $userIds = [];

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
        $this->prefix = 'pp105mig-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        foreach ($this->userIds as $userId) {
            $this->pdo->prepare('DELETE FROM shop_orders WHERE user_id = :id')->execute(['id' => $userId]);
        }
        $this->pdo->prepare('DELETE FROM shop_products WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testAddsTheMarkerColumnAndIsIdempotentEvenWhenHalfApplied(): void
    {
        $file = Migrator::migrationsDir() . '/' . self::FILE;

        Migrator::applyFile($this->pdo, $file);
        Migrator::applyFile($this->pdo, $file);
        $column = $this->markerColumn();
        self::assertCount(1, $column);
        self::assertSame('NO', $column[0]['IS_NULLABLE']);
        self::assertSame('0', (string) $column[0]['COLUMN_DEFAULT']);

        // Half-applied: the column is gone (as before the ALTER), the file is re-run once more.
        try {
            $this->pdo->exec('ALTER TABLE shop_orders DROP COLUMN organizer_credits_granted');
            self::assertCount(0, $this->markerColumn());
        } finally {
            Migrator::applyFile($this->pdo, $file);
        }
        self::assertCount(1, $this->markerColumn());
    }

    public function testBackfillMarksOrdersGrantedByTheOldCodeAndRenamesTheLegacyKind(): void
    {
        $file = Migrator::migrationsDir() . '/' . self::FILE;
        $shop = new ShopRepository($this->pdo);
        $buyer = $this->makeBuyer();
        $package = $this->product($shop, 'package', 'organizer_package');
        $legacy = $this->product($shop, 'legacy', 'organizer_license');
        $plain = $this->product($shop, 'plain', null);

        // State as the OLD code left it: no marker column at all (pre-067 schema), package orders already granted.
        $this->pdo->exec('ALTER TABLE shop_orders DROP COLUMN organizer_credits_granted');
        try {
            $packageOrder = $this->insertOrder($buyer, 'pending', [[$package, 3], [$plain, 1]]);
            $legacyOrder = $this->insertOrder($buyer, 'pending', [[$legacy, 2]]);
            $plainOrder = $this->insertOrder($buyer, 'pending', [[$plain, 1]]);

            Migrator::applyFile($this->pdo, $file);
        } finally {
            // Idempotent: restores the column if the body above failed before applying.
            Migrator::applyFile($this->pdo, $file);
        }

        self::assertSame(3, $this->marker($packageOrder));
        self::assertSame(0, $this->marker($legacyOrder));
        self::assertSame(0, $this->marker($plainOrder));
        self::assertSame('organizer_package', $this->kind($legacy));
        self::assertSame('organizer_package', $this->kind($package));
        self::assertNull($this->kind($plain));

        // A new order created after the migration is never touched by a replay of the file.
        $fresh = $this->insertOrder($buyer, 'pending', [[$package, 1]]);
        Migrator::applyFile($this->pdo, $file);
        self::assertSame(0, $this->marker($fresh));
        self::assertSame(3, $this->marker($packageOrder));
        self::assertSame(0, $this->marker($legacyOrder));

        // Marked order: paying it grants nothing more; the legacy-kind order (marker 0) grants on paid.
        $orders = new OrderRepository($this->pdo, $shop);
        $orders->changeStatus($packageOrder, 'paid', null);
        self::assertSame(0, $this->credits($buyer));
        $orders->changeStatus($legacyOrder, 'paid', null);
        self::assertSame(2, $this->credits($buyer));
        self::assertSame(2, $this->marker($legacyOrder));
    }

    public function testMigrationFileUsesNoIfNotExistsAndTheSeedUsesTheCanonicalKind(): void
    {
        $path = Migrator::migrationsDir() . '/' . self::FILE;
        self::assertFileExists($path);
        self::assertSame(0, preg_match('/ALTER\s+TABLE[^;]*IF\s+(NOT\s+)?EXISTS/i', (string) file_get_contents($path)));

        $seed = (string) file_get_contents(dirname(__DIR__, 2) . '/database/seeders/001_demo.sql');
        self::assertStringNotContainsString('organizer_license', $seed);
        self::assertStringContainsString("'organizer_package'", $seed);
    }

    /** @return list<array<string, mixed>> */
    private function markerColumn(): array
    {
        return $this->pdo->query(
            "SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'shop_orders' AND column_name = 'organizer_credits_granted'"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    private function makeBuyer(): array
    {
        $buyer = (new UserRepository($this->pdo))->create([
            'name' => 'Migration Buyer',
            'email' => $this->prefix . '-buyer@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $this->userIds[] = (string) $buyer['id'];

        return $buyer;
    }

    private function product(ShopRepository $shop, string $key, ?string $kind): string
    {
        $data = [
            'name' => $this->prefix . ' ' . $key,
            'slug' => $this->prefix . '-' . $key,
            'description' => 'Test',
            'price' => 10,
            'category' => 'organizer',
            'brand' => 'Test Brand',
            'imageUrl' => 'https://example.test/product.jpg',
            'inStock' => true,
            'stockCount' => null,
        ];
        if ($kind !== null) {
            $data['isService'] = true;
            $data['serviceKind'] = $kind;
        }

        return (string) $shop->createProduct($data)['id'];
    }

    /** @param array<string, mixed> $buyer @param list<array{0: string, 1: int}> $lines */
    private function insertOrder(array $buyer, string $status, array $lines): string
    {
        $id = $this->prefix . '-o' . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            "INSERT INTO shop_orders (id, order_number, user_id, status, product_total, delivery_price, payment_surcharge,
                price_adjustment, total_price, delivery_method, payment_method, history)
             VALUES (:id, :number, :userId, :status, 10, 0, 0, 0, 10, '{}', '{\"id\":\"bank\"}', '[]')"
        )->execute(['id' => $id, 'number' => strtoupper($id), 'userId' => $buyer['id'], 'status' => $status]);
        foreach ($lines as $i => [$productId, $quantity]) {
            $this->pdo->prepare(
                'INSERT INTO shop_order_items (id, order_id, product_id, product_name, quantity, price)
                 VALUES (:id, :orderId, :productId, :name, :quantity, 10)'
            )->execute(['id' => $id . '-i' . $i, 'orderId' => $id, 'productId' => $productId, 'name' => 'x', 'quantity' => $quantity]);
        }

        return $id;
    }

    private function marker(string $orderId): int
    {
        $stmt = $this->pdo->prepare('SELECT organizer_credits_granted FROM shop_orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);

        return (int) $stmt->fetchColumn();
    }

    private function kind(string $productId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT service_kind FROM shop_products WHERE id = :id');
        $stmt->execute(['id' => $productId]);
        $value = $stmt->fetchColumn();

        return $value === null || $value === false ? null : (string) $value;
    }

    /** @param array<string, mixed> $buyer */
    private function credits(array $buyer): int
    {
        $stmt = $this->pdo->prepare('SELECT organizer_package_credits FROM users WHERE id = :id');
        $stmt->execute(['id' => $buyer['id']]);

        return (int) $stmt->fetchColumn();
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Challenges\ChallengeRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Shop\ShopRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;

/**
 * "Uczestnictwo odblokowuje zakup Produktu".
 * ChallengeRepository::claimReward() gates purchase on real condition-tree
 * completion, prevents double-claiming via the reward_granted
 * "claim lock", and reuses OrderRepository::create() for the
 * actual order so stock/variant limits and zero-price handling are never
 * duplicated here.
 */
final class ChallengeRewardClaimTest extends TestCase
{
    private PDO $pdo;
    private ChallengeRepository $challenges;
    private ShopRepository $shop;
    private UserRepository $users;
    private string $prefix;
    private array $competitor;
    private string $challengeId;
    private string $eventId;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->prefix = 'phpreward-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->shop = new ShopRepository($this->pdo);
            $this->users = new UserRepository($this->pdo);
            $this->challenges = new ChallengeRepository($this->pdo);
            $this->competitor = $this->users->create([
                'name' => 'Reward Competitor',
                'email' => $this->prefix . '-competitor@example.test',
                'password' => 'demo1234',
                'role' => 'participant',
            ]);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for challenge reward claim tests: ' . $e->getMessage());
        }

        $this->challengeId = $this->prefix . '-chal';
        // A real Event row is required: event_threshold counts manual entries through an INNER JOIN on events.
        $event = (new EventRepository($this->pdo))->create([
            'name' => 'PHPUnit Reward Event',
            'slug' => $this->prefix . '-evt',
            'discipline' => 'running',
            'date' => (new \DateTimeImmutable('+90 days'))->format('Y-m-d'),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>desc</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->prefix . '-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'published',
            'registrationDeadline' => (new \DateTimeImmutable('+60 days'))->format('Y-m-d'),
            'featured' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $this->prefix . '-10k',
                'name' => '10 km',
                'length' => 10,
                'unit' => 'km',
                'categories' => ['Open'],
                'ageCategories' => [],
                'specialCategories' => [],
                'limit' => 100,
                'registeredCount' => 0,
                'price' => 80,
                'routeFiles' => [],
            ]],
        ]);
        $this->eventId = (string) $event['id'];

        $this->pdo->prepare(
            "INSERT INTO challenges (id, name, slug, description, min_completed, status, created_by)
             VALUES (:id, 'Reward Challenge', :slug, 'desc', 1, 'active', 'tester')"
        )->execute(['id' => $this->challengeId, 'slug' => $this->challengeId]);

        // Default create()-shape tree ("ukoncz wszystkie") would normally be
        // seeded by create(), but this fixture inserts the challenge row
        // directly (mirrors ChallengeCompletionTest) so the tree is seeded
        // explicitly instead, matching the flat rule exactly.
        $this->challenges->saveConditionTree($this->challengeId, [
            'type' => 'event_threshold',
            'requiredCount' => 1,
            'events' => [['eventId' => $this->eventId]],
        ]);

        $this->pdo->prepare(
            'INSERT INTO challenge_events (challenge_id, event_id, sort_order) VALUES (:challengeId, :eventId, 0)'
        )->execute(['challengeId' => $this->challengeId, 'eventId' => $this->eventId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM shop_order_items WHERE product_id IN (SELECT id FROM shop_products WHERE slug LIKE :slug)')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM shop_orders WHERE user_id = :id')->execute(['id' => $this->competitor['id'] ?? '']);
        $this->pdo->prepare('DELETE FROM shop_products WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM challenge_entries WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenge_products WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenge_condition_node_events WHERE node_id IN (SELECT id FROM challenge_condition_nodes WHERE challenge_id = :id)')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenge_condition_nodes WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenge_events WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $eventStmt = $this->pdo->prepare('SELECT id FROM events WHERE slug = :slug');
        $eventStmt->execute(['slug' => $this->prefix . '-evt']);
        foreach (($eventStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $eventId) {
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        }
        $this->pdo->prepare('DELETE FROM challenge_participations WHERE challenge_id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM challenges WHERE id = :id')->execute(['id' => $this->challengeId]);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    private function id(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function linkRewardProduct(array $product): void
    {
        $this->pdo->prepare(
            'INSERT INTO challenge_products (challenge_id, product_id, purpose, sort_order) VALUES (:challengeId, :productId, :purpose, 0)'
        )->execute(['challengeId' => $this->challengeId, 'productId' => $product['id'], 'purpose' => 'reward']);
    }

    private function approveEntry(): void
    {
        $this->pdo->prepare(
            "INSERT INTO challenge_entries (id, challenge_id, user_id, event_id, status, submitted_at, reviewed_at, reviewed_by, source)
             VALUES (:id, :challengeId, :userId, :eventId, 'approved', NOW(), NOW(), 'tester', 'manual')"
        )->execute(['id' => $this->id(), 'challengeId' => $this->challengeId, 'userId' => $this->competitor['id'], 'eventId' => $this->eventId]);
    }

    private function defaultOrderFields(): array
    {
        return [
            'deliveryMethod' => ['id' => 'pickup'],
            'paymentMethod' => ['id' => 'card'],
        ];
    }

    public function testCannotClaimBeforeJoining(): void
    {
        $product = $this->shop->createProduct($this->productData('ring', 199.0, 2));
        $this->linkRewardProduct($product);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('challenge_not_joined');

        $this->challenges->claimReward($this->challengeId, $product['id'], $this->defaultOrderFields(), $this->competitor['id']);
    }

    public function testCannotClaimBeforeCompletion(): void
    {
        $this->challenges->join($this->challengeId, $this->competitor);
        $product = $this->shop->createProduct($this->productData('ring2', 199.0, 2));
        $this->linkRewardProduct($product);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('challenge_not_completed');

        $this->challenges->claimReward($this->challengeId, $product['id'], $this->defaultOrderFields(), $this->competitor['id']);
    }

    public function testCanClaimAfterRealTreeCompletion(): void
    {
        $this->challenges->join($this->challengeId, $this->competitor);
        $this->approveEntry();
        $product = $this->shop->createProduct($this->productData('ring3', 199.0, 2));
        $this->linkRewardProduct($product);

        $participation = $this->challenges->claimReward($this->challengeId, $product['id'], $this->defaultOrderFields(), $this->competitor['id']);

        self::assertTrue($participation['rewardGranted']);
        self::assertNotNull($participation['rewardGrantedAt']);
        self::assertNotEmpty($participation['selectedRewards']);
        self::assertSame($product['id'], $participation['selectedRewards'][0]['productId']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM shop_order_items WHERE product_id = :id');
        $stmt->execute(['id' => $product['id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testDoubleClaimIsRejectedAndDoesNotCreateASecondOrder(): void
    {
        $this->challenges->join($this->challengeId, $this->competitor);
        $this->approveEntry();
        $product = $this->shop->createProduct($this->productData('ring4', 199.0, 5));
        $this->linkRewardProduct($product);

        $this->challenges->claimReward($this->challengeId, $product['id'], $this->defaultOrderFields(), $this->competitor['id']);

        try {
            $this->challenges->claimReward($this->challengeId, $product['id'], $this->defaultOrderFields(), $this->competitor['id']);
            self::fail('Expected the second claim to be rejected.');
        } catch (\RuntimeException $e) {
            self::assertSame('challenge_reward_already_claimed', $e->getMessage());
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM shop_order_items WHERE product_id = :id');
        $stmt->execute(['id' => $product['id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    /**
     * The stock-exhaustion path exercises OrderRepository::create()'s
     * existing `order_insufficient_stock` error unchanged — the reward claim deliberately
     * doesn't build a parallel Challenge-specific stock rule.
     * A failed claim must also release the reward_granted "claim lock" so
     * the competitor can retry once stock is replenished, and must not touch
     * challenge completion at all.
     */
    public function testStockExhaustionFailsTheClaimWithoutGrantingRewardOrBreakingCompletion(): void
    {
        $this->challenges->join($this->challengeId, $this->competitor);
        $this->approveEntry();
        $product = $this->shop->createProduct($this->productData('ring5', 199.0, 0));
        $this->linkRewardProduct($product);

        try {
            $this->challenges->claimReward($this->challengeId, $product['id'], $this->defaultOrderFields(), $this->competitor['id']);
            self::fail('Expected order_insufficient_stock.');
        } catch (\RuntimeException $e) {
            self::assertSame('order_insufficient_stock', $e->getMessage());
        }

        $participation = $this->challenges->participations($this->challengeId, $this->competitor['id'])[0];
        self::assertFalse($participation['rewardGranted']);
        self::assertNull($participation['rewardGrantedAt']);
        // Completion itself must be untouched by the failed purchase attempt.
        self::assertTrue($participation['isCompleted']);
    }

    public function testZeroPriceRewardClaimsSuccessfullyThroughTheSameOrderFlow(): void
    {
        $this->challenges->join($this->challengeId, $this->competitor);
        $this->approveEntry();
        $product = $this->shop->createProduct($this->productData('diploma', 0.0, null));
        $this->linkRewardProduct($product);

        $participation = $this->challenges->claimReward($this->challengeId, $product['id'], $this->defaultOrderFields(), $this->competitor['id']);

        self::assertTrue($participation['rewardGranted']);

        $stmt = $this->pdo->prepare('SELECT total_price AS totalPrice FROM shop_orders WHERE id = :id');
        $stmt->execute(['id' => $participation['selectedRewards'][0]['orderId']]);
        $order = $stmt->fetch();
        self::assertSame(0.0, (float) $order['totalPrice']);
    }

    public function testRewardOrderWithCardIsRejectedWhenNoGatewayIsConfigured(): void
    {
        $challenges = new ChallengeRepository($this->pdo, false);
        $challenges->join($this->challengeId, $this->competitor);
        $this->approveEntry();
        $product = $this->shop->createProduct($this->productData('nogw-card', 199.0, 2));
        $this->linkRewardProduct($product);

        try {
            $challenges->claimReward($this->challengeId, $product['id'], $this->defaultOrderFields(), $this->competitor['id']);
            self::fail('card must be rejected without a gateway');
        } catch (\RuntimeException $e) {
            // Same code as POST /shop/orders (OrderRepository, G-06b).
            self::assertSame('order_invalid_payment_method', $e->getMessage());
        }

        // The claim is rolled back, so it can be retried with an offered method.
        $participation = $challenges->claimReward($this->challengeId, $product['id'], [
            'deliveryMethod' => ['id' => 'pickup'],
            'paymentMethod' => ['id' => 'bank'],
        ], $this->competitor['id']);
        self::assertTrue($participation['rewardGranted']);
    }

    public function testCannotClaimAProductNotLinkedAsAChallengeReward(): void
    {
        $this->challenges->join($this->challengeId, $this->competitor);
        $this->approveEntry();
        $product = $this->shop->createProduct($this->productData('unrelated', 50.0, 5));
        // Deliberately NOT linked via linkRewardProduct().

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('challenge_invalid_reward_product');

        $this->challenges->claimReward($this->challengeId, $product['id'], $this->defaultOrderFields(), $this->competitor['id']);
    }

    /** @return array<string, mixed> */
    private function productData(string $slugSuffix, float $price, ?int $stockCount): array
    {
        return [
            'name' => $this->prefix . ' ' . $slugSuffix,
            'slug' => $this->prefix . '-' . $slugSuffix,
            'description' => 'Test reward product',
            'price' => $price,
            'category' => 'apparel',
            'brand' => 'Test Brand',
            'imageUrl' => 'https://example.test/product.jpg',
            // inStock stays true even for stockCount=0 on purpose — the test
            // that uses stockCount=0 wants to exercise
            // order_insufficient_stock (quantity > stockCount), not the
            // separate order_out_of_stock ("off the shelf entirely") check.
            'inStock' => true,
            'stockCount' => $stockCount,
        ];
    }
}

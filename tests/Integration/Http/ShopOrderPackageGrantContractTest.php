<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Shop\OrderRepository;
use ZmierzymyCzas\Domain\Shop\ShopRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * An organizer package grants credits, the
 * Organizator role and an event slot only when its shop order becomes `paid`
 * (the single door is OrderRepository::changeStatus()), never at checkout.
 */
final class ShopOrderPackageGrantContractTest extends HttpTestCase
{
    private UserRepository $users;
    private ShopRepository $shop;
    private string $prefix;
    private array $buyer;
    private array $moderator;
    private array $admin;
    private array $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->shop = new ShopRepository($this->pdo);
        $this->prefix = 'pp105-' . bin2hex(random_bytes(4));

        $this->buyer = $this->makeUser('buyer', 'participant');
        $this->moderator = $this->makeUser('mod', 'moderator');
        $this->admin = $this->makeUser('admin', 'admin');
        $this->product = $this->shop->createProduct([
            'name' => $this->prefix . ' Pakiet Organizatora',
            'slug' => $this->prefix . '-organizer-package',
            'description' => 'Prawo do utworzenia jednego Wydarzenia.',
            'price' => 499.0,
            'category' => 'organizer',
            'brand' => 'Zmierzymy Czas',
            'imageUrl' => 'https://example.test/package.jpg',
            'inStock' => true,
            'stockCount' => null,
            'isService' => true,
            'serviceKind' => 'organizer_package',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $ids = $this->pdo->prepare('SELECT id FROM users WHERE email LIKE :email');
        $ids->execute(['email' => $this->prefix . '-%@example.test']);
        foreach (($ids->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $userId) {
            $events = $this->pdo->prepare('SELECT id FROM events WHERE organizer_id = :id');
            $events->execute(['id' => $userId]);
            foreach (($events->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $eventId) {
                $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
                $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
            }
            $this->pdo->prepare('DELETE FROM shop_orders WHERE user_id = :id')->execute(['id' => $userId]);
        }
        $this->pdo->prepare('DELETE FROM shop_products WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    // T1
    public function testCreatingAPackageOrderGrantsNothing(): void
    {
        $before = $this->userRow($this->buyer['id']);

        $response = $this->createOrder(2, $this->asBuyer());

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('pending', $this->json($response)['data']['status']);
        $after = $this->userRow($this->buyer['id']);
        self::assertSame('participant', $after['role']);
        self::assertSame(0, (int) $after['organizer_package_credits']);
        self::assertSame(0, (int) $after['event_limit']);
        self::assertSame((int) $before['token_version'], (int) $after['token_version']);
    }

    // T1 (organizer and admin buyers)
    public function testCreatingAPackageOrderGrantsNothingToOrganizerAndAdminBuyers(): void
    {
        $this->users->update($this->buyer['id'], ['role' => 'organizer']);
        self::assertSame(201, $this->createOrder(1, $this->bearer($this->buyer['id'], 'organizer', $this->buyer['email']))->getStatusCode());
        self::assertSame(0, (int) $this->userRow($this->buyer['id'])['organizer_package_credits']);

        $this->users->update($this->buyer['id'], ['role' => 'admin']);
        self::assertSame(201, $this->createOrder(1, $this->bearer($this->buyer['id'], 'admin', $this->buyer['email']))->getStatusCode());
        self::assertSame(0, (int) $this->userRow($this->buyer['id'])['organizer_package_credits']);
    }

    // T2
    public function testPackageQuantityOutsideOneToTenIsRejected(): void
    {
        foreach ([0, -5, 11, 1000, 'abc', 1.5] as $quantity) {
            $response = $this->createOrder($quantity, $this->asBuyer());
            self::assertSame(422, $response->getStatusCode(), 'quantity ' . json_encode($quantity));
            self::assertSame('order_invalid_quantity', $this->json($response)['error']['code']);
        }
        self::assertSame(0, $this->countOrders($this->buyer['id']));

        $max = $this->createOrder(10, $this->asBuyer());
        self::assertSame(201, $max->getStatusCode());
    }

    // T3
    public function testModeratorMarkingPaidGrantsCreditsRoleAndEventSlotAndEndsTheOldSession(): void
    {
        $oldToken = $this->asBuyer();
        $orderId = $this->orderId($this->createOrder(2, $oldToken));
        $tvBefore = $this->tokenVersionOf($this->buyer['id']);

        $paid = $this->setStatus($orderId, 'paid', $this->asModerator());

        self::assertSame(200, $paid->getStatusCode());
        self::assertSame('paid', $this->json($paid)['data']['status']);
        $row = $this->userRow($this->buyer['id']);
        self::assertSame('organizer', $row['role']);
        self::assertSame(2, (int) $row['organizer_package_credits']);
        self::assertSame(2, (int) $row['event_limit']);
        self::assertSame($tvBefore + 1, (int) $row['token_version']);
        self::assertSame(2, $this->grantedMarker($orderId));

        self::assertSame(401, $this->request('GET', '/api/v1/me/orders', [], $oldToken)->getStatusCode());

        $fresh = $this->bearer($this->buyer['id'], 'organizer', $this->buyer['email']);
        $create = $this->request('POST', '/api/v1/events', $this->eventPayload('first'), $fresh);
        self::assertSame(201, $create->getStatusCode());
        $eventId = $this->json($create)['data']['id'];

        $submit = $this->request('POST', '/api/v1/events/' . $eventId . '/submit', [], $fresh);
        self::assertSame(200, $submit->getStatusCode());
        self::assertSame(1, (int) $this->userRow($this->buyer['id'])['organizer_package_credits']);
    }

    // T4
    public function testExistingOrganizerBuyerGetsCreditsAndSlotsButKeepsSessionAndRole(): void
    {
        $this->users->update($this->buyer['id'], ['role' => 'organizer']);
        $token = $this->bearer($this->buyer['id'], 'organizer', $this->buyer['email']);
        $orderId = $this->orderId($this->createOrder(3, $token));
        $tvBefore = $this->tokenVersionOf($this->buyer['id']);

        self::assertSame(200, $this->setStatus($orderId, 'paid', $this->asModerator())->getStatusCode());

        $row = $this->userRow($this->buyer['id']);
        self::assertSame('organizer', $row['role']);
        self::assertSame(3, (int) $row['organizer_package_credits']);
        self::assertSame(3, (int) $row['event_limit']);
        self::assertSame($tvBefore, (int) $row['token_version']);
        self::assertSame(200, $this->request('GET', '/api/v1/me/orders', [], $token)->getStatusCode());
    }

    // T5
    public function testAdminAndModeratorBuyersGetCreditsOnly(): void
    {
        foreach (['admin', 'moderator'] as $role) {
            $this->users->update($this->buyer['id'], ['role' => $role]);
            $orderId = $this->orderId($this->createOrder(1, $this->bearer($this->buyer['id'], $role, $this->buyer['email'])));
            self::assertSame(200, $this->setStatus($orderId, 'paid', $this->asAdmin())->getStatusCode());
        }

        $row = $this->userRow($this->buyer['id']);
        self::assertSame('moderator', $row['role']);
        self::assertSame(2, (int) $row['organizer_package_credits']);
        self::assertSame(0, (int) $row['event_limit']);
    }

    // T6
    public function testRepeatedPaidIsRejectedAndLaterStatusesGrantNothingMore(): void
    {
        $orderId = $this->orderId($this->createOrder(2, $this->asBuyer()));
        self::assertSame(200, $this->setStatus($orderId, 'paid', $this->asModerator())->getStatusCode());

        $again = $this->setStatus($orderId, 'paid', $this->asModerator());
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('order_invalid_transition', $this->json($again)['error']['code']);
        self::assertSame(2, (int) $this->userRow($this->buyer['id'])['organizer_package_credits']);

        self::assertSame(200, $this->setStatus($orderId, 'shipped', $this->asModerator(), 'TRK-1')->getStatusCode());
        self::assertSame(200, $this->setStatus($orderId, 'shipped', $this->asModerator(), 'TRK-2')->getStatusCode());
        self::assertSame(200, $this->setStatus($orderId, 'delivered', $this->asModerator())->getStatusCode());

        $row = $this->userRow($this->buyer['id']);
        self::assertSame(2, (int) $row['organizer_package_credits']);
        self::assertSame(2, (int) $row['event_limit']);
        $order = $this->json($this->request('GET', '/api/v1/shop/orders/' . $orderId, [], $this->asModerator()))['data'];
        self::assertSame('TRK-2', $order['trackingNumber']);
    }

    // T7
    public function testCancelledPendingOrderNeverGrantsAndCannotBePaid(): void
    {
        $orderId = $this->orderId($this->createOrder(1, $this->asBuyer()));

        self::assertSame(200, $this->setStatus($orderId, 'cancelled', $this->asModerator())->getStatusCode());
        $paid = $this->setStatus($orderId, 'paid', $this->asModerator());

        self::assertSame(409, $paid->getStatusCode());
        $row = $this->userRow($this->buyer['id']);
        self::assertSame('participant', $row['role']);
        self::assertSame(0, (int) $row['organizer_package_credits']);
        self::assertSame(0, $this->grantedMarker($orderId));
    }

    // T8
    public function testRefundRemovesOnlyUnusedCreditsLowersLimitNotBelowActiveEventsAndKeepsRole(): void
    {
        $orderId = $this->orderId($this->createOrder(3, $this->asBuyer()));
        self::assertSame(200, $this->setStatus($orderId, 'paid', $this->asModerator())->getStatusCode());

        $fresh = $this->bearer($this->buyer['id'], 'organizer', $this->buyer['email']);
        self::assertSame(201, $this->request('POST', '/api/v1/events', $this->eventPayload('kept'), $fresh)->getStatusCode());
        // One credit was spent on a submission (simulated: submit would consume it).
        $this->pdo->prepare('UPDATE users SET organizer_package_credits = 2 WHERE id = :id')->execute(['id' => $this->buyer['id']]);

        $refund = $this->setStatus($orderId, 'refunded', $this->asAdmin());

        self::assertSame(200, $refund->getStatusCode());
        $row = $this->userRow($this->buyer['id']);
        self::assertSame(0, (int) $row['organizer_package_credits']);
        self::assertSame(1, (int) $row['event_limit']);
        self::assertSame('organizer', $row['role']);
        self::assertSame(3, $this->grantedMarker($orderId));
    }

    public function testCancellingAPaidOrderWithoutAnyUsageRemovesEverythingButTheRole(): void
    {
        $orderId = $this->orderId($this->createOrder(2, $this->asBuyer()));
        self::assertSame(200, $this->setStatus($orderId, 'paid', $this->asModerator())->getStatusCode());

        self::assertSame(200, $this->setStatus($orderId, 'cancelled', $this->asModerator())->getStatusCode());

        $row = $this->userRow($this->buyer['id']);
        self::assertSame(0, (int) $row['organizer_package_credits']);
        self::assertSame(0, (int) $row['event_limit']);
        self::assertSame('organizer', $row['role']);
        self::assertSame(409, $this->setStatus($orderId, 'paid', $this->asModerator())->getStatusCode());
    }

    // T9
    public function testUnknownStatusIs422AndMissingOrderIs404(): void
    {
        $orderId = $this->orderId($this->createOrder(1, $this->asBuyer()));

        $bogus = $this->setStatus($orderId, 'bogus', $this->asModerator());
        self::assertSame(422, $bogus->getStatusCode());
        self::assertSame('order_invalid_status', $this->json($bogus)['error']['code']);

        $empty = $this->setStatus($orderId, '', $this->asModerator());
        self::assertSame(422, $empty->getStatusCode());

        $missing = $this->setStatus($this->prefix . '-nope', 'paid', $this->asModerator());
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('order_not_found', $this->json($missing)['error']['code']);
        self::assertSame('pending', $this->orderStatus($orderId));
    }

    // T10
    public function testAdminPatchOrderStatusIsTheSameSingleDoor(): void
    {
        $orderId = $this->orderId($this->createOrder(2, $this->asBuyer()));

        $patch = $this->request('PATCH', '/api/v1/shop/orders/' . $orderId, ['status' => 'paid', 'adminNotes' => 'ok'], $this->asAdmin());

        self::assertSame(200, $patch->getStatusCode());
        $row = $this->userRow($this->buyer['id']);
        self::assertSame('organizer', $row['role']);
        self::assertSame(2, (int) $row['organizer_package_credits']);
        self::assertSame(409, $this->setStatus($orderId, 'paid', $this->asAdmin())->getStatusCode());
        // The same status in a full-object edit is a no-op (not a second grant, not an error).
        self::assertSame(200, $this->request('PATCH', '/api/v1/shop/orders/' . $orderId, ['status' => 'paid'], $this->asAdmin())->getStatusCode());
        self::assertSame(2, (int) $this->userRow($this->buyer['id'])['organizer_package_credits']);

        // An edit without a status still works.
        $notes = $this->request('PATCH', '/api/v1/shop/orders/' . $orderId, ['adminNotes' => 'edited'], $this->asAdmin());
        self::assertSame(200, $notes->getStatusCode());
        self::assertSame('edited', $this->json($notes)['data']['adminNotes']);
    }

    // T11
    public function testProductServiceKindIsAllowlisted(): void
    {
        $payload = [
            'name' => $this->prefix . ' P', 'slug' => $this->prefix . '-p', 'description' => 'd', 'category' => 'c',
            'brand' => 'b', 'imageUrl' => 'https://example.test/p.jpg', 'price' => 10,
        ];
        foreach (['organizer_license', 'whatever'] as $kind) {
            $create = $this->request('POST', '/api/v1/shop/products', $payload + ['serviceKind' => $kind], $this->asAdmin());
            self::assertSame(422, $create->getStatusCode(), $kind);
            self::assertSame('validation_error', $this->json($create)['error']['code']);
            $patch = $this->request('PATCH', '/api/v1/shop/products/' . $this->product['id'], ['serviceKind' => $kind], $this->asAdmin());
            self::assertSame(422, $patch->getStatusCode(), $kind);
        }

        self::assertSame(201, $this->request('POST', '/api/v1/shop/products', $payload + ['serviceKind' => 'organizer_package'], $this->asAdmin())->getStatusCode());
        self::assertSame(201, $this->request('POST', '/api/v1/shop/products', ['slug' => $this->prefix . '-q'] + $payload, $this->asAdmin())->getStatusCode());
    }

    public function testStaffOrderListCarriesTheItemsOfEachOrder(): void
    {
        $orderId = $this->orderId($this->createOrder(2, $this->asBuyer()));

        $list = $this->json($this->request('GET', '/api/v1/shop/orders?userId=' . $this->buyer['id'], [], $this->asModerator()))['data'];

        self::assertCount(1, $list);
        self::assertSame($orderId, $list[0]['id']);
        self::assertCount(1, $list[0]['items']);
        self::assertSame(2, (int) $list[0]['items'][0]['quantity']);
        self::assertSame($this->product['id'], $list[0]['items'][0]['productId']);
    }

    // T12
    public function testConcurrentMarkPaidGrantsExactlyOnce(): void
    {
        $orderId = $this->orderId($this->createOrder(2, $this->asBuyer()));

        $connectionA = Connection::fromEnv();
        $connectionA->beginTransaction();
        $connectionA->prepare('SELECT status FROM shop_orders WHERE id = :id FOR UPDATE')->execute(['id' => $orderId]);

        $connectionB = Connection::fromEnv();
        $connectionB->exec('SET innodb_lock_wait_timeout = 1');
        $ordersB = new OrderRepository($connectionB, new ShopRepository($connectionB));

        try {
            $ordersB->changeStatus($orderId, 'paid', null);
            self::fail('Expected connection B to block on the order row lock.');
        } catch (\PDOException $e) {
            self::assertStringContainsStringIgnoringCase('lock wait timeout', $e->getMessage());
        } finally {
            $connectionA->rollBack();
        }
        self::assertSame(0, (int) $this->userRow($this->buyer['id'])['organizer_package_credits']);

        $orders = new OrderRepository($this->pdo, $this->shop);
        $orders->changeStatus($orderId, 'paid', null);
        try {
            $orders->changeStatus($orderId, 'paid', null);
            self::fail('Second paid must be an illegal transition.');
        } catch (\RuntimeException $e) {
            self::assertSame('order_invalid_transition', $e->getMessage());
        }
        self::assertSame(2, (int) $this->userRow($this->buyer['id'])['organizer_package_credits']);
    }

    public function testFailureInsideTheGrantRollsBackStatusCreditsAndRoleTogether(): void
    {
        $orderId = $this->orderId($this->createOrder(2, $this->asBuyer()));
        // The credit step succeeds, the event_limit step overflows the INT column (strict mode): everything must roll back.
        $this->pdo->prepare('UPDATE users SET event_limit = 2147483647 WHERE id = :id')->execute(['id' => $this->buyer['id']]);
        $this->pdo->prepare("UPDATE users SET role = 'organizer' WHERE id = :id")->execute(['id' => $this->buyer['id']]);

        try {
            (new OrderRepository($this->pdo, $this->shop))->changeStatus($orderId, 'paid', null);
            self::fail('Expected the event_limit overflow to abort the transition.');
        } catch (\PDOException) {
            // strict INT overflow
        }

        self::assertSame('pending', $this->orderStatus($orderId));
        self::assertSame(0, (int) $this->userRow($this->buyer['id'])['organizer_package_credits']);
        self::assertSame(0, $this->grantedMarker($orderId));
    }

    /** @return array<string, string> */
    private function asBuyer(): array
    {
        return $this->bearer($this->buyer['id'], 'participant', $this->buyer['email']);
    }

    /** @return array<string, string> */
    private function asModerator(): array
    {
        return $this->bearer($this->moderator['id'], 'moderator', $this->moderator['email']);
    }

    /** @return array<string, string> */
    private function asAdmin(): array
    {
        return $this->bearer($this->admin['id'], 'admin', $this->admin['email']);
    }

    /** @return array<string, mixed> */
    private function makeUser(string $key, string $role): array
    {
        return $this->users->create([
            'name' => 'PP105 ' . $key,
            'email' => $this->prefix . '-' . $key . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ]);
    }

    /** @param int|string|float $quantity @param array<string, string> $headers */
    private function createOrder(int|string|float $quantity, array $headers): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('POST', '/api/v1/shop/orders', [
            'items' => [['productId' => $this->product['id'], 'productName' => 'x', 'quantity' => $quantity, 'price' => 1]],
            'paymentMethod' => ['id' => 'bank', 'name' => 'Przelew'],
        ], $headers);
    }

    private function orderId(\Psr\Http\Message\ResponseInterface $response): string
    {
        self::assertSame(201, $response->getStatusCode());
        return (string) $this->json($response)['data']['id'];
    }

    /** @param array<string, string> $headers */
    private function setStatus(string $orderId, string $status, array $headers, ?string $tracking = null): \Psr\Http\Message\ResponseInterface
    {
        $body = ['status' => $status];
        if ($tracking !== null) {
            $body['trackingNumber'] = $tracking;
        }
        return $this->request('PATCH', '/api/v1/shop/orders/' . $orderId . '/status', $body, $headers);
    }

    /** @return array<string, mixed> */
    private function userRow(string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT role, organizer_package_credits, event_limit, token_version FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function grantedMarker(string $orderId): int
    {
        $stmt = $this->pdo->prepare('SELECT organizer_credits_granted FROM shop_orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        return (int) $stmt->fetchColumn();
    }

    private function orderStatus(string $orderId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM shop_orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        return (string) $stmt->fetchColumn();
    }

    private function countOrders(string $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM shop_orders WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function eventPayload(string $suffix): array
    {
        $slug = $this->prefix . '-ev-' . $suffix;
        return [
            'name' => 'PP105 Event ' . $suffix,
            'slug' => $slug,
            'discipline' => 'running',
            'date' => (new \DateTimeImmutable('+90 days'))->format('Y-m-d'),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->buyer['id'],
            'organizerName' => 'PP105 buyer',
            'status' => 'draft',
            'registrationDeadline' => (new \DateTimeImmutable('+60 days'))->format('Y-m-d'),
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $slug . '-10k', 'name' => '10 km', 'length' => 10, 'unit' => 'km', 'categories' => ['Open'],
                'ageCategories' => [], 'specialCategories' => [], 'limit' => 100, 'registeredCount' => 0,
                'price' => 80, 'routeFiles' => [],
            ]],
        ];
    }
}

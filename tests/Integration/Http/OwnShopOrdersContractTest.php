<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Shop\OwnOrderPresenter;
use ZmierzymyCzas\Tests\Integration\Http\Rbac\RbacFixtures;
use ZmierzymyCzas\Tests\Support\HttpTestCase;
use ZmierzymyCzas\Tests\Support\SavepointPdo;

/**
 * PP.17: GET /me/orders and GET /me/orders/{id} - ownership, buyer projection and the
 * no-enumeration 404, on the RBAC fixture world (order = owner's, orderForeign = participant's).
 */
final class OwnShopOrdersContractTest extends HttpTestCase
{
    private static ?SavepointPdo $sharedPdo = null;
    private static RbacFixtures $fx;

    public static function setUpBeforeClass(): void
    {
        [self::$sharedPdo, self::$fx] = SavepointPdo::openWorld(RbacFixtures::build(...));
    }

    public static function tearDownAfterClass(): void
    {
        self::$sharedPdo?->rollbackOuter();
        self::$sharedPdo = null;
    }

    protected function createPdo(): \PDO
    {
        return self::$sharedPdo ?? throw new \LogicException('The fixture world is not open.');
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$sharedPdo->savepoint('own_orders_test');
    }

    protected function tearDown(): void
    {
        self::$sharedPdo->rollbackTo('own_orders_test');
    }

    /** @return array<string, string> */
    private function as(string $actor): array
    {
        $i = self::$fx->actors[$actor];

        return $this->bearer($i['id'], $i['role'], $i['email']);
    }

    /** @return array{0: int, 1: string, 2: array<string, mixed>} */
    private function get(string $path, ?string $actor): array
    {
        $response = $this->request('GET', $path, [], $actor === null ? [] : $this->as($actor));
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);

        return [$response->getStatusCode(), $raw, is_array($decoded) ? $decoded : []];
    }

    /** @return list<string> */
    private function listIds(string $actor, string $query = ''): array
    {
        [$status, , $body] = $this->get('/api/v1/me/orders' . $query, $actor);
        self::assertSame(200, $status, "$actor: GET /me/orders");

        return array_map(static fn (array $o): string => (string) $o['id'], $body['data']);
    }

    public function testParticipantListsOnlyOwnOrdersWithItems(): void
    {
        [$status, , $body] = $this->get('/api/v1/me/orders', 'owner');
        self::assertSame(200, $status);
        $ids = array_column($body['data'], 'id');
        self::assertContains(self::$fx->values['order'], $ids);
        self::assertNotContains(self::$fx->values['orderForeign'], $ids);
        foreach ($body['data'] as $order) {
            self::assertSame(self::$fx->values['owner'], $order['userId']);
            if ($order['id'] === self::$fx->values['order']) {
                self::assertSame('Rbac koszulka', $order['items'][0]['productName']);
            }
        }
    }

    public function testForeignBuyerSeesTheirOwnNotTheOther(): void
    {
        $ids = $this->listIds('participant');
        self::assertContains(self::$fx->values['orderForeign'], $ids);
        self::assertNotContains(self::$fx->values['order'], $ids);
    }

    public function testUserIdQueryIsIgnored(): void
    {
        $ids = $this->listIds('owner', '?userId=' . rawurlencode(self::$fx->values['participant']));
        self::assertContains(self::$fx->values['order'], $ids);
        self::assertNotContains(self::$fx->values['orderForeign'], $ids);
    }

    public function testStaffAndOrganizerSeeOnlyTheirOwn(): void
    {
        foreach (['organizer', 'moderator', 'admin', 'superadmin'] as $actor) {
            $ids = $this->listIds($actor);
            self::assertNotContains(self::$fx->values['order'], $ids, $actor);
            self::assertNotContains(self::$fx->values['orderForeign'], $ids, $actor);
        }
    }

    public function testProjectionHidesStaffFields(): void
    {
        $paths = ['/api/v1/me/orders', '/api/v1/me/orders/' . rawurlencode(self::$fx->values['order'])];
        foreach ($paths as $path) {
            [$status, $raw, $body] = $this->get($path, 'owner');
            self::assertSame(200, $status, $path);
            self::assertStringNotContainsString('RBAC-INTERNAL-NOTE', $raw);
            self::assertStringNotContainsString('RBAC-INTERNAL-HISTORY', $raw);
            $orders = isset($body['data']['id']) ? [$body['data']] : $body['data'];
            self::assertNotEmpty($orders);
            foreach ($orders as $order) {
                self::assertArrayNotHasKey('adminNotes', $order);
                self::assertArrayNotHasKey('history', $order);
                self::assertSame([], array_diff(array_keys($order), OwnOrderPresenter::OWN_KEYS));
                self::assertSame([], array_diff(array_keys($order['paymentMethod']), OwnOrderPresenter::PAYMENT_METHOD_KEYS));
                self::assertSame([], array_diff(array_keys($order['deliveryMethod']), OwnOrderPresenter::DELIVERY_METHOD_KEYS));
                foreach ($order['items'] as $item) {
                    self::assertSame([], array_diff(array_keys($item), OwnOrderPresenter::ITEM_KEYS));
                }
            }
        }
    }

    public function testSingleOwnOrder(): void
    {
        $id = self::$fx->values['order'];
        [$status, , $body] = $this->get('/api/v1/me/orders/' . rawurlencode($id), 'owner');
        self::assertSame(200, $status);
        self::assertSame($id, $body['data']['id']);
        self::assertNotEmpty($body['data']['items']);
        self::assertSame('TRK-order', $body['data']['trackingNumber']);
    }

    public function testForeignOrderIsIndistinguishableFromMissing(): void
    {
        foreach (['participant', 'organizer', 'admin'] as $actor) {
            [$foreignStatus, , $foreignBody] = $this->get('/api/v1/me/orders/' . rawurlencode(self::$fx->values['order']), $actor);
            [$missingStatus, , $missingBody] = $this->get('/api/v1/me/orders/rbac-does-not-exist', $actor);
            self::assertSame(404, $foreignStatus, $actor);
            self::assertSame(404, $missingStatus, $actor);
            self::assertSame($missingBody, $foreignBody, $actor);
        }
    }

    public function testAnonymousIs401(): void
    {
        [$listStatus] = $this->get('/api/v1/me/orders', null);
        [$oneStatus] = $this->get('/api/v1/me/orders/' . rawurlencode(self::$fx->values['order']), null);
        self::assertSame(401, $listStatus);
        self::assertSame(401, $oneStatus);
    }

    public function testStaffRouteStillHasAdminNotesAndStaysClosedToParticipants(): void
    {
        [$status, , $body] = $this->get('/api/v1/shop/orders?userId=' . rawurlencode(self::$fx->values['owner']), 'admin');
        self::assertSame(200, $status);
        self::assertNotEmpty($body['data']);
        self::assertSame('RBAC-INTERNAL-NOTE', $body['data'][0]['adminNotes']);

        [$ownerStatus] = $this->get('/api/v1/shop/orders', 'owner');
        self::assertSame(403, $ownerStatus);
    }
}

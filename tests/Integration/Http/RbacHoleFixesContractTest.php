<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Shop\ShopRepository;
use ZmierzymyCzas\Tests\Integration\Http\Rbac\RbacFixtures;
use ZmierzymyCzas\Tests\Support\HttpTestCase;
use ZmierzymyCzas\Tests\Support\SavepointPdo;

/**
 * PP.4: targeted positive/negative tests for the RBAC holes the route review found
 * (H1-H7, H9-H11). Allowed actors really succeed and state really stays unchanged on
 * denial. Everything runs inside a savepoint that is rolled back after each test.
 */
final class RbacHoleFixesContractTest extends HttpTestCase
{
    private static ?SavepointPdo $sharedPdo = null;
    private static RbacFixtures $fx;
    private static int $seq = 0;

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
        self::$sharedPdo->savepoint('hole_test');
    }

    protected function tearDown(): void
    {
        self::$sharedPdo->rollbackTo('hole_test');
    }

    /** @return array<string, string> */
    private function as(string $actor): array
    {
        $i = self::$fx->actors[$actor];

        return $this->bearer($i['id'], $i['role'], $i['email']);
    }

    /** @return array<string, mixed> */
    private function newEvent(string $organizer = 'organizer', string $status = 'active', array $overrides = []): array
    {
        $name = 'x' . (++self::$seq);

        return (new EventRepository(self::$sharedPdo))->create(
            self::$fx->eventPayload($name, self::$fx->values[$organizer], $status, false, $overrides)
        );
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $stmt = self::$sharedPdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    private function insertChallenge(): string
    {
        $id = 'rbac-chal-' . bin2hex(random_bytes(4));
        self::$sharedPdo->prepare(
            "INSERT INTO challenges (id, name, slug, description, min_completed, status, created_by)
             VALUES (:id, 'Rbac challenge', :slug, 'desc', 1, 'active', 'tester')"
        )->execute(['id' => $id, 'slug' => $id]);

        return $id;
    }

    private function insertEntry(string $challengeId, string $userId, string $status, string $proof): string
    {
        $id = 'rbac-entry-' . bin2hex(random_bytes(4));
        self::$sharedPdo->prepare(
            "INSERT INTO challenge_entries (id, challenge_id, user_id, event_id, status, proof_url, source)
             VALUES (:id, :c, :u, :e, :s, :p, 'manual')"
        )->execute(['id' => $id, 'c' => $challengeId, 'u' => $userId, 'e' => self::$fx->values['event'], 's' => $status, 'p' => $proof]);

        return $id;
    }

    public function testModeratorCannotCorrectAShopOrderButAdminCan(): void
    {
        $shop = new ShopRepository(self::$sharedPdo);
        $product = $shop->createProduct([
            'name' => 'Rbac product', 'slug' => self::$fx->prefix . '-product', 'description' => 'd', 'price' => 50,
            'category' => 'apparel', 'brand' => 'b', 'imageUrl' => 'https://example.test/p.jpg', 'inStock' => true, 'stockCount' => 10,
        ]);
        $created = $this->request('POST', '/api/v1/shop/orders', [
            'items' => [['productId' => $product['id'], 'productName' => $product['name'], 'quantity' => 1, 'price' => 50]],
            'deliveryMethod' => ['id' => 'courier', 'name' => 'Kurier', 'price' => 0],
            'paymentMethod' => ['id' => 'blik', 'name' => 'BLIK'],
            'shippingAddress' => ['fullName' => 'Jan Kowalski', 'street' => 'Testowa 1', 'city' => 'Warszawa', 'postalCode' => '00-001'],
        ], $this->as('owner'));
        self::assertSame(201, $created->getStatusCode());
        $orderId = (string) $this->json($created)['data']['id'];
        $before = (float) $this->scalar('SELECT price_adjustment FROM shop_orders WHERE id = :id', ['id' => $orderId]);

        $denied = $this->request('PATCH', '/api/v1/shop/orders/' . $orderId, ['priceAdjustment' => -50], $this->as('moderator'));
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame($before, (float) $this->scalar('SELECT price_adjustment FROM shop_orders WHERE id = :id', ['id' => $orderId]));

        $ok = $this->request('PATCH', '/api/v1/shop/orders/' . $orderId, ['priceAdjustment' => -50], $this->as('admin'));
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame(-50.0, (float) $this->scalar('SELECT price_adjustment FROM shop_orders WHERE id = :id', ['id' => $orderId]));
    }

    public function testOnlyAdminConfiguresChallenges(): void
    {
        $challengeId = $this->insertChallenge();
        $entryId = $this->insertEntry($challengeId, self::$fx->values['owner'], 'pending', 'https://example.test/p');

        self::assertSame(403, $this->request('POST', '/api/v1/challenges', ['name' => 'x'], $this->as('moderator'))->getStatusCode());
        self::assertSame(403, $this->request('PATCH', '/api/v1/challenges/' . $challengeId, ['name' => 'Renamed'], $this->as('moderator'))->getStatusCode());
        self::assertSame(403, $this->request('PUT', '/api/v1/challenges/' . $challengeId . '/condition-tree', ['tree' => []], $this->as('moderator'))->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/v1/challenges/' . $challengeId . '/archive', [], $this->as('moderator'))->getStatusCode());
        self::assertSame('Rbac challenge', $this->scalar('SELECT name FROM challenges WHERE id = :id', ['id' => $challengeId]));

        $renamed = $this->request('PATCH', '/api/v1/challenges/' . $challengeId, ['name' => 'Renamed'], $this->as('admin'));
        self::assertSame(200, $renamed->getStatusCode());
        $archived = $this->request('POST', '/api/v1/challenges/' . $challengeId . '/archive', [], $this->as('admin'));
        self::assertSame(200, $archived->getStatusCode());

        $review = $this->request('PATCH', '/api/v1/challenge-entries/' . $entryId . '/review', ['status' => 'approved'], $this->as('moderator'));
        self::assertSame(200, $review->getStatusCode());
    }

    public function testChallengeEntryIsAlwaysFiledForTheCaller(): void
    {
        $challengeId = $this->insertChallenge();
        $victimId = self::$fx->values['participant'];
        $callerId = self::$fx->values['owner'];
        $victimEntry = $this->insertEntry($challengeId, $victimId, 'approved', 'https://example.test/original');

        $response = $this->request('POST', '/api/v1/challenge-entries', [
            'challengeId' => $challengeId,
            'eventId' => self::$fx->values['event'],
            'userId' => $victimId,
            'proofUrl' => 'https://example.test/attacker',
        ], $this->as('owner'));
        self::assertSame(201, $response->getStatusCode());

        $row = self::$sharedPdo->prepare('SELECT status, proof_url FROM challenge_entries WHERE id = :id');
        $row->execute(['id' => $victimEntry]);
        self::assertSame(['status' => 'approved', 'proof_url' => 'https://example.test/original'], $row->fetch());
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM challenge_entries WHERE challenge_id = :c AND user_id = :u',
            ['c' => $challengeId, 'u' => $callerId]
        ));
    }

    public function testModeratorCannotDeleteAnotherOrganizersEvent(): void
    {
        $event = $this->newEvent();

        $denied = $this->request('DELETE', '/api/v1/events/' . $event['id'], [], $this->as('moderator'));
        self::assertSame(403, $denied->getStatusCode());
        self::assertNull($this->scalar('SELECT deleted_at FROM events WHERE id = :id', ['id' => $event['id']]));

        $ok = $this->request('DELETE', '/api/v1/events/' . $event['id'], [], $this->as('admin'));
        self::assertSame(200, $ok->getStatusCode());
        self::assertNotNull($this->scalar('SELECT deleted_at FROM events WHERE id = :id', ['id' => $event['id']]));
    }

    public function testModeratorEditNeverTouchesPaymentConfigOrOwnership(): void
    {
        $event = $this->newEvent();
        $id = (string) $event['id'];
        $configBefore = $this->scalar('SELECT payment_config FROM events WHERE id = :id', ['id' => $id]);

        $edit = $this->request('PATCH', '/api/v1/events/' . $id, [
            'location' => 'Nowe miejsce',
            'paymentConfig' => ['type' => 'organizer_bank', 'bankAccountNumber' => '00 0000'],
            'paymentMethods' => [['id' => 'rbac-pm', 'name' => 'Przelew', 'enabled' => true]],
        ], $this->as('moderator'));
        self::assertSame(200, $edit->getStatusCode());
        self::assertSame('Nowe miejsce', $this->scalar('SELECT location FROM events WHERE id = :id', ['id' => $id]));
        self::assertSame($configBefore, $this->scalar('SELECT payment_config FROM events WHERE id = :id', ['id' => $id]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM event_payment_methods WHERE event_id = :id', ['id' => $id]));

        $cancelled = $this->newEvent();
        self::$sharedPdo->prepare("UPDATE events SET publication_status = 'cancelled' WHERE id = :id")->execute(['id' => $cancelled['id']]);
        $transfer = $this->request('PATCH', '/api/v1/events/' . $cancelled['id'], ['organizerId' => self::$fx->values['foreignOrganizer']], $this->as('moderator'));
        self::assertSame(403, $transfer->getStatusCode());
        self::assertSame(self::$fx->values['organizer'], $this->scalar('SELECT organizer_id FROM events WHERE id = :id', ['id' => $cancelled['id']]));

        $own = $this->request('PATCH', '/api/v1/events/' . $id, ['paymentConfig' => ['type' => 'organizer_bank', 'bankAccountNumber' => '11 1111']], $this->as('organizer'));
        self::assertSame(200, $own->getStatusCode());
        self::assertStringContainsString('11 1111', (string) $this->scalar('SELECT payment_config FROM events WHERE id = :id', ['id' => $id]));
    }

    public function testMoneyOperationsAreOwnerOrAdminOnly(): void
    {
        $event = $this->newEvent('organizer', 'active', ['paymentConfig' => ['type' => 'organizer_bank', 'bankAccountHolder' => 'Rbac']]);
        $registrations = new RegistrationRepository(self::$sharedPdo);
        $payload = fn (array $extra = []): array => [
            'eventId' => $event['id'], 'eventName' => $event['name'],
            'userId' => self::$fx->values['owner'], 'userName' => 'Rbac owner', 'userEmail' => self::$fx->values['ownerEmail'],
            'distanceId' => $event['distances'][0]['id'], 'distanceName' => $event['distances'][0]['name'],
            'category' => 'Open', 'merchandiseOrders' => [],
        ] + $extra;

        $pending = $registrations->create($payload());
        foreach (['mark-paid' => 'POST'] as $action => $method) {
            self::assertSame(403, $this->request($method, "/api/v1/registrations/{$pending['id']}/{$action}", [], $this->as('moderator'))->getStatusCode(), $action);
        }
        self::assertSame(403, $this->request('PATCH', "/api/v1/registrations/{$pending['id']}/payment-details", ['startFeePaid' => true], $this->as('moderator'))->getStatusCode());
        self::assertNotSame('confirmed', $this->scalar('SELECT status FROM registrations WHERE id = :id', ['id' => $pending['id']]));

        $adminPaid = $this->request('PATCH', "/api/v1/registrations/{$pending['id']}/payment-details", ['startFeePaid' => true], $this->as('admin'));
        self::assertNotSame(403, $adminPaid->getStatusCode());
        $ownerMark = $this->request('POST', "/api/v1/registrations/{$pending['id']}/mark-paid", [], $this->as('organizer'));
        self::assertSame(200, $ownerMark->getStatusCode());

        $paid = $registrations->create($payload(['userId' => self::$fx->values['participant'], 'userEmail' => 'x@example.test', 'paymentDetails' => ['startFeePaid' => true]]));
        $registrations->requestRefund($paid['id'], self::$fx->values['participant']);
        foreach (['refund-approve', 'refund-complete'] as $action) {
            self::assertSame(403, $this->request('POST', "/api/v1/registrations/{$paid['id']}/{$action}", [], $this->as('moderator'))->getStatusCode(), $action);
        }
        self::assertSame(200, $this->request('POST', "/api/v1/registrations/{$paid['id']}/refund-approve", [], $this->as('organizer'))->getStatusCode());
        self::assertNotSame(403, $this->request('POST', "/api/v1/registrations/{$paid['id']}/refund-complete", [], $this->as('admin'))->getStatusCode());
    }

    public function testResultCannotBeMovedIntoAForeignEvent(): void
    {
        $resultId = self::$fx->values['result'];
        $foreign = $this->newEvent('foreignOrganizer');

        $denied = $this->request('PATCH', '/api/v1/results/' . $resultId, ['eventId' => $foreign['id'], 'distanceId' => $foreign['distances'][0]['id']], $this->as('organizer'));
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame(self::$fx->values['event'], $this->scalar('SELECT event_id FROM results WHERE id = :id', ['id' => $resultId]));

        $own = $this->newEvent('organizer');
        $moved = $this->request('PATCH', '/api/v1/results/' . $resultId, ['eventId' => $own['id'], 'distanceId' => $own['distances'][0]['id']], $this->as('organizer'));
        self::assertSame(200, $moved->getStatusCode());
        self::assertSame($own['id'], $this->scalar('SELECT event_id FROM results WHERE id = :id', ['id' => $resultId]));
    }

    public function testDuplicateLookupNeverExposesContactDataOrIdentityCode(): void
    {
        $competitors = new CompetitorRepository(self::$sharedPdo);
        $last = 'Dup' . bin2hex(random_bytes(3));
        $existing = $competitors->create([
            'firstName' => 'Zofia', 'lastName' => $last, 'birthDate' => '1991-02-03', 'gender' => 'K',
            'email' => 'zofia-' . $last . '@example.test', 'phone' => '600700800',
        ], true);
        self::assertNotEmpty($existing['identityCode']);
        $keys = ['id', 'firstName', 'lastName', 'birthDate'];

        $lookup = $this->request('GET', '/api/v1/competitors/duplicates?firstName=Zofia&lastName=' . $last . '&birthDate=1991-02-03', [], $this->as('participant'));
        self::assertSame(200, $lookup->getStatusCode());
        $rows = $this->json($lookup)['data'];
        self::assertCount(1, $rows);
        self::assertSame($keys, array_keys($rows[0]));
        self::assertSame($existing['id'], $rows[0]['id']);

        $care = $this->request('POST', '/api/v1/care', ['firstName' => 'Zofia', 'lastName' => $last, 'birthDate' => '1991-02-03', 'gender' => 'K'], $this->as('owner'));
        self::assertSame(201, $care->getStatusCode());
        $warnings = $this->json($care)['data']['duplicateWarnings'];
        self::assertNotEmpty($warnings);
        foreach ($warnings as $row) {
            self::assertSame($keys, array_keys($row));
        }
    }

    public function testModeratorCannotReadOnlineSessions(): void
    {
        self::assertSame(403, $this->request('GET', '/api/v1/admin/online-sessions', [], $this->as('moderator'))->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/v1/admin/online-sessions', [], $this->as('admin'))->getStatusCode());
    }

    public function testCannotRegisterIntoAnEventTheCallerCannotSee(): void
    {
        $draft = $this->newEvent('organizer', 'draft');
        $body = fn (array $event): array => ['eventId' => $event['id'], 'distanceId' => $event['distances'][0]['id'], 'category' => 'Open'];
        $count = fn (string $eventId): int => (int) $this->scalar('SELECT COUNT(*) FROM registrations WHERE event_id = :id', ['id' => $eventId]);

        $denied = $this->request('POST', '/api/v1/registrations', $body($draft), $this->as('participant'));
        self::assertSame(404, $denied->getStatusCode());
        self::assertSame('not_found', $this->json($denied)['error']['code']);
        self::assertSame(0, $count((string) $draft['id']));

        $deleted = $this->newEvent();
        self::$sharedPdo->prepare('UPDATE events SET deleted_at = NOW() WHERE id = :id')->execute(['id' => $deleted['id']]);
        self::assertSame(404, $this->request('POST', '/api/v1/registrations', $body($deleted), $this->as('participant'))->getStatusCode());

        self::assertNotSame(404, $this->request('POST', '/api/v1/registrations', $body($draft), $this->as('organizer'))->getStatusCode());

        // Documented gap: the private access code is not enforced on submit (the client does not forward it yet).
        $private = $this->newEvent('organizer', 'active', ['isPrivate' => true, 'accessCode' => 'rbac-code']);
        self::assertNotSame(404, $this->request('POST', '/api/v1/registrations', $body($private), $this->as('participant'))->getStatusCode());
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/** R-02 (zakres P2): change of Dystans - window, capacity, counters, Rozliczenie, balance, history. */
final class RegistrationDistanceChangeContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private string $prefix;
    private array $participant;
    private array $stranger;
    private array $organizer;
    private array $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo);
        $this->prefix = 'http-distchg-' . bin2hex(random_bytes(4));

        $make = fn (string $label, string $role): array => $this->users->create([
            'name' => 'DC ' . $label,
            'email' => $this->prefix . '-' . $label . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ]);
        $this->participant = $make('participant', 'participant');
        $this->stranger = $make('stranger', 'participant');
        $this->organizer = $make('organizer', 'organizer');
        $distance = fn (string $key, string $name, int $price, int $limit): array => [
            'id' => $this->prefix . '-' . $key,
            'name' => $name,
            'length' => 10,
            'unit' => 'km',
            'categories' => ['Open'],
            'ageCategories' => [],
            'specialCategories' => [],
            'limit' => $limit,
            'registeredCount' => 0,
            'price' => $price,
            'routeFiles' => [],
        ];
        $this->event = $this->events->create([
            'name' => 'PHPUnit DistanceChange Event',
            'slug' => $this->prefix . '-event',
            'discipline' => 'running',
            'date' => (new \DateTimeImmutable('+90 days'))->format('Y-m-d'),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'DC Organizer',
            'status' => 'active',
            'registrationDeadline' => (new \DateTimeImmutable('+60 days'))->format('Y-m-d'),
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [
                $distance('10k', '10 km', 80, 100),
                $distance('5k', '5 km', 50, 100),
                $distance('21k', 'Półmaraton', 120, 100),
                $distance('full', 'Pełny', 60, 1),
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
        $stmt->execute(['id' => $this->event['id']]);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $regId) {
            foreach (['registration_merchandise_orders', 'registration_payment_details', 'registration_settlement_items', 'registration_refunds', 'registration_changes'] as $table) {
                $this->pdo->prepare("DELETE FROM {$table} WHERE registration_id = :id")->execute(['id' => $regId]);
            }
        }
        $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM event_discount_codes WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    private function did(string $key): string
    {
        return $this->prefix . '-' . $key;
    }

    /** @return array<string, mixed> */
    private function register(string $key, bool $paid, ?string $discountCode = null): array
    {
        $distance = array_values(array_filter($this->event['distances'], fn (array $d): bool => $d['id'] === $this->did($key)))[0];

        return $this->registrations->create([
            'eventId' => $this->event['id'],
            'eventName' => $this->event['name'],
            'userId' => $this->participant['id'],
            'userName' => $this->participant['name'],
            'userEmail' => $this->participant['email'],
            'distanceId' => $distance['id'],
            'distanceName' => $distance['name'],
            'category' => 'Open',
            'merchandiseOrders' => [],
            ...($discountCode !== null ? ['discountCode' => $discountCode] : []),
            ...($paid ? ['paymentDetails' => ['startFeePaid' => true]] : []),
        ]);
    }

    /** @return array<string, string> */
    private function ownerAuth(): array
    {
        return $this->bearer($this->participant['id'], 'participant', $this->participant['email']);
    }

    /** @return array<string, string> */
    private function managerAuth(): array
    {
        return $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']);
    }

    private function seats(string $key): int
    {
        $stmt = $this->pdo->prepare('SELECT registered_count FROM event_distances WHERE id = :id');
        $stmt->execute(['id' => $this->did($key)]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function change(string $id, string $key, array $auth, array $extra = [], string $path = '/distance-change'): array
    {
        $response = $this->request('POST', '/api/v1/registrations/' . $id . $path, ['distanceId' => $this->did($key), ...$extra], $auth);

        return ['status' => $response->getStatusCode(), 'json' => $this->json($response)];
    }

    public function testOwnerMovesPaidRegistrationToCheaperDistanceAndGetsRefundRequest(): void
    {
        $reg = $this->register('10k', true);
        $this->pdo->prepare('UPDATE registrations SET bib_number = :b WHERE id = :id')->execute(['b' => '17', 'id' => $reg['id']]);

        $result = $this->change($reg['id'], '5k', $this->ownerAuth());

        self::assertSame(200, $result['status']);
        $data = $result['json']['data'];
        self::assertSame($this->did('5k'), $data['distanceId']);
        self::assertSame('5 km', $data['distanceName']);
        self::assertSame(50.0, (float) $data['totalAmount']);
        self::assertSame(0, $data['balanceDueMinor']);
        self::assertSame(3000, $data['refundDueMinor']);
        self::assertTrue($data['refundRequested']);
        self::assertSame('requested', $data['refund']['status']);
        self::assertSame('distance_change', $data['refund']['reason']);
        self::assertSame(30.0, (float) $data['refund']['amount']);
        self::assertNull($data['bibNumber']);
        self::assertSame($reg['qrCode'], $data['qrCode']);
        self::assertSame('confirmed', $data['status']);
        self::assertSame(0, $this->seats('10k'));
        self::assertSame(1, $this->seats('5k'));

        $history = $this->json($this->request('GET', '/api/v1/registrations/' . $reg['id'] . '/changes', [], $this->ownerAuth()))['data'];
        self::assertCount(1, $history);
        self::assertSame('distance_changed', $history[0]['type']);
        self::assertSame('10 km', $history[0]['from']['distanceName']);
        self::assertSame('5 km', $history[0]['to']['distanceName']);
        self::assertSame('owner', $history[0]['actorRole']);
        $logged = $this->pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE action = 'registration_distance_changed' AND target_id = :id");
        $logged->execute(['id' => $reg['id']]);
        self::assertSame(1, (int) $logged->fetchColumn());
    }

    public function testRefundForTheOverpaymentIsApprovedWithoutCancellingTheRegistration(): void
    {
        $reg = $this->register('10k', true);
        $this->change($reg['id'], '5k', $this->ownerAuth());

        $approve = $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/refund-approve', [], $this->managerAuth());
        self::assertSame(200, $approve->getStatusCode());
        self::assertSame(30.0, (float) $this->json($approve)['data']['refund']['amount']);
        $done = $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/refund-complete', [], $this->managerAuth());
        self::assertSame(200, $done->getStatusCode());
        self::assertSame('confirmed', $this->json($done)['data']['status']);
        self::assertSame(1, $this->seats('5k'));
    }

    public function testOwnerIsRefusedForADearerDistanceWhenPaid(): void
    {
        $reg = $this->register('10k', true);

        $result = $this->change($reg['id'], '21k', $this->ownerAuth());

        self::assertSame(409, $result['status']);
        self::assertSame('balance_due_requires_organizer', $result['json']['error']['code']);
        self::assertSame(4000, $result['json']['error']['details']['balanceDueMinor']);
        self::assertSame($this->did('10k'), $this->registrations->find($reg['id'])['distanceId']);
        self::assertSame(1, $this->seats('10k'));
        self::assertSame(0, $this->seats('21k'));
    }

    public function testManagerMovesPaidRegistrationToDearerDistanceAndSeesTheBalanceDue(): void
    {
        $reg = $this->register('10k', true);

        $result = $this->change($reg['id'], '21k', $this->managerAuth());

        self::assertSame(200, $result['status']);
        $data = $result['json']['data'];
        self::assertSame($this->did('21k'), $data['distanceId']);
        self::assertSame(120.0, (float) $data['totalAmount']);
        self::assertSame(4000, $data['balanceDueMinor']);
        self::assertSame(0, $data['refundDueMinor']);
        self::assertFalse($data['refundRequested']);
        self::assertNull($data['refund']);
        self::assertSame(0, $this->seats('10k'));
        self::assertSame(1, $this->seats('21k'));
        $history = $this->json($this->request('GET', '/api/v1/registrations/' . $reg['id'] . '/changes', [], $this->managerAuth()))['data'];
        self::assertSame('manager', $history[0]['actorRole']);
        self::assertSame(4000, $history[0]['to']['balanceDueMinor']);
    }

    public function testBalanceLeftOpenByAManagerChangeIsNotRefundedBack(): void
    {
        $reg = $this->register('5k', true);
        $this->change($reg['id'], '21k', $this->managerAuth());
        sleep(1);

        // Paid so far is 50 (the 70 extra was never collected through the platform): back to 50 owes and owes back nothing.
        $result = $this->change($reg['id'], '5k', $this->ownerAuth());

        self::assertSame(200, $result['status']);
        self::assertSame(0, $result['json']['data']['refundDueMinor']);
        self::assertFalse($result['json']['data']['refundRequested']);
        self::assertSame(0, $result['json']['data']['balanceDueMinor']);
    }

    public function testUnpaidRegistrationHasNoBalanceSoOwnerMayMoveToDearerDistance(): void
    {
        $reg = $this->register('5k', false);

        $result = $this->change($reg['id'], '21k', $this->ownerAuth());

        self::assertSame(200, $result['status']);
        $data = $result['json']['data'];
        self::assertSame('pending', $data['status']);
        self::assertSame(120.0, (float) $data['totalAmount']);
        self::assertSame(0, $data['balanceDueMinor']);
        self::assertSame(0, $data['refundDueMinor']);
    }

    public function testQuoteShowsTheNumbersAndChangesNothing(): void
    {
        $reg = $this->register('10k', true);

        $cheaper = $this->change($reg['id'], '5k', $this->ownerAuth(), [], '/distance-change/quote');
        $dearer = $this->change($reg['id'], '21k', $this->ownerAuth(), [], '/distance-change/quote');

        self::assertSame(200, $cheaper['status']);
        $quote = $cheaper['json']['data'];
        self::assertSame(80.0, (float) $quote['currentTotal']);
        self::assertSame(50.0, (float) $quote['newTotal']);
        self::assertSame(3000, $quote['refundDueMinor']);
        self::assertNull($quote['blockedReason']);
        self::assertSame('5 km', $quote['to']['distanceName']);
        self::assertSame(50.0, (float) $quote['settlementItems'][0]['amount']);
        self::assertSame(200, $dearer['status']);
        self::assertSame(4000, $dearer['json']['data']['balanceDueMinor']);
        self::assertSame('balance_due_requires_organizer', $dearer['json']['data']['blockedReason']);

        $after = $this->registrations->find($reg['id']);
        self::assertSame($this->did('10k'), $after['distanceId']);
        self::assertNull($after['refund']);
        self::assertSame(1, $this->seats('10k'));
        self::assertSame(0, $this->seats('5k'));
        self::assertSame([], $this->json($this->request('GET', '/api/v1/registrations/' . $reg['id'] . '/changes', [], $this->ownerAuth()))['data']);
    }

    public function testFullTargetDistanceIsRefusedAndNothingMoves(): void
    {
        $other = $this->registrations->create([
            'eventId' => $this->event['id'],
            'eventName' => $this->event['name'],
            'userId' => $this->stranger['id'],
            'userName' => $this->stranger['name'],
            'userEmail' => $this->stranger['email'],
            'distanceId' => $this->did('full'),
            'distanceName' => 'Pełny',
            'category' => 'Open',
            'merchandiseOrders' => [],
        ]);
        $reg = $this->register('10k', false);

        $result = $this->change($reg['id'], 'full', $this->ownerAuth());
        $quote = $this->change($reg['id'], 'full', $this->ownerAuth(), [], '/distance-change/quote');

        self::assertSame(409, $result['status']);
        self::assertSame('registration_capacity_exceeded', $result['json']['error']['code']);
        self::assertSame(409, $quote['status']);
        self::assertSame(1, $this->seats('10k'));
        self::assertSame(1, $this->seats('full'));
        self::assertNotEmpty($other['id']);
    }

    public function testOwnerIsRefusedAfterTheCutOffButManagerStillChanges(): void
    {
        $reg = $this->register('10k', false);
        $this->events->update((string) $this->event['id'], ['date' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d')]);

        $owner = $this->change($reg['id'], '5k', $this->ownerAuth());
        $manager = $this->change($reg['id'], '5k', $this->managerAuth());

        self::assertSame(409, $owner['status']);
        self::assertSame('self_service_closed', $owner['json']['error']['code']);
        self::assertSame(200, $manager['status']);
        self::assertSame($this->did('5k'), $manager['json']['data']['distanceId']);
    }

    public function testOwnerIsRefusedWhenSelfServiceIsSwitchedOff(): void
    {
        $reg = $this->register('10k', false);
        $this->events->update((string) $this->event['id'], ['selfServiceEnabled' => false]);

        $result = $this->change($reg['id'], '5k', $this->ownerAuth());

        self::assertSame(409, $result['status']);
        self::assertSame('self_service_disabled', $result['json']['error']['code']);
    }

    public function testDiscountCodeIsKeptWhenValidForTargetAndReleasedWhenNot(): void
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO event_discount_codes (id, event_id, code, discount_type, discount_value, max_uses, distance_ids) VALUES (:id, :event, :code, 'fixed', 10, :max, :distances)"
        );
        $insert->execute(['id' => 'dc-' . bin2hex(random_bytes(6)), 'event' => $this->event['id'], 'code' => 'BOTH10', 'max' => 1, 'distances' => json_encode([$this->did('10k'), $this->did('5k')])]);
        $insert->execute(['id' => 'dc-' . bin2hex(random_bytes(6)), 'event' => $this->event['id'], 'code' => 'ONLY10K', 'max' => 1, 'distances' => json_encode([$this->did('10k')])]);

        $kept = $this->register('10k', true, 'BOTH10');
        $dropped = $this->register('10k', true, 'ONLY10K');
        self::assertSame(70.0, (float) $kept['totalAmount']);

        $keptResult = $this->change($kept['id'], '5k', $this->managerAuth());
        $droppedResult = $this->change($dropped['id'], '5k', $this->managerAuth());

        self::assertSame(200, $keptResult['status']);
        self::assertSame(40.0, (float) $keptResult['json']['data']['totalAmount']);
        self::assertSame('BOTH10', $keptResult['json']['data']['discountCode']);
        self::assertSame(200, $droppedResult['status']);
        self::assertSame(50.0, (float) $droppedResult['json']['data']['totalAmount']);
        self::assertNull($droppedResult['json']['data']['discountCode']);
        self::assertTrue($droppedResult['json']['data']['change']['discountDropped']);
        $uses = $this->pdo->prepare('SELECT code, used_count FROM event_discount_codes WHERE event_id = :id');
        $uses->execute(['id' => $this->event['id']]);
        $byCode = array_column($uses->fetchAll(), 'used_count', 'code');
        self::assertSame(1, (int) $byCode['BOTH10']);
        self::assertSame(0, (int) $byCode['ONLY10K']);
    }

    public function testOtherSettlementLinesAreKeptFrozen(): void
    {
        $reg = $this->register('10k', true);
        $this->pdo->prepare("INSERT INTO registration_settlement_items (id, registration_id, name, basis, amount, frozen_at, sort_order) VALUES (:id, :reg, 'Koszulka', 'merchandise', 25, :at, 9)")
            ->execute(['id' => 'si-' . bin2hex(random_bytes(6)), 'reg' => $reg['id'], 'at' => date(DATE_ATOM)]);

        $result = $this->change($reg['id'], '5k', $this->managerAuth());

        self::assertSame(200, $result['status']);
        $names = array_column($result['json']['data']['settlementItems'], 'name');
        self::assertContains('Koszulka', $names);
        // 105 paid (80 + 25), now 50 + 25 = 75 -> 30 back.
        self::assertSame(75.0, (float) $result['json']['data']['totalAmount']);
        self::assertSame(3000, $result['json']['data']['refundDueMinor']);
    }

    public function testValidationAndAccessErrors(): void
    {
        $reg = $this->register('10k', false);
        $path = '/api/v1/registrations/' . $reg['id'] . '/distance-change';

        $stranger = $this->request('POST', $path, ['distanceId' => $this->did('5k')], $this->bearer($this->stranger['id'], 'participant', $this->stranger['email']));
        $anonymous = $this->request('POST', $path, ['distanceId' => $this->did('5k')]);
        $missing = $this->request('POST', $path, [], $this->ownerAuth());
        $same = $this->request('POST', $path, ['distanceId' => $this->did('10k')], $this->ownerAuth());
        $foreign = $this->request('POST', $path, ['distanceId' => 'no-such-distance'], $this->ownerAuth());
        $variant = $this->request('POST', $path, ['distanceId' => $this->did('5k'), 'pricingVariantId' => 'no-such-variant'], $this->ownerAuth());
        $unknown = $this->request('POST', '/api/v1/registrations/00000000-0000-4000-8000-000000000000/distance-change', ['distanceId' => $this->did('5k')], $this->managerAuth());

        self::assertSame(403, $stranger->getStatusCode());
        self::assertSame(401, $anonymous->getStatusCode());
        self::assertSame(422, $missing->getStatusCode());
        self::assertSame(422, $same->getStatusCode());
        self::assertSame('distance_change_no_change', $this->json($same)['error']['code']);
        self::assertSame(422, $foreign->getStatusCode());
        self::assertSame(422, $variant->getStatusCode());
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame(1, $this->seats('10k'));
    }

    public function testCancelledRegistrationCannotBeMoved(): void
    {
        $reg = $this->register('10k', false);
        $this->registrations->cancel($reg['id']);

        $result = $this->change($reg['id'], '5k', $this->managerAuth());

        self::assertSame(409, $result['status']);
        self::assertSame('registration_not_active', $result['json']['error']['code']);
    }
}

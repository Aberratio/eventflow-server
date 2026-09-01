<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Payments\PaymentGatewayInterface;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;

/**
 * E1.7: RegistrationRepository now takes two independent gateways (Tpay and
 * PayNow, both just PaymentGatewayInterface) and picks between them per
 * Wydarzenie via `payment_config.provider` — this is the selection logic
 * itself (business-logic-agnostic to which concrete gateway either fake
 * stands in for). The per-gateway request/response shapes are covered
 * separately (tests/Unit/Payments/PayNow*Test.php,
 * tests/Integration/RegistrationRepositoryTpayTest.php already covers the
 * single-gateway create/retry/notify/refund flow end to end against a fake
 * PaymentGatewayInterface).
 */
final class RegistrationRepositoryGatewayProviderTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private FakeProviderGateway $tpayGateway;
    private FakeProviderGateway $payNowGateway;
    private string $slugPrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->slugPrefix = 'phpgwsel-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->deletePhpUnitEvents();
            $this->events = new EventRepository($this->pdo);
            $this->tpayGateway = new FakeProviderGateway('TX-tpay');
            $this->payNowGateway = new FakeProviderGateway('TX-paynow');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->deletePhpUnitEvents();
    }

    public function testUnsetProviderDefaultsToTheTpayGatewayForBackwardCompatibility(): void
    {
        $registrations = new RegistrationRepository($this->pdo, $this->tpayGateway, $this->payNowGateway);
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-default']));

        $registration = $registrations->create($this->registrationPayload($event));

        self::assertCount(1, $this->tpayGateway->startSessionCalls);
        self::assertCount(0, $this->payNowGateway->startSessionCalls);
        self::assertSame('TX-tpay', $registration['paymentSession']['transactionId']);
        self::assertSame('tpay', $this->rawGatewayTransaction('TX-tpay')['gateway']);
    }

    public function testExplicitTpayProviderUsesTheTpayGateway(): void
    {
        $registrations = new RegistrationRepository($this->pdo, $this->tpayGateway, $this->payNowGateway);
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-tpay',
            'paymentConfig' => ['type' => 'admin_gateway', 'provider' => 'tpay'],
        ]));

        $registrations->create($this->registrationPayload($event));

        self::assertCount(1, $this->tpayGateway->startSessionCalls);
        self::assertCount(0, $this->payNowGateway->startSessionCalls);
    }

    public function testPayNowProviderUsesThePayNowGatewayInstead(): void
    {
        $registrations = new RegistrationRepository($this->pdo, $this->tpayGateway, $this->payNowGateway);
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-paynow',
            'paymentConfig' => ['type' => 'admin_gateway', 'provider' => 'paynow'],
        ]));

        $registration = $registrations->create($this->registrationPayload($event));

        self::assertCount(0, $this->tpayGateway->startSessionCalls);
        self::assertCount(1, $this->payNowGateway->startSessionCalls);
        self::assertSame('TX-paynow', $registration['paymentSession']['transactionId']);
        self::assertSame('paynow', $this->rawGatewayTransaction('TX-paynow')['gateway']);
    }

    public function testPayNowProviderWithNoPayNowGatewayConfiguredSkipsSessionStartWithoutError(): void
    {
        $registrations = new RegistrationRepository($this->pdo, $this->tpayGateway, null);
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-paynow-nogw',
            'paymentConfig' => ['type' => 'admin_gateway', 'provider' => 'paynow'],
        ]));

        $registration = $registrations->create($this->registrationPayload($event));

        self::assertSame('pending', $registration['status']);
        self::assertNull($registration['paymentSession']);
        self::assertCount(0, $this->tpayGateway->startSessionCalls);
    }

    /**
     * The crux of the ticket's design: a refund must call the gateway that
     * actually took the payment — read off the frozen transaction row — even
     * if the organizer reconfigures the Wydarzenie to the other provider in
     * between (a hypothetical, but the transaction row is what must decide,
     * not the event's current config).
     */
    public function testRefundUsesTheGatewayThatActuallyProcessedThePaymentEvenIfTheEventWasReconfiguredSince(): void
    {
        $registrations = new RegistrationRepository($this->pdo, $this->tpayGateway, $this->payNowGateway);
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-refund-switch',
            'paymentConfig' => ['type' => 'admin_gateway', 'provider' => 'paynow'],
        ]));
        $registration = $registrations->create($this->registrationPayload($event));
        $registrations->applyGatewayNotification('TX-paynow', true, 80.0);

        // Organizer switches the event to Tpay after the fact.
        $this->pdo->prepare('UPDATE events SET payment_config = :cfg WHERE id = :id')->execute([
            'cfg' => json_encode(['type' => 'admin_gateway', 'provider' => 'tpay']),
            'id' => $event['id'],
        ]);

        $registrations->requestRefund($registration['id'], 'phpunit-participant');
        $registrations->approveRefund($registration['id'], 'phpunit-organizer', 30.0);

        self::assertCount(1, $this->payNowGateway->refundCalls, 'the refund must go to PayNow — it actually took the money');
        self::assertCount(0, $this->tpayGateway->refundCalls);
        self::assertSame('TX-paynow', $this->payNowGateway->refundCalls[0]['transactionId']);
        self::assertSame(30.0, $this->payNowGateway->refundCalls[0]['amount']);
    }

    /** @return array<string, mixed> */
    private function rawGatewayTransaction(string $transactionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT transaction_id AS transactionId, registration_id AS registrationId, status, amount, gateway
             FROM registration_gateway_transactions WHERE transaction_id = :id'
        );
        $stmt->execute(['id' => $transactionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? $this->slugPrefix);
        return array_replace_recursive([
            'name' => 'PHPUnit Gateway Provider Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-06-15',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'draft',
            'registrationDeadline' => '2026-06-01',
            'featured' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [
                [
                    'id' => $slug . '-10k',
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
                ],
            ],
        ], $overrides);
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    private function registrationPayload(array $event, string $userId = 'phpunit-participant'): array
    {
        return [
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'userId' => $userId,
            'userName' => 'PHPUnit User',
            'userEmail' => $userId . '@example.test',
            'distanceId' => $event['distances'][0]['id'],
            'distanceName' => $event['distances'][0]['name'],
            'category' => 'Open',
            'merchandiseOrders' => [],
        ];
    }

    private function deletePhpUnitEvents(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => 'phpgwsel-%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $eventId) {
            $regStmt = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :eventId');
            $regStmt->execute(['eventId' => $eventId]);
            foreach ($regStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $registrationId) {
                $this->pdo->prepare('DELETE FROM registration_gateway_transactions WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_refunds WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_payment_details WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_settlement_items WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_merchandise_orders WHERE registration_id = :id')->execute(['id' => $registrationId]);
            }
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :eventId')->execute(['eventId' => $eventId]);
        }
    }
}

/** Test double for PaymentGatewayInterface, one instance standing in for each provider. */
final class FakeProviderGateway implements PaymentGatewayInterface
{
    /** @var array<int, array{registrationId: string, amount: float}> */
    public array $startSessionCalls = [];

    /** @var array<int, array{transactionId: string, amount: float}> */
    public array $refundCalls = [];

    public function __construct(private readonly string $transactionId)
    {
    }

    public function startSession(string $registrationId, float $amount, string $description, string $payerEmail, string $payerName): array
    {
        $this->startSessionCalls[] = ['registrationId' => $registrationId, 'amount' => $amount];
        return ['transactionId' => $this->transactionId, 'redirectUrl' => 'https://pay.example.test/' . $this->transactionId];
    }

    public function refund(string $transactionId, float $amount): void
    {
        $this->refundCalls[] = ['transactionId' => $transactionId, 'amount' => $amount];
    }
}

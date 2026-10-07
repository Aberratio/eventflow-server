<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use ZmierzymyCzas\Config\AppConfig;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Auth\JwtService;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Payments\PaymentGatewayInterface;
use ZmierzymyCzas\Domain\Payments\PayNow\PayNowSignature;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Http\HttpMiddlewareStack;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Support\Uuid;

/**
 * PP.63: a signed PayNow notification whose `externalId` disagrees with the registration id stored
 * on the gateway transaction row must not mark anything paid, but is still acked (200, empty body).
 * Built like ObservabilityContractTest: real middleware stack + real routes.php. A DB that is not
 * reachable is a hard failure here, never a skip.
 */
final class PayNowWebhookIdentityContractTest extends TestCase
{
    private const PAYNOW_KEY = 'phpunit-pp63-signature-key';

    private PDO $pdo;
    private TestHandler $logs;
    private Logger $logger;
    private EventRepository $events;
    private string $slugPrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        Env::set('PAYNOW_SIGNATURE_KEY', self::PAYNOW_KEY);
        $this->slugPrefix = 'pp63-' . bin2hex(random_bytes(4));
        $this->logs = new TestHandler();
        $this->logger = new Logger('test', [$this->logs]);

        try {
            $this->pdo = Connection::fromEnv();
        } catch (\Throwable $e) {
            self::fail('Database is not available for PayNowWebhookIdentityContractTest: ' . $e->getMessage());
        }
        $this->deleteFixtures();
        $this->events = new EventRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        Env::restore('PAYNOW_SIGNATURE_KEY');
        if (isset($this->pdo)) {
            $this->deleteFixtures();
        }
    }

    public function testSwappedExternalIdIsAckedButNotPaid(): void
    {
        $registration = $this->registrationWithPayNowTransaction('PN-pp63-swap');
        $foreign = Uuid::v4();

        $response = $this->notify('PN-pp63-swap', $foreign);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame('pending', $this->registrationStatus($registration['id']));
        self::assertSame('amount_mismatch', $this->transactionStatus('PN-pp63-swap'));

        $webhookRecords = array_values(array_filter(
            $this->logs->getRecords(),
            static fn ($r): bool => ($r->context['outcome'] ?? null) === 'registration_mismatch'
        ));
        self::assertCount(1, $webhookRecords);
        self::assertSame(Level::Info, $webhookRecords[0]->level);
        self::assertSame('paynow', $webhookRecords[0]->context['gateway']);
        self::assertSame($registration['id'], $webhookRecords[0]->context['registrationId']);
        $all = '';
        foreach ($this->logs->getRecords() as $record) {
            $all .= $record->message . json_encode($record->context);
        }
        self::assertStringNotContainsString($foreign, $all);
        self::assertCount(1, array_filter(
            $this->logs->getRecords(),
            static fn ($r): bool => ($r->context['alert'] ?? null) === 'payments.registration_mismatch'
        ));
    }

    public function testCorrectExternalIdConfirms(): void
    {
        $registration = $this->registrationWithPayNowTransaction('PN-pp63-ok');

        $response = $this->notify('PN-pp63-ok', $registration['id']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame('confirmed', $this->registrationStatus($registration['id']));
        self::assertSame('paid', $this->transactionStatus('PN-pp63-ok'));
    }

    public function testNonUuidExternalIdIsNotBlockedAndWarns(): void
    {
        $registration = $this->registrationWithPayNowTransaction('PN-pp63-fmt');

        $response = $this->notify('PN-pp63-fmt', 'legacy-12345');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('confirmed', $this->registrationStatus($registration['id']));
        self::assertCount(1, array_filter(
            $this->logs->getRecords(),
            static fn ($r): bool => ($r->context['check'] ?? null) === 'payments.registration_id_unverifiable'
        ));
    }

    /** @return array<string, mixed> */
    private function registrationWithPayNowTransaction(string $transactionId): array
    {
        $gateway = new class ($transactionId) implements PaymentGatewayInterface {
            public function __construct(private readonly string $transactionId)
            {
            }

            public function startSession(string $registrationId, float $amount, string $description, string $payerEmail, string $payerName): array
            {
                return ['transactionId' => $this->transactionId, 'redirectUrl' => 'https://pay.example.test/' . $this->transactionId];
            }

            public function refund(string $transactionId, float $amount): void
            {
            }
        };
        $registrations = new RegistrationRepository($this->pdo, null, $gateway);
        $slug = $this->slugPrefix . '-' . strtolower($transactionId);
        $event = $this->events->create([
            'name' => 'PHPUnit PayNow Identity Event',
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
            'paymentConfig' => ['type' => 'admin_gateway', 'provider' => 'paynow'],
            'distances' => [[
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
            ]],
        ]);
        $registration = $registrations->create([
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'userId' => 'phpunit-participant',
            'userName' => 'PHPUnit User',
            'userEmail' => 'phpunit-participant@example.test',
            'distanceId' => $event['distances'][0]['id'],
            'distanceName' => $event['distances'][0]['name'],
            'category' => 'Open',
            'merchandiseOrders' => [],
        ]);
        self::assertSame('pending', $registration['status']);
        self::assertSame('created', $this->transactionStatus($transactionId));

        return $registration;
    }

    private function notify(string $transactionId, string $externalId): ResponseInterface
    {
        $body = (string) json_encode([
            'paymentId' => $transactionId,
            'externalId' => $externalId,
            'status' => 'CONFIRMED',
            'modifiedAt' => '2026-10-03T10:00:00',
        ]);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/webhooks/paynow')
            ->withBody((new StreamFactory())->createStream($body))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Signature', PayNowSignature::compute($body, self::PAYNOW_KEY));

        return $this->buildApp()->handle($request);
    }

    private function buildApp(): App
    {
        $config = AppConfig::fromEnvironment([
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'false',
            'APP_CORS_ORIGIN' => 'http://localhost:5173',
            'JWT_SECRET' => str_repeat('a', 32),
            'JWT_TTL_SECONDS' => '86400',
            'TPAY_SANDBOX' => 'true',
            'PAYNOW_SANDBOX' => 'true',
        ]);
        $app = AppFactory::create();
        $app->setBasePath('');
        HttpMiddlewareStack::register($app, $config, $this->logger);
        $jwt = new JwtService('phpunit-http-secret-at-least-32-bytes', 3600);

        (require __DIR__ . '/../../../src/Http/routes.php')($app, $this->pdo, $jwt, $this->logger);

        return $app;
    }

    private function registrationStatus(string $registrationId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $registrationId]);

        return (string) $stmt->fetchColumn();
    }

    private function transactionStatus(string $transactionId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM registration_gateway_transactions WHERE transaction_id = :id');
        $stmt->execute(['id' => $transactionId]);

        return (string) $stmt->fetchColumn();
    }

    private function deleteFixtures(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => 'pp63-%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $eventId) {
            $regStmt = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :eventId');
            $regStmt->execute(['eventId' => $eventId]);
            foreach ($regStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $registrationId) {
                foreach (['registration_gateway_transactions', 'registration_refunds', 'registration_payment_details', 'registration_settlement_items', 'registration_merchandise_orders'] as $table) {
                    $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE registration_id = :id')->execute(['id' => $registrationId]);
                }
                $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE source_entity_id = :id')->execute(['id' => $registrationId]);
            }
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :eventId')->execute(['eventId' => $eventId]);
        }
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerClient;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxService;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\CriticalMailer;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailCatalog;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailTemplateRepository;
use ZmierzymyCzas\Domain\Mailer\Http\HttpResponse;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\FakeCentralMailerHttpClient;

/**
 * E1.10 trigger wiring inside RegistrationRepository: mail #4 on create(), #5
 * on the pending -> confirmed transition (both trasa A/B routes fold through
 * markGatewayPaymentPaid(), see its doc comment), and #14 on approveRefund().
 * Uses a real outbox + mail_templates table with a fake Central Mailer HTTP
 * client — no real network call is ever made.
 */
final class RegistrationRepositoryMailTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private FakeCentralMailerHttpClient $http;
    private CentralMailerOutboxRepository $outboxRepo;
    private string $slugPrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->slugPrefix = 'phpregmail-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->deletePhpUnitEvents();
            $this->events = new EventRepository($this->pdo);
            $this->http = new FakeCentralMailerHttpClient();
            $this->outboxRepo = new CentralMailerOutboxRepository($this->pdo);
            $config = new CentralMailerConfig(apiKey: 'k', baseUrl: 'https://mailer.example.test');
            $outboxService = new CentralMailerOutboxService(new CentralMailerClient($this->http, $config), $this->outboxRepo);
            $mailer = new CriticalMailer($config, $outboxService, new MailTemplateRepository($this->pdo));
            $this->registrations = new RegistrationRepository($this->pdo, mailer: $mailer);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for registration mail tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->prepare("DELETE FROM central_mailer_outbox WHERE source_entity_id IN (SELECT id FROM registrations WHERE event_id IN (SELECT id FROM events WHERE slug LIKE :slugLike))")
            ->execute(['slugLike' => $this->slugPrefix . '%']);
        $this->deletePhpUnitEvents();
    }

    public function testCreateEnqueuesRegistrationCreatedMail(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-1', 'status' => 'pending'])));

        $event = $this->events->create($this->payload());
        $registration = $this->registrations->create($this->registrationPayload($event));

        $row = $this->outboxRepo->findByIdempotencyKey($registration['id'] . ':' . MailCatalog::REGISTRATION_CREATED . ':v1');
        self::assertNotNull($row);
        self::assertSame('normal', $row['priority']);
    }

    public function testManualPaymentConfirmationEnqueuesPaymentConfirmedMail(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-2', 'status' => 'pending']))); // mail #4
        $event = $this->events->create($this->payload(['paymentConfig' => ['type' => 'organizer_bank']]));
        $registration = $this->registrations->create($this->registrationPayload($event));

        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-3', 'status' => 'pending']))); // mail #5
        $this->registrations->markManualPaymentPaid($registration['id']);

        $row = $this->outboxRepo->findByIdempotencyKey($registration['id'] . ':' . MailCatalog::PAYMENT_CONFIRMED . ':v1');
        self::assertNotNull($row);
    }

    public function testPaymentConfirmedMailCarriesTheRegistrationStartCodeAsInlineQr(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-qr-a', 'status' => 'pending']))); // mail #4
        $event = $this->events->create($this->payload(['paymentConfig' => ['type' => 'organizer_bank']]));
        $registration = $this->registrations->create($this->registrationPayload($event));
        $code = (string) $this->pdo->query("SELECT qr_code FROM registrations WHERE id = '" . $registration['id'] . "'")->fetchColumn();
        self::assertStringStartsWith('pqr_', $code);

        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-qr-b', 'status' => 'pending']))); // mail #5
        $this->registrations->markManualPaymentPaid($registration['id']);

        $sent = json_decode((string) $this->http->calls[1]['body'], true);
        self::assertSame('qr-kod-startowy', $sent['attachments'][0]['contentId']);
        self::assertSame(\ZmierzymyCzas\Support\QrPng::render($code), base64_decode($sent['attachments'][0]['contentBase64'], true));
        self::assertStringContainsString($code, $sent['html']);
    }

    public function testApproveRefundEnqueuesRefundApprovedMail(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-4', 'status' => 'pending']))); // mail #4
        $event = $this->events->create($this->payload(['paymentConfig' => ['type' => 'organizer_bank']]));
        $registration = $this->registrations->create($this->registrationPayload($event));

        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-5', 'status' => 'pending']))); // mail #5
        $this->registrations->markManualPaymentPaid($registration['id']);

        $this->registrations->requestRefund($registration['id'], 'phpunit-user');
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-6', 'status' => 'pending']))); // mail #14
        $this->registrations->approveRefund($registration['id'], 'phpunit-admin');

        $row = $this->outboxRepo->findByIdempotencyKey($registration['id'] . ':' . MailCatalog::REFUND_APPROVED . ':v1');
        self::assertNotNull($row);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? $this->slugPrefix);
        return array_replace_recursive([
            'name' => 'PHPUnit Mail Event',
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
    private function registrationPayload(array $event, string $userId = 'phpunit-user'): array
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
        $stmt->execute(['slugLike' => $this->slugPrefix . '%']);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($ids as $id) {
            $registrationIds = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
            $registrationIds->execute(['id' => $id]);
            foreach ($registrationIds->fetchAll(PDO::FETCH_COLUMN) ?: [] as $registrationId) {
                $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE source_entity_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_settlement_items WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_payment_details WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_refunds WHERE registration_id = :id')->execute(['id' => $registrationId]);
            }
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
    }
}

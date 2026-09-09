<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Bz\BzWebhookClient;
use ZmierzymyCzas\Domain\Bz\BzWebhookConfig;
use ZmierzymyCzas\Domain\Bz\BzWebhookOutboxRepository;
use ZmierzymyCzas\Domain\Bz\BzWebhookOutboxService;
use ZmierzymyCzas\Domain\Bz\Http\HttpResponse;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\FakeBzHttpClient;

/**
 * E3.4: "Push zmian krytycznych (rezygnacja, zwrot) webhookiem" — the trigger
 * wiring inside RegistrationRepository (cancel() via update(), approveRefund(),
 * markRefunded()). Uses a real bz_webhook_outbox table with a fake BZ HTTP
 * client — no real network call is ever made (BZ's own webhook receiver does
 * not exist yet either way, see BzWebhookClientInterface's doc comment).
 *
 * Mirrors RegistrationRepositoryMailTest's shape (E1.10's equivalent trigger
 * wiring test).
 */
final class RegistrationRepositoryBzWebhookTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private FakeBzHttpClient $http;
    private BzWebhookOutboxRepository $outboxRepo;
    private string $slugPrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->slugPrefix = 'phpregbz-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->deletePhpUnitEvents();
            $this->events = new EventRepository($this->pdo);
            $this->http = new FakeBzHttpClient();
            $this->outboxRepo = new BzWebhookOutboxRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for registration BZ webhook tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM bz_webhook_outbox WHERE event_id IN (SELECT id FROM events WHERE slug LIKE :slugLike)')
            ->execute(['slugLike' => $this->slugPrefix . '%']);
        $this->deletePhpUnitEvents();
    }

    private function registrationsWithBzOutbox(): RegistrationRepository
    {
        $config = new BzWebhookConfig(baseUrl: 'https://bz.example.test');
        $outbox = new BzWebhookOutboxService(new BzWebhookClient($this->http, $config), $this->outboxRepo);
        return new RegistrationRepository($this->pdo, bzWebhookOutbox: $outbox);
    }

    public function testCancelSkipsPushEntirelyWhenNoBzWebhookOutboxIsConfigured(): void
    {
        // The zero-regression case: a RegistrationRepository built the same way
        // every other test in this suite already builds one (no BZ outbox at
        // all, mirroring local dev/CI without BZ_WEBHOOK_BASE_URL set).
        $registrations = new RegistrationRepository($this->pdo);
        $event = $this->events->create($this->payload(['bzIntegrationEnabled' => true, 'bzOrganizationToken' => 'ORG-TOKEN']));
        $registration = $registrations->create($this->registrationPayload($event));

        $registrations->cancel($registration['id']);

        self::assertCount(0, $this->http->calls);
        self::assertNull($this->outboxRepo->findByIdempotencyKey('bz-push:' . $registration['id'] . ':cancellation'));
    }

    public function testCancelSkipsPushWhenEventHasBzIntegrationDisabled(): void
    {
        $registrations = $this->registrationsWithBzOutbox();
        $event = $this->events->create($this->payload(['bzIntegrationEnabled' => false]));
        $registration = $registrations->create($this->registrationPayload($event));

        $registrations->cancel($registration['id']);

        self::assertCount(0, $this->http->calls);
        self::assertNull($this->outboxRepo->findByIdempotencyKey('bz-push:' . $registration['id'] . ':cancellation'));
    }

    public function testCancelSkipsPushWhenEventHasNoOrganizationToken(): void
    {
        $registrations = $this->registrationsWithBzOutbox();
        $event = $this->events->create($this->payload(['bzIntegrationEnabled' => true, 'bzOrganizationToken' => null]));
        $registration = $registrations->create($this->registrationPayload($event));

        $registrations->cancel($registration['id']);

        self::assertCount(0, $this->http->calls);
    }

    public function testCancelPushesCancellationWithCorrectPayloadAndNoMoneyFields(): void
    {
        $registrations = $this->registrationsWithBzOutbox();
        $event = $this->events->create($this->payload(['bzIntegrationEnabled' => true, 'bzOrganizationToken' => 'ORG-TOKEN-1']));
        $registration = $registrations->create($this->registrationPayload($event));

        $this->http->queueResponse(new HttpResponse(202, '{}'));
        $registrations->cancel($registration['id']);

        self::assertCount(1, $this->http->calls);
        $call = $this->http->calls[0];
        self::assertSame('ORG-TOKEN-1', $call['headers']['X-BZ-Organization-Token']);

        $sent = json_decode((string) $call['body'], true);
        self::assertSame((string) $event['id'], $sent['eventId']);
        self::assertSame($registration['id'], $sent['registrationId']);
        self::assertSame('cancellation', $sent['changeType']);
        self::assertArrayHasKey('occurredAt', $sent);
        self::assertArrayHasKey('qrCode', $sent);

        foreach (['amount', 'price', 'totalAmount', 'settlementItems', 'pricePaid', 'discountAmount'] as $forbiddenKey) {
            self::assertArrayNotHasKey($forbiddenKey, $sent, "\"{$forbiddenKey}\" must never appear in the BZ push payload.");
        }

        $stored = $this->outboxRepo->findByIdempotencyKey('bz-push:' . $registration['id'] . ':cancellation');
        self::assertNotNull($stored);
        self::assertSame('sent', $stored['status']);
    }

    public function testCancellingAnAlreadyCancelledRegistrationDoesNotPushAgain(): void
    {
        $registrations = $this->registrationsWithBzOutbox();
        $event = $this->events->create($this->payload(['bzIntegrationEnabled' => true, 'bzOrganizationToken' => 'ORG-TOKEN-2']));
        $registration = $registrations->create($this->registrationPayload($event));

        $this->http->queueResponse(new HttpResponse(202, '{}'));
        $registrations->cancel($registration['id']);
        // Second cancel() call on an already-cancelled Zapis: update()'s own
        // "was this a genuine transition" guard must stop this from ever
        // reaching pushBzCriticalChange() a second time — no queued response
        // needed because the fake client would throw on an unexpected call.
        $registrations->cancel($registration['id']);

        self::assertCount(1, $this->http->calls);
    }

    public function testApproveRefundPushesRefundApproved(): void
    {
        $registrations = $this->registrationsWithBzOutbox();
        $event = $this->events->create($this->payload([
            'bzIntegrationEnabled' => true,
            'bzOrganizationToken' => 'ORG-TOKEN-3',
            'paymentConfig' => ['type' => 'organizer_bank'],
        ]));
        $registration = $registrations->create($this->registrationPayload($event));
        $registrations->markManualPaymentPaid($registration['id']);
        $registrations->requestRefund($registration['id'], 'phpunit-user');

        $this->http->queueResponse(new HttpResponse(202, '{}'));
        $registrations->approveRefund($registration['id'], 'phpunit-admin');

        $stored = $this->outboxRepo->findByIdempotencyKey('bz-push:' . $registration['id'] . ':refund_approved');
        self::assertNotNull($stored);
        $sent = json_decode((string) $this->http->calls[0]['body'], true);
        self::assertSame('refund_approved', $sent['changeType']);
        foreach (['amount', 'route'] as $forbiddenKey) {
            self::assertArrayNotHasKey($forbiddenKey, $sent);
        }
    }

    public function testMarkRefundedPushesRefundRefunded(): void
    {
        $registrations = $this->registrationsWithBzOutbox();
        $event = $this->events->create($this->payload([
            'bzIntegrationEnabled' => true,
            'bzOrganizationToken' => 'ORG-TOKEN-4',
            'paymentConfig' => ['type' => 'organizer_bank'],
        ]));
        $registration = $registrations->create($this->registrationPayload($event));
        $registrations->markManualPaymentPaid($registration['id']);
        $registrations->requestRefund($registration['id'], 'phpunit-user');

        $this->http->queueResponse(new HttpResponse(202, '{}')); // refund_approved
        $registrations->approveRefund($registration['id'], 'phpunit-admin');

        $this->http->queueResponse(new HttpResponse(202, '{}')); // refund_refunded
        $registrations->markRefunded($registration['id'], 'phpunit-admin');

        self::assertCount(2, $this->http->calls);
        $stored = $this->outboxRepo->findByIdempotencyKey('bz-push:' . $registration['id'] . ':refund_refunded');
        self::assertNotNull($stored);
        $sent = json_decode((string) $this->http->calls[1]['body'], true);
        self::assertSame('refund_refunded', $sent['changeType']);

        // A full refund also moves the Zapis to `cancelled` as a side effect
        // (markRefunded()'s own doc comment) — that must NOT additionally fire
        // a separate `cancellation` push (see markRefunded()'s call site).
        self::assertNull($this->outboxRepo->findByIdempotencyKey('bz-push:' . $registration['id'] . ':cancellation'));
    }

    public function testBzWebhookFailureDoesNotBreakTheCancelCall(): void
    {
        // The push is best-effort: a failing/unreachable BZ endpoint must
        // never turn an organizer's cancel action into a 500 — the outbox row
        // still records the failure for later inspection (see
        // BzWebhookOutboxServiceTest for that recording behavior).
        $registrations = $this->registrationsWithBzOutbox();
        $event = $this->events->create($this->payload(['bzIntegrationEnabled' => true, 'bzOrganizationToken' => 'ORG-TOKEN-5']));
        $registration = $registrations->create($this->registrationPayload($event));

        $this->http->queueResponse(new HttpResponse(500, 'server error'));

        $result = $registrations->cancel($registration['id']);

        self::assertSame('cancelled', $result['status']);
        $stored = $this->outboxRepo->findByIdempotencyKey('bz-push:' . $registration['id'] . ':cancellation');
        self::assertNotNull($stored);
        self::assertSame('failed', $stored['status']);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? ($this->slugPrefix . '-' . bin2hex(random_bytes(3))));
        return array_replace_recursive([
            'name' => 'PHPUnit BZ Webhook Event',
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
            'userId' => $userId . '-' . bin2hex(random_bytes(4)),
            'userName' => 'PHPUnit User',
            'userEmail' => $userId . bin2hex(random_bytes(4)) . '@example.test',
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
                $this->pdo->prepare('DELETE FROM bz_webhook_outbox WHERE registration_id = :id')->execute(['id' => $registrationId]);
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

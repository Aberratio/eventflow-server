<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Scheduler;

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

/** Mail #7 sent by the scheduler, once per payment window, with a per-route lead time. */
final class PaymentRemindersJobTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private FakeCentralMailerHttpClient $http;
    private string $slugPrefix;
    private ?string $previousAppUrl = null;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        $this->slugPrefix = 'phprem-' . bin2hex(random_bytes(4));
        $this->pdo = Connection::fromEnv();
        // Outside any try/catch: a broken migration must fail, not skip.
        Migrator::applyAll($this->pdo);
        $this->deleteFixtures();
        $this->events = new EventRepository($this->pdo);
        $this->http = new FakeCentralMailerHttpClient();
        for ($i = 0; $i < 60; $i++) {
            $this->http->queueResponse(new HttpResponse(201, (string) json_encode(['id' => 'cm-' . $i, 'status' => 'pending'])));
        }
        $this->registrations = $this->repository(true);
        $this->previousAppUrl = Env::get('APP_URL', null);
        Env::set('APP_URL', 'https://app.example.test');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->deleteFixtures();
        }
        Env::set('APP_URL', $this->previousAppUrl ?? '');
    }

    public function testGatewayReminderOnceWithin15MinAndLinkAndDateArePolishFriendly(): void
    {
        [$id] = $this->pendingRegistration('gw', 'admin_gateway');
        $now = new \DateTimeImmutable('2026-10-05 10:00:00+00:00');
        $this->setExpiryRaw($id, $now->modify('+10 minutes')->format(DATE_ATOM));

        $first = $this->registrations->sendDuePaymentReminders($now);
        $second = $this->registrations->sendDuePaymentReminders($now->modify('+1 minute'));

        self::assertSame(1, $first['sent']);
        self::assertSame(0, $second['sent']);
        self::assertSame(1, $this->reminderRows($id));
        $html = $this->reminderHtml($id);
        self::assertStringContainsString('https://app.example.test/participant?tab=registrations', $html);
        self::assertStringNotContainsString('/pay', $html);
        // 10:10 UTC on 2026-10-05 is 12:10 in Europe/Warsaw (CEST).
        self::assertStringContainsString('05.10.2026, 12:10', $html);
    }

    public function testGatewayReminderIsNotSentBeforeTheLead(): void
    {
        [$id] = $this->pendingRegistration('gw-early', 'admin_gateway');
        $now = new \DateTimeImmutable('2026-10-05 10:00:00+00:00');
        $this->setExpiryRaw($id, $now->modify('+30 minutes')->format(DATE_ATOM));

        $this->registrations->sendDuePaymentReminders($now);

        self::assertSame(0, $this->reminderRows($id));
    }

    public function testTemplateVersionBumpDoesNotResend(): void
    {
        [$id, $eventId] = $this->pendingRegistration('tpl', 'admin_gateway');
        $now = new \DateTimeImmutable('2026-10-05 10:00:00+00:00');
        $this->setExpiryRaw($id, $now->modify('+10 minutes')->format(DATE_ATOM));
        $this->registrations->sendDuePaymentReminders($now);
        self::assertSame(1, $this->reminderRows($id));

        $templates = new MailTemplateRepository($this->pdo);
        for ($i = 0; $i < 2; $i++) {
            $templates->saveEventOverride(
                MailCatalog::UNPAID_REMINDER,
                $eventId,
                'Zmiana ' . $i . ' {{eventName}}',
                '<p>{{eventName}} {{amountDue}} {{payNowLink}} {{expiresAt}} wersja ' . $i . '</p>',
                'phpunit-actor'
            );
        }
        $this->registrations->sendDuePaymentReminders($now->modify('+2 minutes'));

        self::assertSame(1, $this->reminderRows($id), 'editing the template must not send a second reminder');
    }

    public function testTransferRouteUses24hLead(): void
    {
        [$soon] = $this->pendingRegistration('bank-soon', 'organizer_bank');
        [$later] = $this->pendingRegistration('bank-later', 'organizer_bank');
        $now = new \DateTimeImmutable('2026-10-05 10:00:00+00:00');
        $this->setExpiryRaw($soon, $now->modify('+20 hours')->format(DATE_ATOM));
        $this->setExpiryRaw($later, $now->modify('+30 hours')->format(DATE_ATOM));

        $this->registrations->sendDuePaymentReminders($now);

        self::assertSame(1, $this->reminderRows($soon));
        self::assertSame(0, $this->reminderRows($later));
    }

    public function testNewWindowAfterRetryGetsOneNewReminder(): void
    {
        [$id] = $this->pendingRegistration('retry', 'admin_gateway');
        $now = new \DateTimeImmutable('2026-10-05 10:00:00+00:00');
        $this->setExpiryRaw($id, $now->modify('+10 minutes')->format(DATE_ATOM));
        $this->registrations->sendDuePaymentReminders($now);
        self::assertSame(1, $this->reminderRows($id));

        $this->registrations->expireOverdueRegistrations($now->modify('+11 minutes'));
        self::assertSame('expired', $this->statusOf($id));
        $retried = $this->registrations->retryPayment($id);
        self::assertSame('pending', $retried['status']);

        $newExpiry = new \DateTimeImmutable((string) $this->rawExpiry($id));
        $this->registrations->sendDuePaymentReminders($newExpiry->modify('-10 minutes'));
        $this->registrations->sendDuePaymentReminders($newExpiry->modify('-9 minutes'));

        self::assertSame(2, $this->reminderRows($id), 'one reminder per payment window');
    }

    public function testNothingClaimedWhenMailerNotConfigured(): void
    {
        [$id] = $this->pendingRegistration('noconf', 'admin_gateway');
        $now = new \DateTimeImmutable('2026-10-05 10:00:00+00:00');
        $this->setExpiryRaw($id, $now->modify('+10 minutes')->format(DATE_ATOM));

        $result = $this->repository(false)->sendDuePaymentReminders($now);

        self::assertSame(['skipped' => 1], $result);
        $stmt = $this->pdo->prepare('SELECT payment_reminder_for FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        self::assertNull($stmt->fetchColumn());
    }

    public function testReadPathsNoLongerExpireOrRemind(): void
    {
        [$due, $dueDistance, $eventId] = $this->pendingRegistration('reads-due', 'admin_gateway');
        [$overdue, $overdueDistance] = $this->pendingRegistration('reads-overdue', 'admin_gateway');
        $this->setExpiryRaw($due, (new \DateTimeImmutable())->modify('+10 minutes')->format(DATE_ATOM));
        $this->setExpiryRaw($overdue, (new \DateTimeImmutable())->modify('-1 hour')->format(DATE_ATOM));
        $callsBefore = count($this->http->calls);

        $this->registrations->find($due);
        $this->registrations->byEvent($eventId);
        $this->registrations->list(['eventId' => $eventId]);
        $this->registrations->publicStartList($eventId, true);
        $this->registrations->byUser('phpunit-rem-user');
        $this->registrations->organizerStats($eventId);

        self::assertSame('pending', $this->statusOf($due));
        self::assertSame('pending', $this->statusOf($overdue));
        self::assertSame(0, $this->reminderRows($due));
        self::assertSame(0, $this->reminderRows($overdue));
        self::assertSame($callsBefore, count($this->http->calls), 'a read must not call Central Mailer');
        self::assertSame(1, $this->registeredCount($overdueDistance));
        self::assertSame(1, $this->registeredCount($dueDistance));
    }

    // --- fixtures -------------------------------------------------------------

    private function repository(bool $configured): RegistrationRepository
    {
        $config = $configured
            ? new CentralMailerConfig(apiKey: 'k', baseUrl: 'https://mailer.example.test')
            : new CentralMailerConfig(apiKey: '', baseUrl: '');
        $outbox = new CentralMailerOutboxService(
            new CentralMailerClient($this->http, $config),
            new CentralMailerOutboxRepository($this->pdo)
        );

        return new RegistrationRepository(
            $this->pdo,
            mailer: new CriticalMailer($config, $outbox, new MailTemplateRepository($this->pdo))
        );
    }

    /** @return array{0: string, 1: string, 2: string} registration id, distance id, event id */
    private function pendingRegistration(string $name, string $paymentType): array
    {
        $slug = $this->slugPrefix . '-' . $name;
        $event = $this->events->create([
            'name' => 'PHPUnit Reminder ' . $name,
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-12-15',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'draft',
            'registrationDeadline' => '2026-12-01',
            'featured' => false,
            'paymentConfig' => ['type' => $paymentType],
            'distances' => [[
                'id' => $slug . '-10k', 'name' => '10 km', 'length' => 10, 'unit' => 'km',
                'categories' => ['Open'], 'ageCategories' => [], 'specialCategories' => [],
                'limit' => 100, 'registeredCount' => 0, 'price' => 80, 'routeFiles' => [],
            ]],
        ]);
        $registration = $this->registrations->create([
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'userId' => 'phpunit-rem-user',
            'userName' => 'PHPUnit User',
            'userEmail' => 'phpunit-rem-user@example.test',
            'distanceId' => $event['distances'][0]['id'],
            'distanceName' => $event['distances'][0]['name'],
            'category' => 'Open',
            'merchandiseOrders' => [],
        ]);
        self::assertSame('pending', $registration['status']);

        return [(string) $registration['id'], (string) $event['distances'][0]['id'], (string) $event['id']];
    }

    private function setExpiryRaw(string $id, string $raw): void
    {
        $this->pdo->prepare('UPDATE registrations SET payment_expires_at = :when WHERE id = :id')
            ->execute(['when' => $raw, 'id' => $id]);
    }

    private function rawExpiry(string $id): string
    {
        $stmt = $this->pdo->prepare('SELECT payment_expires_at FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return (string) $stmt->fetchColumn();
    }

    private function statusOf(string $id): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return (string) $stmt->fetchColumn();
    }

    private function registeredCount(string $distanceId): int
    {
        $stmt = $this->pdo->prepare('SELECT registered_count FROM event_distances WHERE id = :id');
        $stmt->execute(['id' => $distanceId]);

        return (int) $stmt->fetchColumn();
    }

    private function reminderRows(string $registrationId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM central_mailer_outbox WHERE source_entity_type = :type AND source_entity_id = :id'
        );
        $stmt->execute(['type' => MailCatalog::UNPAID_REMINDER, 'id' => $registrationId]);

        return (int) $stmt->fetchColumn();
    }

    private function reminderHtml(string $registrationId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT html FROM central_mailer_outbox WHERE source_entity_type = :type AND source_entity_id = :id LIMIT 1'
        );
        $stmt->execute(['type' => MailCatalog::UNPAID_REMINDER, 'id' => $registrationId]);

        return (string) $stmt->fetchColumn();
    }

    private function deleteFixtures(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => 'phprem-%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $eventId) {
            $regs = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :eventId');
            $regs->execute(['eventId' => $eventId]);
            foreach ($regs->fetchAll(PDO::FETCH_COLUMN) ?: [] as $registrationId) {
                foreach (['registration_gateway_transactions', 'registration_refunds', 'registration_payment_details',
                    'registration_settlement_items', 'registration_merchandise_orders'] as $table) {
                    $this->pdo->prepare("DELETE FROM {$table} WHERE registration_id = :id")->execute(['id' => $registrationId]);
                }
                $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE source_entity_id = :id')->execute(['id' => $registrationId]);
            }
            $this->pdo->prepare('DELETE FROM mail_templates WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :eventId')->execute(['eventId' => $eventId]);
        }
    }
}

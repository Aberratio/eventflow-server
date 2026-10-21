<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Mailer;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerClient;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxService;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\CriticalMailer;
use ZmierzymyCzasDomainMailerCriticalMailsventReplyToResolver;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\EventReplyToResolver;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailCatalog;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailTemplateRepository;
use ZmierzymyCzas\Domain\Mailer\Http\HttpResponse;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Support\QrCode;
use ZmierzymyCzas\Support\QrPng;
use ZmierzymyCzas\Tests\Support\FakeCentralMailerHttpClient;

/**
 * CriticalMailer end-to-end against a real outbox + mail_templates table and a
 * fake Central Mailer HTTP client — exercises the full chain: template
 * resolution, rendering, deterministic idempotency-key construction,
 * queue routing, and CentralMailerConfig::isConfigured() gating (mirrors
 * TpayConfig/PayNowConfig's "skip silently" stance).
 */
final class CriticalMailerTest extends TestCase
{
    private PDO $pdo;
    private FakeCentralMailerHttpClient $http;
    private CentralMailerOutboxRepository $outboxRepo;
    private string $prefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        $this->prefix = 'phpunit-cm-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->http = new FakeCentralMailerHttpClient();
            $this->outboxRepo = new CentralMailerOutboxRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for CriticalMailer tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM events WHERE id LIKE :prefix')->execute(['prefix' => $this->prefix . '%']);
        $this->pdo->prepare('DELETE FROM users WHERE id LIKE :prefix')->execute(['prefix' => $this->prefix . '%']);
        $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE source_entity_id LIKE :prefix')
            ->execute(['prefix' => $this->prefix . '%']);
    }

    private function mailer(bool $configured = true): CriticalMailer
    {
        $config = new CentralMailerConfig(apiKey: $configured ? 'test-key' : '', baseUrl: $configured ? 'https://mailer.example.test' : '');
        $client = new CentralMailerClient($this->http, $config);
        $outbox = new CentralMailerOutboxService($client, $this->outboxRepo);

        return new CriticalMailer($config, $outbox, new MailTemplateRepository($this->pdo), replyToResolver: new EventReplyToResolver($this->pdo));
    }

    /** @param array<string, mixed>|null $contact */
    private function insertEvent(string $suffix, ?array $contact, ?string $organizerUserId = null): string
    {
        $id = $this->prefix . '-evt-' . $suffix;
        $this->pdo->prepare(
            'INSERT INTO events
                (id, name, slug, discipline, date, location, city, description, image_url,
                 organizer_id, organizer_name, organizer_contact, registration_deadline)
             VALUES
                (:id, :name, :slug, :discipline, :date, :location, :city, :description, :imageUrl,
                 :organizerId, :organizerName, :contact, :registrationDeadline)'
        )->execute([
            'id' => $id,
            'name' => 'Bieg testowy',
            'slug' => $id,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+6 months')),
            'location' => 'Stadion',
            'city' => 'Warszawa',
            'description' => 'Opis',
            'imageUrl' => 'https://example.test/e.jpg',
            'organizerId' => $organizerUserId ?? ($this->prefix . '-no-such-user'),
            'organizerName' => 'Klub Biegowy',
            'contact' => $contact === null ? null : json_encode($contact),
            'registrationDeadline' => '2026-06-01',
        ]);

        return $id;
    }

    private function insertUser(string $suffix, string $email): string
    {
        $id = $this->prefix . '-usr-' . $suffix;
        $this->pdo->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (:id, :name, :email, :hash)')
            ->execute(['id' => $id, 'name' => 'Organizator', 'email' => $email, 'hash' => 'x']);

        return $id;
    }

    /** @return array<string, mixed> request body of the last POST to Central Mailer */
    private function lastRequestBody(): array
    {
        return json_decode((string) $this->http->calls[array_key_last($this->http->calls)]['body'], true);
    }

    public function testEventMailsCarryTheOrganizerContactEmailAsReplyTo(): void
    {
        $eventId = $this->insertEvent('contact', ['email' => 'kontakt@klub.test', 'phone' => '+48 500 000 000']);
        $mailer = $this->mailer();

        $cases = [
            'registration_created' => fn () => $mailer->sendRegistrationCreated($this->prefix . '-r1', $eventId, 'user@example.test', 'Bieg', '10km', '50.00'),
            'payment_confirmed' => fn () => $mailer->sendPaymentConfirmed($this->prefix . '-r2', $eventId, 'user@example.test', 'Bieg', '10km', 'https://app/my'),
            'unpaid_reminder' => fn () => $mailer->sendUnpaidReminder($this->prefix . '-r3', $eventId, 'user@example.test', 'Bieg', '50.00', 'https://app/pay', '2026-01-01', 'w1'),
            'event_changed' => fn () => $mailer->sendEventChanged($eventId, $this->prefix . '-rcp', 'user@example.test', 'Bieg', 'zmiana', 'n/a'),
            'moderation_decision' => fn () => $mailer->sendModerationDecision($eventId, 'org@example.test', 'Bieg', 'approved'),
            'results_published' => fn () => $mailer->sendResultsPublished($eventId, $this->prefix . '-hash', 'user@example.test', 'Bieg', 'https://app/results'),
        ];
        foreach ($cases as $type => $send) {
            $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-' . $type, 'status' => 'pending'])));
            $row = $send();
            self::assertSame(['email' => 'kontakt@klub.test', 'name' => 'Klub Biegowy'], $row['replyTo'], $type);
        }

        // Everything but the deferred results mail was POSTed with replyTo in the body.
        self::assertCount(5, $this->http->calls);
        foreach ($this->http->calls as $call) {
            self::assertSame(['email' => 'kontakt@klub.test', 'name' => 'Klub Biegowy'], json_decode((string) $call['body'], true)['replyTo']);
        }
    }

    public function testReplyToFallsBackToOrganizerAccountEmailWhenContactHasNoEmail(): void
    {
        $userId = $this->insertUser('org', 'konto-organizatora@klub.test');
        $eventId = $this->insertEvent('account', ['phone' => '+48 500 000 000'], $userId);
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-acc', 'status' => 'pending'])));

        $this->mailer()->sendRegistrationCreated($this->prefix . '-r4', $eventId, 'user@example.test', 'Bieg', '10km', '50.00');

        self::assertSame(['email' => 'konto-organizatora@klub.test', 'name' => 'Klub Biegowy'], $this->lastRequestBody()['replyTo']);
    }

    public function testNoReplyToWhenOrganizerEmailIsUnknownOrInvalid(): void
    {
        $eventId = $this->insertEvent('none', ['email' => 'not-an-email']);
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-none', 'status' => 'pending'])));

        $row = $this->mailer()->sendRegistrationCreated($this->prefix . '-r5', $eventId, 'user@example.test', 'Bieg', '10km', '50.00');

        self::assertNull($row['replyTo']);
        self::assertArrayNotHasKey('replyTo', $this->lastRequestBody());
    }

    public function testAuthenticationAndSystemMailsHaveNoReplyTo(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-a', 'status' => 'pending'])));
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-b', 'status' => 'pending'])));
        $mailer = $this->mailer();

        $mailer->sendPasswordReset($this->prefix . '-t1', 'user@example.test', 'https://app/reset', 60);
        $mailer->sendShopOrderStatus($this->prefix . '-t2', 'user@example.test', 'ZC-1', 'shipped');

        foreach ($this->http->calls as $call) {
            self::assertArrayNotHasKey('replyTo', json_decode((string) $call['body'], true));
        }
    }

    public function testSendReturnsNullSilentlyWhenNotConfigured(): void
    {
        $mailer = $this->mailer(configured: false);

        $result = $mailer->sendPasswordReset($this->prefix . '-user', 'user@example.test', 'https://app/reset', 60);

        self::assertNull($result);
        self::assertCount(0, $this->http->calls);
    }

    public function testTechnicalMailUsesTechnicalPriority(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-1', 'status' => 'pending'])));
        $mailer = $this->mailer();

        $row = $mailer->sendPasswordReset($this->prefix . '-user', 'user@example.test', 'https://app/reset', 60);

        self::assertNotNull($row);
        self::assertSame('technical', $row['priority']);
    }

    public function testAccountSetupMailUsesTechnicalPriorityAndRendersTheLink(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-setup', 'status' => 'pending'])));
        $mailer = $this->mailer();
        $tokenId = $this->prefix . '-token';

        $row = $mailer->sendAccountSetup($tokenId, 'user@example.test', 'https://app.example.test/reset-password?token=abc', 72);

        self::assertNotNull($row);
        self::assertSame('technical', $row['priority']);
        self::assertSame($tokenId . ':' . MailCatalog::ACCOUNT_SETUP . ':v1', $row['idempotencyKey']);
        $sent = json_decode((string) $this->http->calls[0]['body'], true);
        self::assertStringContainsString('https://app.example.test/reset-password?token=abc', $sent['html']);
        self::assertStringContainsString('72 godz.', $sent['html']);
    }

    public function testStandardMailUsesNormalPriority(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-2', 'status' => 'pending'])));
        $mailer = $this->mailer();

        $row = $mailer->sendRegistrationCreated($this->prefix . '-reg', 'evt-1', 'user@example.test', 'Bieg', '10km', '50.00');

        self::assertNotNull($row);
        self::assertSame('normal', $row['priority']);
    }

    public function testIdempotencyKeyIsDeterministicSourceEntityMailTypeAndVersion(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-3', 'status' => 'pending'])));
        $mailer = $this->mailer();

        $sourceId = $this->prefix . '-reg-2';
        $row = $mailer->sendRegistrationCreated($sourceId, 'evt-1', 'user@example.test', 'Bieg', '10km', '50.00');

        self::assertSame($sourceId . ':' . MailCatalog::REGISTRATION_CREATED . ':v1', $row['idempotencyKey']);
    }

    /** Only the unpaid reminder gains a payment-window discriminator; every other key stays byte-identical. */
    public function testUnpaidReminderKeyCarriesWindowDiscriminatorOthersUnchanged(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-w1', 'status' => 'pending'])));
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-w2', 'status' => 'pending'])));
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-w3', 'status' => 'pending'])));
        $mailer = $this->mailer();
        $sourceId = $this->prefix . '-reg-window';
        $window = '2026-10-05T12:00:00+00:00';

        $row = $mailer->sendUnpaidReminder($sourceId, 'evt-1', 'user@example.test', 'Bieg', '80.00', 'https://x.test/participant', '05.10.2026, 14:00', $window);
        $otherWindow = $mailer->sendUnpaidReminder($sourceId, 'evt-1', 'user@example.test', 'Bieg', '80.00', 'https://x.test/participant', '06.10.2026, 14:00', '2026-10-06T12:00:00+00:00');
        $other = $mailer->sendRegistrationCreated($sourceId . '-b', 'evt-1', 'user@example.test', 'Bieg', '10km', '50.00');

        self::assertMatchesRegularExpression(
            '/^' . preg_quote($sourceId . ':' . MailCatalog::UNPAID_REMINDER . ':v1:w', '/') . '[0-9a-f]{12}$/',
            $row['idempotencyKey']
        );
        self::assertNotSame($row['idempotencyKey'], $otherWindow['idempotencyKey']);
        self::assertSame($sourceId . '-b:' . MailCatalog::REGISTRATION_CREATED . ':v1', $other['idempotencyKey']);
    }

    public function testRepeatedCallWithSameEntityDoesNotCallCentralMailerTwice(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-4', 'status' => 'pending'])));
        $mailer = $this->mailer();
        $sourceId = $this->prefix . '-reg-3';

        $first = $mailer->sendRegistrationCreated($sourceId, 'evt-1', 'user@example.test', 'Bieg', '10km', '50.00');
        $second = $mailer->sendRegistrationCreated($sourceId, 'evt-1', 'user@example.test', 'Bieg', '10km', '50.00');

        self::assertSame($first['id'], $second['id']);
        self::assertCount(1, $this->http->calls);
    }

    public function testSendReturnsNullWhenRecipientEmailIsBlank(): void
    {
        $mailer = $this->mailer();

        $result = $mailer->sendIdentityCode($this->prefix . '-competitor', '', 'Jan Kowalski');

        self::assertNull($result);
        self::assertCount(0, $this->http->calls);
    }

    public function testEventOverrideIsRenderedInsteadOfGlobalDefault(): void
    {
        $eventId = $this->prefix . '-evt';
        $templates = new MailTemplateRepository($this->pdo);
        $templates->saveEventOverride(
            MailCatalog::REGISTRATION_CREATED,
            $eventId,
            'Nadpisany temat: {{eventName}}',
            '<p>Nadpisana tresc dla {{eventName}}</p>',
            'organizer-1'
        );
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-5', 'status' => 'pending'])));
        $mailer = $this->mailer();

        $mailer->sendRegistrationCreated($this->prefix . '-reg-4', $eventId, 'user@example.test', 'Bieg Testowy', '10km', '50.00');

        $sent = json_decode((string) $this->http->calls[0]['body'], true);
        self::assertSame('Nadpisany temat: Bieg Testowy', $sent['subject']);
        self::assertStringContainsString('Nadpisana tresc dla Bieg Testowy', $sent['html']);

        $this->pdo->prepare('DELETE FROM mail_templates WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
    }

    public function testPaymentConfirmedCarriesInlineQrAttachment(): void
    {
        $code = QrCode::startCode();
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-qr5', 'status' => 'pending'])));

        $row = $this->mailer()->sendPaymentConfirmed($this->prefix . '-reg-qr', 'evt-qr', 'user@example.test', 'Bieg', '10 km', 'https://app.example.test/my-starts', $code);

        self::assertNotNull($row);
        $sent = json_decode((string) $this->http->calls[0]['body'], true);
        self::assertCount(1, $sent['attachments']);
        $attachment = $sent['attachments'][0];
        self::assertSame('kod-startowy.png', $attachment['filename']);
        self::assertSame('qr-kod-startowy', $attachment['contentId']);
        self::assertTrue($attachment['inline']);
        $png = base64_decode($attachment['contentBase64'], true);
        self::assertIsString($png);
        self::assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
        self::assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->buffer($png));
        self::assertSame(QrPng::render($code), $png, 'the image encodes exactly the Zapis start code');
        self::assertStringContainsString('src="cid:qr-kod-startowy"', $sent['html']);
        self::assertStringContainsString($code, $sent['html']);

        $stored = $this->outboxRepo->find($row['id']);
        self::assertCount(1, $stored['attachments']);
        self::assertSame($attachment['contentBase64'], $stored['attachments'][0]['contentBase64']);
    }

    public function testIdentityCodeCarriesInlineQrAttachment(): void
    {
        $code = QrCode::identityCode();
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-qr6', 'status' => 'pending'])));

        $row = $this->mailer()->sendIdentityCode($this->prefix . '-comp-qr', 'guardian@example.test', 'Zosia Test', $code);

        self::assertNotNull($row);
        $sent = json_decode((string) $this->http->calls[0]['body'], true);
        self::assertCount(1, $sent['attachments']);
        self::assertSame('kod-tozsamosci.png', $sent['attachments'][0]['filename']);
        self::assertSame('qr-kod-tozsamosci', $sent['attachments'][0]['contentId']);
        self::assertTrue($sent['attachments'][0]['inline']);
        self::assertSame(QrPng::render($code), base64_decode($sent['attachments'][0]['contentBase64'], true));
        self::assertStringContainsString('src="cid:qr-kod-tozsamosci"', $sent['html']);
        self::assertStringContainsString($code, $sent['html']);
    }

    public function testPaymentConfirmedWithoutCodeHasNoAttachment(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-noqr', 'status' => 'pending'])));

        $row = $this->mailer()->sendPaymentConfirmed($this->prefix . '-reg-noqr', 'evt-qr', 'user@example.test', 'Bieg', '10 km', 'https://app.example.test/my-starts');

        self::assertNotNull($row);
        $sent = json_decode((string) $this->http->calls[0]['body'], true);
        self::assertArrayNotHasKey('attachments', $sent);
        self::assertStringNotContainsString('cid:', $sent['html']);
        self::assertNull($this->outboxRepo->find($row['id'])['attachments']);
    }

    public function testQrBlockSurvivesEventTemplateOverride(): void
    {
        $eventId = $this->prefix . '-evt-qr';
        $templates = new MailTemplateRepository($this->pdo);
        $templates->saveEventOverride(MailCatalog::PAYMENT_CONFIRMED, $eventId, 'Bilet: {{eventName}}', '<p>Wlasna tresc {{eventName}}</p>', 'organizer-1');
        $code = QrCode::startCode();
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-qr-ovr', 'status' => 'pending'])));

        $this->mailer()->sendPaymentConfirmed($this->prefix . '-reg-ovr', $eventId, 'user@example.test', 'Bieg Testowy', '10 km', 'https://app.example.test/my-starts', $code);

        $sent = json_decode((string) $this->http->calls[0]['body'], true);
        self::assertStringContainsString('Wlasna tresc Bieg Testowy', $sent['html']);
        self::assertStringContainsString('src="cid:qr-kod-startowy"', $sent['html']);
        self::assertCount(1, $sent['attachments']);

        $this->pdo->prepare('DELETE FROM mail_templates WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
    }

    public function testEventChangedWithTwoUuidsIsStored(): void
    {
        // Two 36-char ids give a 73-char source_entity_id; VARCHAR(36) overflowed on strict MySQL 8.4.
        $eventId = $this->prefix . '-' . str_repeat('a', 16);
        $recipientId = $this->prefix . '-' . str_repeat('b', 16);
        self::assertSame(36, strlen($eventId));
        $this->http->queueResponse(new HttpResponse(201, json_encode(['id' => 'cm-evt-changed', 'status' => 'pending'])));

        $row = $this->mailer()->sendEventChanged($eventId, $recipientId, 'user@example.test', 'Bieg', 'Zmiana terminu', 'brak');

        self::assertNotNull($row);
        self::assertSame($eventId . ':' . $recipientId, $this->outboxRepo->find($row['id'])['sourceEntityId']);
        self::assertSame(73, strlen((string) $row['sourceEntityId']));
    }

    public function testResultsPublishedIsDeferredAndFitsSourceColumn(): void
    {
        $eventId = $this->prefix . '-' . str_repeat('c', 16);
        $recipientKey = substr(hash('sha256', 'user@example.test'), 0, 16);

        $row = $this->mailer()->sendResultsPublished($eventId, $recipientKey, 'user@example.test', 'Bieg', 'https://app.example.test/events/bieg/results');

        self::assertCount(0, $this->http->calls, 'a deferred row makes no HTTP call');
        self::assertNotNull($row);
        self::assertSame('queued', $row['status']);
        self::assertSame(0, $row['attempts']);
        self::assertNull($row['centralMailerEmailId']);
        self::assertSame($eventId . ':' . $recipientKey, $this->outboxRepo->find($row['id'])['sourceEntityId']);
        self::assertSame(53, strlen((string) $row['sourceEntityId']));
    }
}

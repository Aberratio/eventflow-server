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
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailCatalog;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailTemplateRepository;
use ZmierzymyCzas\Domain\Mailer\Http\HttpResponse;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\FakeCentralMailerHttpClient;

/**
 * CriticalMailer end-to-end against a real outbox + mail_templates table and a
 * fake Central Mailer HTTP client — exercises the full E1.10 chain: template
 * resolution, rendering, deterministic idempotency-key construction (§6),
 * queue routing (§2), and CentralMailerConfig::isConfigured() gating (mirrors
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
        $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE source_entity_id LIKE :prefix')
            ->execute(['prefix' => $this->prefix . '%']);
    }

    private function mailer(bool $configured = true): CriticalMailer
    {
        $config = new CentralMailerConfig(apiKey: $configured ? 'test-key' : '', baseUrl: $configured ? 'https://mailer.example.test' : '');
        $client = new CentralMailerClient($this->http, $config);
        $outbox = new CentralMailerOutboxService($client, $this->outboxRepo);

        return new CriticalMailer($config, $outbox, new MailTemplateRepository($this->pdo));
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
}

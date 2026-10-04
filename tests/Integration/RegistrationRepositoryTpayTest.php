<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerClient;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxService;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\CriticalMailer;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailTemplateRepository;
use ZmierzymyCzas\Domain\Mailer\Http\HttpResponse;
use ZmierzymyCzas\Domain\Payments\PaymentGatewayInterface;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\FakeCentralMailerHttpClient;

/**
 * E1.6: RegistrationRepository wired against a fake gateway (implements the same
 * PaymentGatewayInterface the real TpayGateway does) — exercises "start a session
 * on create/retry", "amount always comes from the frozen Rozliczenie, never the
 * caller", "webhook idempotency by transaction_id", and "a real refund call fires
 * from approveRefund() on trasa A" without ever touching HTTP/Tpay itself (that
 * part is covered by tests/Unit/Payments/*Test.php against a FakeHttpClient).
 */
final class RegistrationRepositoryTpayTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private FakeGateway $gateway;
    private RegistrationRepository $registrations;
    private string $slugPrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->slugPrefix = 'phptpay-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->deletePhpUnitEvents();
            $this->events = new EventRepository($this->pdo);
            $this->gateway = new FakeGateway();
            $this->registrations = new RegistrationRepository($this->pdo, $this->gateway);
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

    public function testCreateOnGatewayRouteStartsASessionWithTheFrozenSettlementAmount(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-gw']));
        $this->gateway->nextSession = ['transactionId' => 'TX-100', 'redirectUrl' => 'https://pay.example.test/TX-100'];

        $registration = $this->registrations->create($this->registrationPayload($event));

        self::assertSame('pending', $registration['status']);
        self::assertSame(['transactionId' => 'TX-100', 'redirectUrl' => 'https://pay.example.test/TX-100'], $registration['paymentSession']);
        self::assertCount(1, $this->gateway->startSessionCalls);
        // The amount handed to the gateway is exactly the frozen Rozliczenie total
        // (distance price 80.0 here) — never anything a caller could have injected.
        self::assertSame(80.0, $this->gateway->startSessionCalls[0]['amount']);
        self::assertSame($registration['id'], $this->gateway->startSessionCalls[0]['registrationId']);

        $tx = $this->rawGatewayTransaction('TX-100');
        self::assertSame($registration['id'], $tx['registrationId']);
        self::assertSame('created', $tx['status']);
        self::assertSame('80.00', $tx['amount']);
    }

    public function testClientSuppliedAmountFieldsAreIgnoredByTheGatewaySession(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-inj']));
        $this->gateway->nextSession = ['transactionId' => 'TX-inj', 'redirectUrl' => 'https://pay.example.test/TX-inj'];

        $payload = $this->registrationPayload($event);
        // A hostile/buggy client trying to smuggle its own amount in.
        $payload['totalAmount'] = 1.0;
        $payload['price'] = 1.0;
        $payload['paymentDetails'] = ['startFeeAmount' => 1.0];

        $this->registrations->create($payload);

        self::assertSame(80.0, $this->gateway->startSessionCalls[0]['amount']);
    }

    public function testCreateOnOrganizerBankRouteNeverStartsAGatewaySession(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-bank',
            'paymentConfig' => ['type' => 'organizer_bank'],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event));

        self::assertNull($registration['paymentSession']);
        self::assertCount(0, $this->gateway->startSessionCalls);
    }

    public function testNoGatewayConfiguredSkipsSessionStartWithoutError(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-nogw']));
        $registrations = new RegistrationRepository($this->pdo);

        $registration = $registrations->create($this->registrationPayload($event));

        self::assertSame('pending', $registration['status']);
        self::assertNull($registration['paymentSession']);
    }

    public function testGatewayFailureDoesNotBreakRegistrationCreation(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-fail']));
        $this->gateway->throwOnStartSession = true;

        $registration = $this->registrations->create($this->registrationPayload($event));

        self::assertSame('pending', $registration['status']);
        self::assertNull($registration['paymentSession']);
    }

    public function testRetryPaymentOnExpiredGatewayRegistrationStartsANewSession(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-retry']));
        $this->gateway->nextSession = ['transactionId' => 'TX-first', 'redirectUrl' => 'https://pay.example.test/first'];
        $registration = $this->registrations->create($this->registrationPayload($event));

        $this->pdo->prepare("UPDATE registrations SET status = 'expired' WHERE id = :id")->execute(['id' => $registration['id']]);

        $this->gateway->nextSession = ['transactionId' => 'TX-second', 'redirectUrl' => 'https://pay.example.test/second'];
        $retried = $this->registrations->retryPayment($registration['id']);

        self::assertSame('pending', $retried['status']);
        self::assertSame('TX-second', $retried['paymentSession']['transactionId']);
        self::assertCount(2, $this->gateway->startSessionCalls);
        self::assertNotNull($this->rawGatewayTransaction('TX-first'));
        self::assertNotNull($this->rawGatewayTransaction('TX-second'));
    }

    public function testApplyGatewayNotificationConfirmsAPendingRegistration(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-notif']));
        $this->gateway->nextSession = ['transactionId' => 'TX-paid', 'redirectUrl' => 'https://pay.example.test/paid'];
        $registration = $this->registrations->create($this->registrationPayload($event));
        self::assertSame('pending', $registration['status']);

        $result = $this->registrations->applyGatewayNotification('TX-paid', true, 8000);

        self::assertTrue($result['applied']);
        self::assertSame($registration['id'], $result['registrationId']);
        $reloaded = $this->registrations->find($registration['id']);
        self::assertSame('confirmed', $reloaded['status']);
        self::assertTrue($reloaded['paymentDetails']['startFeePaid']);
        self::assertSame(80.0, (float) $reloaded['paymentDetails']['startFeeAmount']);
    }

    /**
     * The ticket's core idempotency requirement: Tpay retrying the same webhook
     * (its own timeout retry) after we already applied it once must be a pure
     * no-op — no double side effects, no error.
     */
    public function testApplyGatewayNotificationIsIdempotentForARepeatedTransactionId(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-idem']));
        $this->gateway->nextSession = ['transactionId' => 'TX-dup', 'redirectUrl' => 'https://pay.example.test/dup'];
        $registration = $this->registrations->create($this->registrationPayload($event));

        $first = $this->registrations->applyGatewayNotification('TX-dup', true, 8000);
        self::assertTrue($first['applied']);

        // Simulate Tpay retrying the same notification after a timeout.
        $second = $this->registrations->applyGatewayNotification('TX-dup', true, 8000);
        self::assertFalse($second['applied']);

        $reloaded = $this->registrations->find($registration['id']);
        self::assertSame('confirmed', $reloaded['status']);
        $tx = $this->rawGatewayTransaction('TX-dup');
        self::assertSame('paid', $tx['status']);
    }

    public function testApplyGatewayNotificationForUnknownTransactionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('gateway_transaction_unknown');
        $this->registrations->applyGatewayNotification('does-not-exist', true, 1000);
    }

    public function testApplyGatewayNotificationMarksFailedWithoutConfirming(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-declined']));
        $this->gateway->nextSession = ['transactionId' => 'TX-declined', 'redirectUrl' => 'https://pay.example.test/declined'];
        $registration = $this->registrations->create($this->registrationPayload($event));

        $result = $this->registrations->applyGatewayNotification('TX-declined', false, null);

        self::assertTrue($result['applied']);
        $reloaded = $this->registrations->find($registration['id']);
        self::assertSame('pending', $reloaded['status']);
        self::assertSame('failed', $this->rawGatewayTransaction('TX-declined')['status']);
    }

    /** @return array<string, array{int|null}> */
    public static function mismatchedAmountProvider(): array
    {
        return [
            'underpaid' => [7999],
            'overpaid' => [8001],
            'no amount reported' => [null],
        ];
    }

    #[DataProvider('mismatchedAmountProvider')]
    public function testTpayPaidNotificationWithAMismatchedAmountDoesNotConfirm(?int $notified): void
    {
        $registration = $this->registrationWithTransaction('TX-mismatch');

        $result = $this->registrations->applyGatewayNotification('TX-mismatch', true, $notified);

        self::assertFalse($result['applied']);
        self::assertTrue($result['amountMismatch']);
        $reloaded = $this->registrations->find($registration['id']);
        self::assertSame('pending', $reloaded['status']);
        self::assertNotTrue($reloaded['paymentDetails']['startFeePaid'] ?? null);
        self::assertSame('amount_mismatch', $this->rawGatewayTransaction('TX-mismatch')['status']);
    }

    public function testRepeatedMismatchIsNoOpAndLaterExactAmountConfirms(): void
    {
        $registration = $this->registrationWithTransaction('TX-retry');

        $this->registrations->applyGatewayNotification('TX-retry', true, 7999);
        $again = $this->registrations->applyGatewayNotification('TX-retry', true, 7999);
        self::assertFalse($again['applied']);
        self::assertTrue($again['amountMismatch']);
        self::assertSame('amount_mismatch', $this->rawGatewayTransaction('TX-retry')['status']);

        $final = $this->registrations->applyGatewayNotification('TX-retry', true, 8000);
        self::assertTrue($final['applied']);
        self::assertFalse($final['amountMismatch']);
        self::assertSame('confirmed', $this->registrations->find($registration['id'])['status']);
        self::assertSame('paid', $this->rawGatewayTransaction('TX-retry')['status']);
    }

    public function testPaidTransactionIgnoresLaterNotifications(): void
    {
        $registration = $this->registrationWithTransaction('TX-final');

        $this->registrations->applyGatewayNotification('TX-final', true, 8000);
        $later = $this->registrations->applyGatewayNotification('TX-final', true, 7999);

        self::assertFalse($later['applied']);
        self::assertFalse($later['amountMismatch']);
        self::assertSame('paid', $this->rawGatewayTransaction('TX-final')['status']);
        self::assertSame('confirmed', $this->registrations->find($registration['id'])['status']);
    }

    public function testNotPaidNotificationAfterMismatchMarksFailed(): void
    {
        $registration = $this->registrationWithTransaction('TX-mm-failed');

        $this->registrations->applyGatewayNotification('TX-mm-failed', true, 7999);
        $result = $this->registrations->applyGatewayNotification('TX-mm-failed', false, null);

        self::assertTrue($result['applied']);
        self::assertSame('failed', $this->rawGatewayTransaction('TX-mm-failed')['status']);
        self::assertSame('pending', $this->registrations->find($registration['id'])['status']);
    }

    public function testMismatchLogsOnceWithoutPii(): void
    {
        $registration = $this->registrationWithTransaction('TX-log');

        $previousErrorLog = ini_get('error_log');
        $logFile = tempnam(sys_get_temp_dir(), 'pp6-error-log-');
        ini_set('error_log', $logFile);
        try {
            $this->registrations->applyGatewayNotification('TX-log', true, 7999);
            $this->registrations->applyGatewayNotification('TX-log', true, 7999);
        } finally {
            ini_set('error_log', $previousErrorLog === false ? '' : $previousErrorLog);
        }
        $logged = (string) file_get_contents($logFile);
        @unlink($logFile);

        self::assertSame(1, substr_count($logged, 'Gateway amount mismatch'));
        self::assertStringContainsString('expectedGrosze=8000 notifiedGrosze=7999', $logged);
        self::assertStringContainsString('transactionId=TX-log', $logged);
        self::assertStringContainsString('registrationId=' . $registration['id'], $logged);
        self::assertStringNotContainsString('phpunit-participant@example.test', $logged);
        self::assertStringNotContainsString('PHPUnit User', $logged);
    }

    public function testPaidNotificationAfterExpiryRevivesWhenCapacityAllows(): void
    {
        [$logs, $registrations] = $this->mailingRepository();
        $registration = $this->registrationWithTransaction('TX-revive', $registrations);
        $this->backdateExpiry($registration['id']);
        $registrations->expireOverdueRegistrations(new \DateTimeImmutable());
        self::assertSame('expired', $this->rawStatus($registration['id']));
        self::assertSame(0, $this->rawRegisteredCount((string) $registration['distanceId']));

        $result = $registrations->applyGatewayNotification('TX-revive', true, 8000);

        self::assertTrue($result['applied']);
        self::assertFalse($result['paidAfterExpiry']);
        self::assertSame('confirmed', $this->rawStatus($registration['id']));
        self::assertSame(1, $this->rawRegisteredCount((string) $registration['distanceId']));
        self::assertSame('paid', $this->rawGatewayTransaction('TX-revive')['status']);
        self::assertTrue($registrations->find($registration['id'])['paymentDetails']['startFeePaid']);
        self::assertSame(1, $this->outboxRows($registration['id'], 'payment_confirmed'));
        self::assertTrue($logs->hasWarningThatContains('registration revived'));
    }

    public function testPaidNotificationAfterExpiryWithFullDistanceLeavesExpiredAlertsAndSendsNoMail(): void
    {
        [$logs, $registrations] = $this->mailingRepository();
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-full',
            'distances' => [['id' => $this->slugPrefix . '-full-10k', 'limit' => 1]],
        ]));
        $this->gateway->nextSession = ['transactionId' => 'TX-full', 'redirectUrl' => 'https://pay.example.test/full'];
        $first = $registrations->create($this->registrationPayload($event, 'phpunit-participant'));
        $this->backdateExpiry($first['id']);
        $registrations->expireOverdueRegistrations(new \DateTimeImmutable());
        self::assertSame('expired', $this->rawStatus($first['id']));

        // Someone else takes the freed slot (the Dystans limit is 1).
        $this->gateway->nextSession = ['transactionId' => 'TX-full-other', 'redirectUrl' => 'https://pay.example.test/other'];
        $second = $registrations->create($this->registrationPayload($event, 'phpunit-other'));
        self::assertSame('pending', $second['status']);

        $result = $registrations->applyGatewayNotification('TX-full', true, 8000);

        self::assertTrue($result['applied']);
        self::assertTrue($result['paidAfterExpiry']);
        self::assertSame('paid', $this->rawGatewayTransaction('TX-full')['status'], 'the money did arrive');
        self::assertSame('expired', $this->rawStatus($first['id']));
        self::assertSame(1, $this->rawRegisteredCount((string) $first['distanceId']), 'no over-capacity admission');
        self::assertSame(0, $this->outboxRows($first['id'], 'payment_confirmed'));

        $alerts = array_values(array_filter(
            $logs->getRecords(),
            static fn ($r): bool => ($r->context['alert'] ?? null) === 'payments.paid_after_expiry'
        ));
        self::assertCount(1, $alerts);
        self::assertSame(\Monolog\Level::Error, $alerts[0]->level);
        $encoded = (string) json_encode($alerts[0]->context);
        self::assertStringNotContainsString('@', $encoded, 'no e-mail address in the alert record');
        self::assertStringNotContainsString('PHPUnit User', $encoded);
    }

    public function testPaidNotificationForACancelledRegistrationSendsNoPaymentConfirmedMail(): void
    {
        [$logs, $registrations] = $this->mailingRepository();
        $registration = $this->registrationWithTransaction('TX-cancelled', $registrations);
        $this->pdo->prepare("UPDATE registrations SET status = 'cancelled' WHERE id = :id")->execute(['id' => $registration['id']]);

        $registrations->applyGatewayNotification('TX-cancelled', true, 8000);

        self::assertSame('cancelled', $this->rawStatus($registration['id']));
        self::assertSame(0, $this->outboxRows($registration['id'], 'payment_confirmed'));
        self::assertTrue($logs->hasErrorThatContains('not awaiting payment'));
    }

    /** @return array{0: \Monolog\Handler\TestHandler, 1: RegistrationRepository} */
    private function mailingRepository(): array
    {
        $logs = new \Monolog\Handler\TestHandler();
        $http = new FakeCentralMailerHttpClient();
        for ($i = 0; $i < 20; $i++) {
            $http->queueResponse(new HttpResponse(201, (string) json_encode(['id' => 'cm-' . $i, 'status' => 'pending'])));
        }
        $config = new CentralMailerConfig(apiKey: 'k', baseUrl: 'https://mailer.example.test');
        $outbox = new CentralMailerOutboxService(new CentralMailerClient($http, $config), new CentralMailerOutboxRepository($this->pdo));
        $mailer = new CriticalMailer($config, $outbox, new MailTemplateRepository($this->pdo));

        return [$logs, new RegistrationRepository(
            $this->pdo,
            $this->gateway,
            mailer: $mailer,
            logger: new \Monolog\Logger('test', [$logs])
        )];
    }

    private function backdateExpiry(string $registrationId): void
    {
        $this->pdo->prepare('UPDATE registrations SET payment_expires_at = :when WHERE id = :id')
            ->execute(['when' => (new \DateTimeImmutable('-2 hours'))->format(DATE_ATOM), 'id' => $registrationId]);
    }

    private function rawStatus(string $registrationId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $registrationId]);

        return (string) $stmt->fetchColumn();
    }

    private function rawRegisteredCount(string $distanceId): int
    {
        $stmt = $this->pdo->prepare('SELECT registered_count FROM event_distances WHERE id = :id');
        $stmt->execute(['id' => $distanceId]);

        return (int) $stmt->fetchColumn();
    }

    private function outboxRows(string $registrationId, string $mailType): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM central_mailer_outbox WHERE source_entity_type = :type AND source_entity_id = :id'
        );
        $stmt->execute(['type' => $mailType, 'id' => $registrationId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function registrationWithTransaction(string $transactionId, ?RegistrationRepository $repository = null): array
    {
        $repository ??= $this->registrations;
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-' . strtolower($transactionId)]));
        $this->gateway->nextSession = ['transactionId' => $transactionId, 'redirectUrl' => 'https://pay.example.test/' . $transactionId];
        $registration = $repository->create($this->registrationPayload($event));
        self::assertSame('pending', $registration['status']);
        self::assertSame('created', $this->rawGatewayTransaction($transactionId)['status']);

        return $registration;
    }

    public function testApproveRefundOnGatewayRouteCallsTheGatewaysRefundWithTheApprovedAmount(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-refund']));
        $this->gateway->nextSession = ['transactionId' => 'TX-refundable', 'redirectUrl' => 'https://pay.example.test/refundable'];
        $registration = $this->registrations->create($this->registrationPayload($event));
        $this->registrations->applyGatewayNotification('TX-refundable', true, 8000);

        $this->registrations->requestRefund($registration['id'], 'phpunit-participant');
        $this->registrations->approveRefund($registration['id'], 'phpunit-organizer', 30.0);

        self::assertCount(1, $this->gateway->refundCalls);
        self::assertSame('TX-refundable', $this->gateway->refundCalls[0]['transactionId']);
        self::assertSame(30.0, $this->gateway->refundCalls[0]['amount']);
    }

    /**
     * TD.13: $gateway->refund() throwing must no longer vanish silently. It
     * must (a) be logged, (b) not be reported to the caller as a plain
     * success, and (c) leave the refund cycle's persisted status exactly
     * where the pre-fix code already left it (`approved` — see
     * initiateGatewayRefund()'s docblock for why the state itself isn't
     * rolled back: there is no `failed` state in the migration-035 state
     * machine, and platform-side approval isn't supposed to flip-flop on a
     * gateway hiccup). This test forces the fake gateway's refund() call to
     * throw and asserts all three.
     */
    public function testApproveRefundLogsAndReportsAGatewayRefundFailureWithoutClaimingSuccess(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-refund-fail']));
        $this->gateway->nextSession = ['transactionId' => 'TX-refund-fail', 'redirectUrl' => 'https://pay.example.test/refund-fail'];
        $registration = $this->registrations->create($this->registrationPayload($event));
        $this->registrations->applyGatewayNotification('TX-refund-fail', true, 8000);
        $this->registrations->requestRefund($registration['id'], 'phpunit-participant');

        $this->gateway->throwOnRefund = true;

        $previousErrorLog = ini_get('error_log');
        $logFile = tempnam(sys_get_temp_dir(), 'td13-error-log-');
        ini_set('error_log', $logFile);
        try {
            $result = $this->registrations->approveRefund($registration['id'], 'phpunit-organizer', 30.0);
        } finally {
            ini_set('error_log', $previousErrorLog === false ? '' : $previousErrorLog);
        }
        $logged = (string) file_get_contents($logFile);
        @unlink($logFile);

        // (a) the failure is actually logged, with enough to investigate it.
        self::assertStringContainsString('initiateGatewayRefund', $logged);
        self::assertStringContainsString($registration['id'], $logged);
        self::assertStringContainsString('TX-refund-fail', $logged);
        self::assertStringContainsString('fake_gateway_refund_unreachable', $logged);

        // (c) the caller gets an honest signal, not a silent "it worked".
        self::assertTrue($result['refund']['gatewayRefundError'] ?? false);

        // (b) the gateway call really was attempted with the approved amount.
        self::assertCount(1, $this->gateway->refundCalls);
        self::assertSame(30.0, $this->gateway->refundCalls[0]['amount']);

        // The refund cycle is left exactly where the pre-fix code already
        // left it (no new, worse state introduced by this fix): `approved`,
        // not silently promoted to `refunded` despite the gateway call
        // failing, and not left dangling as `requested` either.
        self::assertSame('approved', $result['refund']['status']);
        self::assertSame(30.0, $result['refund']['amount']);
    }

    public function testApproveRefundOnOrganizerBankRouteNeverCallsTheGateway(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-refund-bank',
            'paymentConfig' => ['type' => 'organizer_bank'],
        ]));
        $payload = $this->registrationPayload($event);
        $payload['paymentDetails'] = ['startFeePaid' => true];
        $registration = $this->registrations->create($payload);

        $this->registrations->requestRefund($registration['id'], 'phpunit-participant');
        $this->registrations->approveRefund($registration['id'], 'phpunit-organizer');

        self::assertCount(0, $this->gateway->refundCalls);
    }

    /** @return array<string, mixed> */
    private function rawGatewayTransaction(string $transactionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT transaction_id AS transactionId, registration_id AS registrationId, status, amount
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
            'name' => 'PHPUnit Tpay Event',
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
        $stmt->execute(['slugLike' => 'phptpay-%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $eventId) {
            $regStmt = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :eventId');
            $regStmt->execute(['eventId' => $eventId]);
            foreach ($regStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $registrationId) {
                $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE source_entity_id = :id')->execute(['id' => $registrationId]);
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

/** Test double for PaymentGatewayInterface used only by this file's tests. */
final class FakeGateway implements PaymentGatewayInterface
{
    /** @var array{transactionId: string, redirectUrl: string} */
    public array $nextSession = ['transactionId' => 'TX-default', 'redirectUrl' => 'https://pay.example.test/default'];
    public bool $throwOnStartSession = false;
    public bool $throwOnRefund = false;

    /** @var array<int, array{registrationId: string, amount: float}> */
    public array $startSessionCalls = [];

    /** @var array<int, array{transactionId: string, amount: float}> */
    public array $refundCalls = [];

    public function startSession(string $registrationId, float $amount, string $description, string $payerEmail, string $payerName): array
    {
        $this->startSessionCalls[] = ['registrationId' => $registrationId, 'amount' => $amount];
        if ($this->throwOnStartSession) {
            throw new \RuntimeException('fake_gateway_unreachable');
        }

        return $this->nextSession;
    }

    public function refund(string $transactionId, float $amount): void
    {
        $this->refundCalls[] = ['transactionId' => $transactionId, 'amount' => $amount];
        if ($this->throwOnRefund) {
            throw new \RuntimeException('fake_gateway_refund_unreachable');
        }
    }
}

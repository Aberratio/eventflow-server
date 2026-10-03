<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Log;

use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerClient;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;
use ZmierzymyCzas\Domain\Mailer\Http\HttpResponse;
use ZmierzymyCzas\Support\Log\AlertHandler;
use ZmierzymyCzas\Support\Log\AlertThrottle;
use ZmierzymyCzas\Support\Log\LogConfig;
use ZmierzymyCzas\Support\Log\LoggerFactory;
use ZmierzymyCzas\Support\Log\PiiRedactingProcessor;
use ZmierzymyCzas\Tests\Support\FakeCentralMailerHttpClient;

final class AlertHandlerTest extends TestCase
{
    private const RECIPIENT = 'ops@example.test';
    private const WINDOW = 900;

    private string $tmp;
    private FakeCentralMailerHttpClient $http;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zc-pp20-alert-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
        $this->http = new FakeCentralMailerHttpClient();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    private function removeDir(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeDir($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    }

    public function testSendsOneTechnicalMailForAnAlertRecord(): void
    {
        $this->queueOk(1);
        $logger = $this->logger();

        $logger->error('Gateway refund call failed', ['alert' => 'payments.refund_failed']);

        self::assertCount(1, $this->http->calls);
        $call = $this->http->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertStringEndsWith('/emails', $call['url']);
        $body = json_decode((string) $call['body'], true);
        self::assertSame('technical', $body['priority']);
        self::assertSame(self::RECIPIENT, $body['to']);
        self::assertSame('[ZC production] ALERT payments.refund_failed', $body['subject']);
        self::assertStringStartsWith('zc-alert:payments.refund_failed:', $call['headers']['Idempotency-Key']);
    }

    public function testThrottlesSameKeyWithinWindowAndReportsSuppressedCount(): void
    {
        $this->queueOk(2);
        $logger = $this->logger();

        foreach ([0, 10, 20] as $offset) {
            $this->now = 1_800_000_000 + $offset;
            $logger->error('boom', ['alert' => 'http.server_error']);
        }
        self::assertCount(1, $this->http->calls);

        $this->now = 1_800_000_000 + self::WINDOW + 1;
        $logger->error('boom again', ['alert' => 'http.server_error']);

        self::assertCount(2, $this->http->calls);
        $second = json_decode((string) $this->http->calls[1]['body'], true);
        self::assertStringContainsString('Suppressed since last alert: 2', $second['text']);
    }

    public function testDifferentKeysAreThrottledIndependently(): void
    {
        $this->queueOk(2);
        $logger = $this->logger();

        $logger->error('a', ['alert' => 'payments.amount_mismatch']);
        $logger->error('b', ['alert' => 'db.unavailable']);

        self::assertCount(2, $this->http->calls);
    }

    public function testIgnoresRecordsWithoutAlertKeyOrBelowError(): void
    {
        $logger = $this->logger();

        $logger->warning('below error', ['alert' => 'db.unavailable']);
        $logger->error('no key');

        self::assertCount(0, $this->http->calls);
    }

    public function testAlertBodyIsRedacted(): void
    {
        $this->queueOk(1);
        $logger = $this->logger();

        $logger->error('refund failed for jan@example.test', ['alert' => 'payments.refund_failed']);

        $body = json_decode((string) $this->http->calls[0]['body'], true);
        foreach (['text', 'html'] as $field) {
            self::assertStringContainsString('[email]', $body[$field]);
            self::assertStringNotContainsString('jan@example.test', $body[$field]);
        }
    }

    public function testMailerFailureIsSwallowed(): void
    {
        $this->http->queueResponse(new HttpResponse(500, 'upstream down'));
        $logger = $this->logger();
        $previous = ini_set('error_log', $this->tmp . DIRECTORY_SEPARATOR . 'php-error.log');

        try {
            $logger->error('boom', ['alert' => 'http.server_error']);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        self::assertCount(1, $this->http->calls);
        $log = (string) file_get_contents($this->tmp . DIRECTORY_SEPARATOR . 'php-error.log');
        self::assertStringContainsString('Alert e-mail failed for http.server_error', $log);
        self::assertStringNotContainsString('upstream down', $log);
    }

    public function testUnwritableThrottleFileSendsNothing(): void
    {
        $logger = $this->logger($this->tmp . DIRECTORY_SEPARATOR . 'missing-dir' . DIRECTORY_SEPARATOR . 'throttle.json');

        $logger->error('boom', ['alert' => 'http.server_error']);

        self::assertCount(0, $this->http->calls);
    }

    public function testFactoryAttachesAlertsOnlyWhenEmailAndMailerAreConfigured(): void
    {
        $this->queueOk(1);
        $mailerConfig = new CentralMailerConfig('key', 'https://mailer.example.test');
        $client = new CentralMailerClient($this->http, $mailerConfig);
        $logDir = $this->tmp . DIRECTORY_SEPARATOR . 'logs';

        $off = LoggerFactory::create(LogConfig::fromArray(['APP_ENV' => 'production', 'LOG_DIR' => $logDir]), $client, $mailerConfig);
        $off->error('x', ['alert' => 'http.server_error']);
        self::assertCount(0, $this->http->calls, 'no ALERT_EMAIL: log only');

        $unconfigured = new CentralMailerConfig('', '');
        $noMailer = LoggerFactory::create(
            LogConfig::fromArray(['APP_ENV' => 'production', 'LOG_DIR' => $logDir, 'ALERT_EMAIL' => self::RECIPIENT]),
            $client,
            $unconfigured
        );
        $noMailer->error('x', ['alert' => 'http.server_error']);
        self::assertCount(0, $this->http->calls, 'Central Mailer unconfigured: log only');

        $on = LoggerFactory::create(
            LogConfig::fromArray(['APP_ENV' => 'production', 'LOG_DIR' => $logDir, 'ALERT_EMAIL' => self::RECIPIENT]),
            $client,
            $mailerConfig
        );
        $on->error('x', ['alert' => 'http.server_error']);
        self::assertCount(1, $this->http->calls);
    }

    private function queueOk(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->http->queueResponse(new HttpResponse(201, (string) json_encode(['id' => 'em-' . $i, 'status' => 'pending'])));
        }
    }

    private function logger(?string $throttleFile = null): Logger
    {
        $client = new CentralMailerClient($this->http, new CentralMailerConfig('key', 'https://mailer.example.test'));
        $handler = new AlertHandler(
            $client,
            self::RECIPIENT,
            new AlertThrottle($throttleFile ?? $this->tmp . DIRECTORY_SEPARATOR . 'throttle.json', self::WINDOW),
            'production',
            $this->tmp,
            fn (): int => $this->now,
        );
        $logger = new Logger('test', [$handler]);
        $logger->pushProcessor(new UidProcessor(16));
        $logger->pushProcessor(new PiiRedactingProcessor());

        return $logger;
    }
}

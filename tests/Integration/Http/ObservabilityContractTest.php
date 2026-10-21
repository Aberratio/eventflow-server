<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Monolog\Formatter\JsonFormatter;
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
use ZmierzymyCzas\Domain\Payments\PayNow\PayNowSignature;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Http\HttpMiddlewareStack;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Support\Log\PiiRedactingProcessor;
use ZmierzymyCzas\Support\Uuid;
use ZmierzymyCzas\Tests\Support\FaultInjectingPdo;

/**
 * Builds its own app (real middleware stack + real routes.php, a TestHandler
 * logger carrying the real redactor) instead of extending HttpTestCase. A DB that is not
 * reachable is a hard failure here, never a skip.
 */
final class ObservabilityContractTest extends TestCase
{
    private const PAYNOW_KEY = 'phpunit-pp20-signature-key';

    private TestHandler $logs;
    private Logger $logger;
    private JwtService $jwt;
    private ?string $userId = null;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        Env::set('PAYNOW_SIGNATURE_KEY', self::PAYNOW_KEY);

        $this->logs = new TestHandler();
        $this->logger = new Logger('test', [$this->logs]);
        $this->logger->pushProcessor(new PiiRedactingProcessor());
        $this->jwt = new JwtService('phpunit-http-secret-at-least-32-bytes', 3600);

        try {
            $this->realPdo();
        } catch (\Throwable $e) {
            self::fail('Database is not available for ObservabilityContractTest: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        Env::restore('PAYNOW_SIGNATURE_KEY');
        if ($this->userId !== null) {
            $this->realPdo()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->userId]);
        }
    }

    public function testHealthChecksTheDatabase(): void
    {
        $response = $this->send($this->buildApp($this->realPdo()), 'GET', '/api/v1/health');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $payload['data']['status']);
        self::assertSame('ok', $payload['data']['checks']['database']);
        self::assertArrayHasKey('version', $payload['data']);
        self::assertArrayHasKey('commit', $payload['data']);
        self::assertArrayNotHasKey('env', $payload['data']);
        self::assertArrayNotHasKey('url', $payload['data']);
    }

    public function testHealthIs503WithoutDetailsWhenTheDatabaseFails(): void
    {
        $pdo = new FaultInjectingPdo(
            'SELECT 1',
            new \PDOException("SQLSTATE[HY000] [2002] Connection refused for user 'zc'@'db.internal'")
        );

        $response = $this->send($this->buildApp($pdo), 'GET', '/api/v1/health');
        $raw = (string) $response->getBody();
        $payload = (array) json_decode($raw, true);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('service_unavailable', $payload['error']['code']);
        self::assertArrayNotHasKey('details', $payload['error']);
        self::assertStringNotContainsString('SQLSTATE', $raw);
        self::assertStringNotContainsString('db.internal', $raw);

        $errors = $this->logs->getRecords();
        self::assertCount(1, $errors);
        self::assertTrue($errors[0]->level->includes(Level::Error));
        self::assertSame('db.unavailable', $errors[0]->context['alert']);
    }

    public function testTpayInvalidSignatureIsLoggedWithoutBodyOrSignature(): void
    {
        $response = $this->postRaw(
            $this->buildApp($this->realPdo()),
            '/api/v1/webhooks/tpay',
            '{"tr_email":"jan@example.test"}',
            ['X-JWS-Signature' => 'bogus.sig.value']
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_signature', $this->json($response)['error']['code']);

        $records = $this->logs->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Warning, $records[0]->level);
        self::assertSame('Payment webhook invalid_signature', $records[0]->message);
        self::assertSame('tpay', $records[0]->context['gateway']);
        $formatted = $this->formattedLog();
        self::assertStringNotContainsString('jan@example.test', $formatted);
        self::assertStringNotContainsString('bogus.sig.value', $formatted);
    }

    public function testPayNowUnknownTransactionIsLogged(): void
    {
        $body = (string) json_encode([
            'paymentId' => 'PN-UNKNOWN-' . bin2hex(random_bytes(4)),
            'externalId' => 'ext',
            'status' => 'CONFIRMED',
            'modifiedAt' => '2026-10-03T10:00:00',
        ]);

        $response = $this->postRaw(
            $this->buildApp($this->realPdo()),
            '/api/v1/webhooks/paynow',
            $body,
            ['Signature' => PayNowSignature::compute($body, self::PAYNOW_KEY)]
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('gateway_transaction_unknown', $this->json($response)['error']['code']);
        $records = $this->logs->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Warning, $records[0]->level);
        self::assertSame('Payment webhook unknown_transaction', $records[0]->message);
        self::assertSame('paynow', $records[0]->context['gateway']);
    }

    public function testPayNowInvalidPayloadIsLogged(): void
    {
        $body = 'not-json';

        $response = $this->postRaw(
            $this->buildApp($this->realPdo()),
            '/api/v1/webhooks/paynow',
            $body,
            ['Signature' => PayNowSignature::compute($body, self::PAYNOW_KEY)]
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_payload', $this->json($response)['error']['code']);
        $records = $this->logs->getRecords();
        self::assertCount(1, $records);
        self::assertSame('invalid_payload', $records[0]->context['outcome']);
    }

    public function testDatabaseFailureInsideADomainCatchIsAGeneric500AndIsLogged(): void
    {
        $pdo = new FaultInjectingPdo(
            'result_import_rows',
            new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'secret_col' in 'field list'")
        );

        $response = $this->send(
            $this->buildApp($pdo),
            'PATCH',
            '/api/v1/result-import-rows/' . Uuid::v4(),
            ['Authorization' => $this->bearer('organizer')]
        );
        $raw = (string) $response->getBody();
        $payload = (array) json_decode($raw, true);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('server_error', $payload['error']['code']);
        self::assertStringNotContainsString('SQLSTATE', $raw);
        self::assertStringNotContainsString('secret_col', $raw);

        $records = $this->logs->getRecords();
        self::assertCount(1, $records);
        self::assertSame('Unhandled server error', $records[0]->message);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertSame('http.server_error', $records[0]->context['alert']);
    }

    public function testDomainCodeStillMapsAfterTheGuard(): void
    {
        $response = $this->send(
            $this->buildApp($this->realPdo()),
            'PATCH',
            '/api/v1/result-import-rows/' . Uuid::v4(),
            ['Authorization' => $this->bearer('organizer')]
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->json($response)['error']['code']);
        self::assertCount(0, $this->logs->getRecords());
    }

    private function realPdo(): PDO
    {
        return Connection::fromEnv();
    }

    private function buildApp(PDO $pdo): App
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
        (require __DIR__ . '/../../../src/Http/routes.php')($app, $pdo, $this->jwt, $this->logger);

        return $app;
    }

    /** @param array<string, string> $headers */
    private function send(App $app, string $method, string $path, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    /** The session guard needs a real user row (a token for an unknown sub is a 401). */
    private function bearer(string $role): string
    {
        if ($this->userId === null) {
            $user = (new UserRepository($this->realPdo()))->create([
                'name' => 'PP20 User',
                'email' => 'pp20-' . bin2hex(random_bytes(4)) . '@example.test',
                'password' => 'demo1234',
                'role' => $role,
            ]);
            $this->userId = (string) $user['id'];
        }

        return 'Bearer ' . $this->jwt->issue(['sub' => $this->userId, 'role' => $role, 'email' => 'phpunit@example.test', 'tv' => 0]);
    }

    /** @param array<string, string> $headers */
    private function postRaw(App $app, string $path, string $rawBody, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withBody((new StreamFactory())->createStream($rawBody))
            ->withHeader('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);

        return $payload;
    }

    private function formattedLog(): string
    {
        $formatter = new JsonFormatter();
        $out = '';
        foreach ($this->logs->getRecords() as $record) {
            $out .= $formatter->format($record);
        }

        return $out;
    }
}

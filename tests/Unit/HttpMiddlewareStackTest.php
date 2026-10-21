<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use ZmierzymyCzas\Config\AppConfig;
use ZmierzymyCzas\Http\HttpMiddlewareStack;

final class HttpMiddlewareStackTest extends TestCase
{
    private function buildApp(AppConfig $config, ?\Psr\Log\LoggerInterface $logger = null): App
    {
        $app = AppFactory::create();
        $app->setBasePath('');

        HttpMiddlewareStack::register($app, $config, $logger);

        $app->get('/ok', function ($request, $response) {
            $response->getBody()->write('{"success":true}');
            return $response->withHeader('Content-Type', 'application/json');
        });
        $app->get('/boom', function ($request, $response) {
            throw new RuntimeException('boom');
        });

        return $app;
    }

    private function localConfig(): AppConfig
    {
        return AppConfig::fromEnvironment([
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'false',
            'APP_CORS_ORIGIN' => 'http://localhost:5173',
            'JWT_SECRET' => str_repeat('a', 32),
            'JWT_TTL_SECONDS' => '86400',
            'TPAY_SANDBOX' => 'true',
            'PAYNOW_SANDBOX' => 'true',
        ]);
    }

    private function stagingConfig(): AppConfig
    {
        return AppConfig::fromEnvironment([
            'APP_ENV' => 'staging',
            'APP_DEBUG' => 'false',
            'APP_CORS_ORIGIN' => 'https://front.example.test',
            'JWT_SECRET' => str_repeat('b', 32),
            'JWT_TTL_SECONDS' => '86400',
            'TPAY_SANDBOX' => 'true',
            'PAYNOW_SANDBOX' => 'true',
        ]);
    }

    public function testPreflightAllowsEveryRoutedMethod(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/ok')
            ->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Access-Control-Request-Method', 'PUT');
        $response = $this->buildApp($this->localConfig())->handle($request);

        $allowed = array_map('trim', explode(',', $response->getHeaderLine('Access-Control-Allow-Methods')));
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            self::assertContains($method, $allowed);
        }
    }

    /** @return array<string, mixed> */
    private function errorBody(string $env, string $debug, string $path): array
    {
        $config = AppConfig::fromEnvironment([
            'APP_ENV' => $env,
            'APP_DEBUG' => $debug,
            'APP_CORS_ORIGIN' => 'http://localhost:5173',
            'JWT_SECRET' => str_repeat('c', 32),
            'JWT_TTL_SECONDS' => '86400',
            'TPAY_SANDBOX' => 'true',
            'PAYNOW_SANDBOX' => 'true',
        ]);
        $response = $this->buildApp($config)->handle((new ServerRequestFactory())->createServerRequest('GET', $path));

        return (array) json_decode((string) $response->getBody(), true);
    }

    public function testLocalDebugExposesExceptionDetailsOn404And500(): void
    {
        foreach (['/nope', '/boom'] as $path) {
            $body = $this->errorBody('local', 'true', $path);
            self::assertArrayHasKey('trace', $body['error']['details'], $path);
        }
        self::assertSame('boom', $this->errorBody('local', 'true', '/boom')['error']['message']);
    }

    public function testNonLocalEnvsNeverExposeDetailsEvenWithDebugOn(): void
    {
        foreach (['dev', 'staging'] as $env) {
            foreach (['/nope', '/boom'] as $path) {
                $body = $this->errorBody($env, 'true', $path);
                self::assertArrayNotHasKey('details', $body['error'], "{$env} {$path}");
                self::assertStringNotContainsString('trace', json_encode($body), "{$env} {$path}");
                self::assertStringNotContainsString('boom', json_encode($body), "{$env} {$path}");
            }
        }
    }

    public function testLocalWithoutDebugExposesNoDetails(): void
    {
        $body = $this->errorBody('local', 'false', '/boom');
        self::assertArrayNotHasKey('details', $body['error']);
        self::assertSame('server_error', $body['error']['code']);
    }

    private function assertHeadersPresent(\Psr\Http\Message\ResponseInterface $response): void
    {
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertNotSame('', $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testHeadersOnSuccessResponse(): void
    {
        $app = $this->buildApp($this->localConfig());
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/ok');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $this->assertHeadersPresent($response);
    }

    public function testHeadersOnNotFound(): void
    {
        $app = $this->buildApp($this->localConfig());
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/nope');
        $response = $app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $this->assertHeadersPresent($response);
    }

    public function testHeadersOnUnhandledException(): void
    {
        $app = $this->buildApp($this->localConfig());
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/boom');
        $response = $app->handle($request);

        self::assertSame(500, $response->getStatusCode());
        $this->assertHeadersPresent($response);
    }

    public function testHeadersOnCorsPreflight(): void
    {
        $app = $this->buildApp($this->localConfig());
        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/ok')
            ->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Access-Control-Request-Method', 'GET');
        $response = $app->handle($request);

        $this->assertHeadersPresent($response);
        self::assertSame('http://localhost:5173', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testHstsFollowsAppEnv(): void
    {
        $localApp = $this->buildApp($this->localConfig());
        $localResponse = $localApp->handle((new ServerRequestFactory())->createServerRequest('GET', '/ok'));
        self::assertFalse($localResponse->hasHeader('Strict-Transport-Security'));

        $stagingApp = $this->buildApp($this->stagingConfig());
        $stagingResponse = $stagingApp->handle((new ServerRequestFactory())->createServerRequest('GET', '/ok'));
        self::assertTrue($stagingResponse->hasHeader('Strict-Transport-Security'));
    }

    public function testInjectedLoggerReceivesServerErrorsThroughTheStack(): void
    {
        $handler = new TestHandler();
        $app = $this->buildApp($this->localConfig(), new Logger('test', [$handler]));

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/boom'));

        self::assertSame(500, $response->getStatusCode());
        self::assertTrue($handler->hasErrorRecords());
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use ZmierzymyCzas\Config\AppConfig;
use ZmierzymyCzas\Http\HttpMiddlewareStack;

final class HttpMiddlewareStackTest extends TestCase
{
    private function buildApp(AppConfig $config): App
    {
        $app = AppFactory::create();
        $app->setBasePath('');

        HttpMiddlewareStack::register($app, $config);

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
}

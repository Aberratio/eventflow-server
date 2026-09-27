<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;
use ZmierzymyCzas\Http\Middleware\CorsMiddleware;

final class CorsMiddlewareTest extends TestCase
{
    private function handler(bool &$wasCalled, ?callable $responseBuilder = null): RequestHandlerInterface
    {
        return new class ($wasCalled, $responseBuilder) implements RequestHandlerInterface {
            private bool $called = false;

            public function __construct(private bool &$wasCalled, private $responseBuilder)
            {
            }

            public function handle(Request $request): Response
            {
                $this->wasCalled = true;
                $response = new SlimResponse();
                if ($this->responseBuilder !== null) {
                    $response = ($this->responseBuilder)($response);
                }

                return $response;
            }
        };
    }

    public function testAllowedOriginIsEchoed(): void
    {
        $middleware = new CorsMiddleware('https://a.pl,https://b.pl');
        $called = false;
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/health')
            ->withHeader('Origin', 'https://b.pl');

        $response = $middleware->process($request, $this->handler($called));

        self::assertSame('https://b.pl', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertNotSame('', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertTrue($called);
    }

    public function testForeignOriginGetsNoCorsHeaders(): void
    {
        $middleware = new CorsMiddleware('https://a.pl,https://b.pl');
        $called = false;
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/health')
            ->withHeader('Origin', 'https://evil.example');

        $response = $middleware->process($request, $this->handler($called));

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Methods'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Headers'));
        self::assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
    }

    public function testPreflightFromForeignOriginIsNotAllowed(): void
    {
        $middleware = new CorsMiddleware('https://a.pl');
        $called = false;
        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/api/v1/auth/login')
            ->withHeader('Origin', 'https://evil.example');

        $response = $middleware->process($request, $this->handler($called));

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($called);
    }

    public function testPreflightFromAllowedOriginSkipsHandler(): void
    {
        $middleware = new CorsMiddleware('https://a.pl');
        $called = false;
        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/api/v1/auth/login')
            ->withHeader('Origin', 'https://a.pl');

        $response = $middleware->process($request, $this->handler($called));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://a.pl', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($called);
    }

    public function testWildcardNeverSendsCredentials(): void
    {
        $middleware = new CorsMiddleware('*');
        $called = false;
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/health')
            ->withHeader('Origin', 'https://anything.example');

        $response = $middleware->process($request, $this->handler($called));

        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    public function testExplicitOriginNeverSendsCredentials(): void
    {
        $middleware = new CorsMiddleware('https://a.pl');
        $called = false;
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/health')
            ->withHeader('Origin', 'https://a.pl');

        $response = $middleware->process($request, $this->handler($called));

        self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    public function testRequestWithoutOriginGetsNoAllowOrigin(): void
    {
        $middleware = new CorsMiddleware('https://a.pl');
        $called = false;
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/health');

        $response = $middleware->process($request, $this->handler($called));

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testVaryFromHandlerIsPreserved(): void
    {
        $middleware = new CorsMiddleware('https://a.pl');
        $called = false;
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/health')
            ->withHeader('Origin', 'https://a.pl');

        $response = $middleware->process(
            $request,
            $this->handler($called, fn (Response $r) => $r->withHeader('Vary', 'Accept-Encoding'))
        );

        $vary = $response->getHeader('Vary');
        $joined = implode(',', $vary);
        self::assertStringContainsString('Accept-Encoding', $joined);
        self::assertStringContainsString('Origin', $joined);
    }
}

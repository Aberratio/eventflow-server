<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;
use ZmierzymyCzas\Http\Middleware\SecurityHeadersMiddleware;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    private function handler(?callable $responseBuilder = null): RequestHandlerInterface
    {
        return new class ($responseBuilder) implements RequestHandlerInterface {
            public function __construct(private $responseBuilder)
            {
            }

            public function handle(Request $request): Response
            {
                $response = new SlimResponse();
                if ($this->responseBuilder !== null) {
                    $response = ($this->responseBuilder)($response);
                }

                return $response;
            }
        };
    }

    private function request(): Request
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/health');
    }

    public function testAddsBaselineHeadersToHandlerResponse(): void
    {
        $middleware = new SecurityHeadersMiddleware(false);
        $response = $middleware->process($this->request(), $this->handler());

        self::assertSame(
            SecurityHeadersMiddleware::CONTENT_SECURITY_POLICY,
            $response->getHeaderLine('Content-Security-Policy')
        );
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame(
            SecurityHeadersMiddleware::PERMISSIONS_POLICY,
            $response->getHeaderLine('Permissions-Policy')
        );
    }

    public function testDoesNotSendHstsWhenDisabled(): void
    {
        $middleware = new SecurityHeadersMiddleware(false);
        $response = $middleware->process($this->request(), $this->handler());

        self::assertFalse($response->hasHeader('Strict-Transport-Security'));
    }

    public function testSendsHstsWhenEnabled(): void
    {
        $middleware = new SecurityHeadersMiddleware(true);
        $response = $middleware->process($this->request(), $this->handler());

        self::assertSame(SecurityHeadersMiddleware::HSTS, $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function testAddsHeadersToErrorStatusResponses(): void
    {
        $middleware = new SecurityHeadersMiddleware(false);

        $notFound = $middleware->process(
            $this->request(),
            $this->handler(static fn (Response $r) => $r->withStatus(404))
        );
        self::assertSame(404, $notFound->getStatusCode());
        self::assertSame('nosniff', $notFound->getHeaderLine('X-Content-Type-Options'));

        $serverError = $middleware->process(
            $this->request(),
            $this->handler(static fn (Response $r) => $r->withStatus(500))
        );
        self::assertSame(500, $serverError->getStatusCode());
        self::assertSame('nosniff', $serverError->getHeaderLine('X-Content-Type-Options'));
    }

    public function testKeepsHeaderAlreadySetByRoute(): void
    {
        $middleware = new SecurityHeadersMiddleware(false);
        $response = $middleware->process(
            $this->request(),
            $this->handler(static fn (Response $r) => $r->withHeader('Content-Security-Policy', "default-src 'self'"))
        );

        self::assertSame("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testBaselineHeadersHelperMatchesMiddleware(): void
    {
        $middleware = new SecurityHeadersMiddleware(true);
        $response = $middleware->process($this->request(), $this->handler());

        $helperKeys = array_keys(SecurityHeadersMiddleware::baselineHeaders(true));
        $responseKeys = array_keys($response->getHeaders());

        sort($helperKeys);
        sort($responseKeys);

        self::assertSame($helperKeys, $responseKeys);
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use ZmierzymyCzas\Domain\Auth\JwtService;
use ZmierzymyCzas\Http\Middleware\AuthMiddleware;

final class AuthMiddlewareTest extends TestCase
{
    private JwtService $jwt;

    protected function setUp(): void
    {
        $this->jwt = new JwtService('test-secret-at-least-32-bytes-long', 3600);
    }

    public function testMissingAuthorizationHeaderReturns401(): void
    {
        $middleware = new AuthMiddleware($this->jwt);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/protected');

        $response = $middleware->process($request, $this->noOpHandler());

        self::assertSame(401, $response->getStatusCode());
        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('unauthorized', $body['error']['code']);
    }

    public function testInvalidTokenReturns401(): void
    {
        $middleware = new AuthMiddleware($this->jwt);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/protected')
            ->withHeader('Authorization', 'Bearer not.a.valid.jwt');

        $response = $middleware->process($request, $this->noOpHandler());

        self::assertSame(401, $response->getStatusCode());
        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertSame('unauthorized', $body['error']['code']);
    }

    public function testValidTokenWithoutRoleRequirementPassesThrough(): void
    {
        $middleware = new AuthMiddleware($this->jwt);
        $token = $this->jwt->issue(['sub' => 'u-123', 'role' => 'participant']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/me')
            ->withHeader('Authorization', 'Bearer ' . $token);

        $capturedAuth = null;
        $handler = $this->capturingHandler(function ($req) use (&$capturedAuth) {
            $capturedAuth = $req->getAttribute('auth');
        });

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($capturedAuth);
        self::assertSame('u-123', $capturedAuth['sub']);
        self::assertSame('participant', $capturedAuth['role']);
    }

    public function testValidTokenWithWrongRoleReturns403(): void
    {
        $middleware = new AuthMiddleware($this->jwt, ['admin', 'superadmin']);
        $token = $this->jwt->issue(['sub' => 'u-part', 'role' => 'participant']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/users')
            ->withHeader('Authorization', 'Bearer ' . $token);

        $response = $middleware->process($request, $this->noOpHandler());

        self::assertSame(403, $response->getStatusCode());
        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertSame('forbidden', $body['error']['code']);
    }

    public function testValidTokenWithCorrectRolePassesThrough(): void
    {
        $middleware = new AuthMiddleware($this->jwt, ['admin', 'superadmin']);
        $token = $this->jwt->issue(['sub' => 'u-admin', 'role' => 'admin']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/users')
            ->withHeader('Authorization', 'Bearer ' . $token);

        $response = $middleware->process($request, $this->noOpHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testSuperadminPassesWhenAdminRoleIsRequired(): void
    {
        // superadmin inherits admin permissions
        $middleware = new AuthMiddleware($this->jwt, ['admin']);
        $token = $this->jwt->issue(['sub' => 'u-super', 'role' => 'superadmin']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/users')
            ->withHeader('Authorization', 'Bearer ' . $token);

        $response = $middleware->process($request, $this->noOpHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testBearerPrefixIsCaseInsensitive(): void
    {
        $middleware = new AuthMiddleware($this->jwt);
        $token = $this->jwt->issue(['sub' => 'u-1', 'role' => 'participant']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/me')
            ->withHeader('Authorization', 'bearer ' . $token);

        $response = $middleware->process($request, $this->noOpHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    private function noOpHandler(): RequestHandlerInterface
    {
        return new class extends \stdClass implements RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    private function capturingHandler(callable $callback): RequestHandlerInterface
    {
        return new class ($callback) implements RequestHandlerInterface {
            public function __construct(private readonly \Closure $callback) {}

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                ($this->callback)($request);
                return new Response(200);
            }
        };
    }
}

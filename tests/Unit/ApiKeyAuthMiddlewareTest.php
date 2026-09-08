<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use ZmierzymyCzas\Domain\Auth\ApplicationApiKeyConfig;
use ZmierzymyCzas\Http\Middleware\ApiKeyAuthMiddleware;

final class ApiKeyAuthMiddlewareTest extends TestCase
{
    public function testMissingApiKeyHeaderReturns401(): void
    {
        $middleware = new ApiKeyAuthMiddleware(new ApplicationApiKeyConfig(['biuro_zawodow' => 'secret-bz']));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/whatever');

        $response = $middleware->process($request, $this->noOpHandler());

        self::assertSame(401, $response->getStatusCode());
        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('unauthorized', $body['error']['code']);
    }

    public function testEmptyApiKeyHeaderReturns401(): void
    {
        $middleware = new ApiKeyAuthMiddleware(new ApplicationApiKeyConfig(['biuro_zawodow' => 'secret-bz']));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/whatever')
            ->withHeader('X-API-Key', '');

        $response = $middleware->process($request, $this->noOpHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function testUnknownApiKeyReturns401WithGenericMessage(): void
    {
        $middleware = new ApiKeyAuthMiddleware(new ApplicationApiKeyConfig(['biuro_zawodow' => 'secret-bz']));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/whatever')
            ->withHeader('X-API-Key', 'totally-made-up-key');

        $response = $middleware->process($request, $this->noOpHandler());

        self::assertSame(401, $response->getStatusCode());
        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertSame('unauthorized', $body['error']['code']);
        // The failure message must not hint at which keys exist or why the
        // check failed.
        self::assertStringNotContainsStringIgnoringCase('biuro_zawodow', (string) $response->getBody());
        self::assertStringNotContainsStringIgnoringCase('secret-bz', (string) $response->getBody());
    }

    public function testNoApplicationsConfiguredRejectsEvenAPreviouslyValidLookingKey(): void
    {
        // The critical fail-closed case: empty/unset .env (local dev, CI) must
        // not silently authorize every caller.
        $middleware = new ApiKeyAuthMiddleware(new ApplicationApiKeyConfig([]));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/whatever')
            ->withHeader('X-API-Key', 'secret-bz');

        $response = $middleware->process($request, $this->noOpHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    public function testValidKeyPassesThroughAndResolvesApplicationAttribute(): void
    {
        $middleware = new ApiKeyAuthMiddleware(new ApplicationApiKeyConfig(['biuro_zawodow' => 'secret-bz']));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/whatever')
            ->withHeader('X-API-Key', 'secret-bz');

        $capturedApplication = null;
        $handler = $this->capturingHandler(function ($req) use (&$capturedApplication) {
            $capturedApplication = $req->getAttribute('application');
        });

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('biuro_zawodow', $capturedApplication);
    }

    public function testTwoConfiguredApplicationsResolveToDistinctIdentities(): void
    {
        $config = new ApplicationApiKeyConfig([
            'biuro_zawodow' => 'secret-bz',
            'other_app' => 'secret-other',
        ]);

        $captured = [];
        $capture = function ($req) use (&$captured) {
            $captured[] = $req->getAttribute('application');
        };

        $requestA = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/whatever')
            ->withHeader('X-API-Key', 'secret-bz');
        (new ApiKeyAuthMiddleware($config))->process($requestA, $this->capturingHandler($capture));

        $requestB = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/whatever')
            ->withHeader('X-API-Key', 'secret-other');
        (new ApiKeyAuthMiddleware($config))->process($requestB, $this->capturingHandler($capture));

        self::assertSame(['biuro_zawodow', 'other_app'], $captured);
    }

    private function noOpHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
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

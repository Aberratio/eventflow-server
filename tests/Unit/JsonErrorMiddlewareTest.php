<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Throwable;
use ZmierzymyCzas\Http\Middleware\JsonErrorMiddleware;

final class JsonErrorMiddlewareTest extends TestCase
{
    private function throwingHandler(Throwable $exception): RequestHandlerInterface
    {
        return new class ($exception) implements RequestHandlerInterface {
            public function __construct(private Throwable $exception)
            {
            }

            public function handle(Request $request): Response
            {
                throw $this->exception;
            }
        };
    }

    private function request(): Request
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/events');
    }

    public function testRuntimeExceptionMessageIsHiddenOutsideDebug(): void
    {
        $middleware = new JsonErrorMiddleware(false);
        $exception = new \RuntimeException('SQLSTATE[42S02]: Base table or view not found: users_secret');

        $response = $middleware->process($this->request(), $this->throwingHandler($exception));
        $rawBody = (string) $response->getBody();
        $body = (array) json_decode($rawBody, true);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('server_error', $body['error']['code']);
        self::assertSame(JsonErrorMiddleware::GENERIC_SERVER_ERROR_MESSAGE, $body['error']['message']);
        self::assertStringNotContainsString('SQLSTATE', $rawBody);
        self::assertArrayNotHasKey('details', $body['error']);
    }

    public function testPdoExceptionIsHiddenOutsideDebug(): void
    {
        $middleware = new JsonErrorMiddleware(false);
        $exception = new \PDOException('SQLSTATE[HY000] [1045] Access denied for user root');

        $response = $middleware->process($this->request(), $this->throwingHandler($exception));
        $rawBody = (string) $response->getBody();
        $body = (array) json_decode($rawBody, true);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('server_error', $body['error']['code']);
        self::assertSame(JsonErrorMiddleware::GENERIC_SERVER_ERROR_MESSAGE, $body['error']['message']);
        self::assertStringNotContainsString('SQLSTATE', $rawBody);
        self::assertArrayNotHasKey('details', $body['error']);
    }

    public function testHttpServerErrorExceptionIsHiddenOutsideDebug(): void
    {
        $middleware = new JsonErrorMiddleware(false);
        $req = $this->request();
        $exception = new \Slim\Exception\HttpInternalServerErrorException($req, 'internal detail');

        $response = $middleware->process($req, $this->throwingHandler($exception));
        $body = (array) json_decode((string) $response->getBody(), true);

        self::assertSame('server_error', $body['error']['code']);
        self::assertSame(JsonErrorMiddleware::GENERIC_SERVER_ERROR_MESSAGE, $body['error']['message']);
    }

    public function testHttpNotFoundKeepsItsMessage(): void
    {
        $middleware = new JsonErrorMiddleware(false);
        $req = $this->request();
        $exception = new HttpNotFoundException($req);

        $response = $middleware->process($req, $this->throwingHandler($exception));
        $body = (array) json_decode((string) $response->getBody(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('request_error', $body['error']['code']);
        self::assertSame((new HttpNotFoundException($req))->getMessage(), $body['error']['message']);
    }

    public function testDebugModeExposesMessageAndDetails(): void
    {
        $middleware = new JsonErrorMiddleware(true);
        $exception = new \RuntimeException('boom detail');

        $response = $middleware->process($this->request(), $this->throwingHandler($exception));
        $body = (array) json_decode((string) $response->getBody(), true);

        self::assertSame('boom detail', $body['error']['message']);
        self::assertSame(\RuntimeException::class, $body['error']['details']['exception']);
        self::assertArrayHasKey('trace', $body['error']['details']);
    }

    public function testGenericMessageIsPolish(): void
    {
        self::assertSame(
            'Wystąpił nieoczekiwany błąd serwera. Spróbuj ponownie później.',
            JsonErrorMiddleware::GENERIC_SERVER_ERROR_MESSAGE
        );
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Throwable;
use ZmierzymyCzas\Http\Middleware\JsonErrorMiddleware;
use ZmierzymyCzas\Support\InputValidationException;
use ZmierzymyCzas\Support\Log\PiiRedactingProcessor;

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

    private function testLogger(): array
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $logger->pushProcessor(new PiiRedactingProcessor());

        return [$logger, $handler];
    }

    public function testServerErrorIsLoggedOnceWithRequestIdMatchingTheHeader(): void
    {
        [$logger, $handler] = $this->testLogger();
        $middleware = new JsonErrorMiddleware(false, $logger);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/registrations?email=jan@example.test')
            ->withHeader('Authorization', 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig')
            ->withParsedBody(['phone' => '600700800']);
        $exception = new \RuntimeException("Duplicate entry 'jan@example.test' for key 'users.email'");

        $response = $middleware->process($request, $this->throwingHandler($exception));

        self::assertSame(500, $response->getStatusCode());
        self::assertCount(1, $handler->getRecords());
        $record = $handler->getRecords()[0];
        self::assertSame('Unhandled server error', $record->message);
        self::assertSame('/api/v1/registrations', $record->context['path']);
        $requestId = $response->getHeaderLine('X-Request-Id');
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $requestId);
        self::assertSame($requestId, $record->context['requestId']);

        $formatter = new JsonFormatter();
        $formatted = '';
        foreach ($handler->getRecords() as $r) {
            $formatted .= $formatter->format($r);
        }
        foreach (['jan@example.test', 'eyJ', '600700800', '?email'] as $literal) {
            self::assertStringNotContainsString($literal, $formatted);
        }
    }

    public function testClientErrorIsNotLoggedAndHasNoRequestId(): void
    {
        [$logger, $handler] = $this->testLogger();
        $middleware = new JsonErrorMiddleware(false, $logger);
        $request = $this->request();

        $response = $middleware->process($request, $this->throwingHandler(new HttpNotFoundException($request)));

        self::assertSame(404, $response->getStatusCode());
        self::assertCount(0, $handler->getRecords());
        self::assertFalse($response->hasHeader('X-Request-Id'));
    }

    public function testWebhookServerErrorUsesPaymentsAlertKey(): void
    {
        [$logger, $handler] = $this->testLogger();
        $middleware = new JsonErrorMiddleware(false, $logger);
        $factory = new ServerRequestFactory();

        $middleware->process($factory->createServerRequest('POST', '/api/v1/webhooks/tpay'), $this->throwingHandler(new \RuntimeException('x')));
        $middleware->process($factory->createServerRequest('GET', '/api/v1/events'), $this->throwingHandler(new \RuntimeException('x')));

        self::assertSame('payments.webhook_error', $handler->getRecords()[0]->context['alert']);
        self::assertSame('http.server_error', $handler->getRecords()[1]->context['alert']);
    }

    public function testThrowingLoggerDoesNotChangeTheResponse(): void
    {
        $logger = new class () extends \Psr\Log\AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger is broken');
            }
        };
        $middleware = new JsonErrorMiddleware(false, $logger);

        $response = $middleware->process($this->request(), $this->throwingHandler(new \RuntimeException('boom')));
        $body = (array) json_decode((string) $response->getBody(), true);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(JsonErrorMiddleware::GENERIC_SERVER_ERROR_MESSAGE, $body['error']['message']);
    }

    public function testInputValidationExceptionBecomes422WithField(): void
    {
        foreach ([false, true] as $debug) {
            [$logger, $handler] = $this->testLogger();
            $middleware = new JsonErrorMiddleware($debug, $logger);
            $exception = new InputValidationException('Pole „Nazwa serii” jest wymagane.', 'name');

            $response = $middleware->process($this->request(), $this->throwingHandler($exception));
            $body = (array) json_decode((string) $response->getBody(), true);

            self::assertSame(422, $response->getStatusCode());
            self::assertFalse($body['success']);
            self::assertSame('validation_error', $body['error']['code']);
            self::assertSame('Pole „Nazwa serii” jest wymagane.', $body['error']['message']);
            self::assertSame(['field' => 'name'], $body['error']['details']);
            self::assertFalse($response->hasHeader('X-Request-Id'));
            self::assertCount(0, $handler->getRecords());
        }
    }

    public function testInputValidationExceptionDefaultsToValidationErrorCode(): void
    {
        $middleware = new JsonErrorMiddleware(false, $this->testLogger()[0]);

        $response = $middleware->process($this->request(), $this->throwingHandler(new InputValidationException('Brak pola.', 'name')));
        $body = (array) json_decode((string) $response->getBody(), true);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_error', $body['error']['code']);
    }

    public function testInputValidationExceptionCarriesCustomErrorCode(): void
    {
        [$logger, $handler] = $this->testLogger();
        $middleware = new JsonErrorMiddleware(false, $logger);
        $exception = new InputValidationException('Ten wariant opłaty nie jest dostępny.', 'pricingVariantId', 'pricing_variant_unavailable');

        $response = $middleware->process($this->request(), $this->throwingHandler($exception));
        $body = (array) json_decode((string) $response->getBody(), true);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($body['success']);
        self::assertSame('pricing_variant_unavailable', $body['error']['code']);
        self::assertSame('Ten wariant opłaty nie jest dostępny.', $body['error']['message']);
        self::assertSame(['field' => 'pricingVariantId'], $body['error']['details']);
        self::assertFalse($response->hasHeader('X-Request-Id'));
        self::assertCount(0, $handler->getRecords());
    }
}

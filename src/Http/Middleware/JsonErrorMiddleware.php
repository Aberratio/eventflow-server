<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Psr7\Response as SlimResponse;
use Throwable;
use ZmierzymyCzas\Support\InputValidationException;
use ZmierzymyCzas\Support\JsonResponder;
use ZmierzymyCzas\Support\Log\LoggerFactory;

final class JsonErrorMiddleware implements MiddlewareInterface
{
    public const GENERIC_SERVER_ERROR_MESSAGE = 'Wystąpił nieoczekiwany błąd serwera. Spróbuj ponownie później.';
    public const GENERIC_REQUEST_ERROR_MESSAGE = 'Nieprawidłowe żądanie.';

    private readonly LoggerInterface $logger;

    public function __construct(private readonly bool $debug = false, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? LoggerFactory::fallback();
    }

    public function process(Request $request, Handler $handler): Response
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $exception) {
            if ($exception instanceof InputValidationException) {
                // Expected client error, not a server fault, so not logged and never 500.
                return JsonResponder::error(new SlimResponse(), $exception->errorCode, $exception->getMessage(), 422, ['field' => $exception->field]);
            }

            $status = $exception instanceof HttpException ? $exception->getCode() : 500;
            if ($status < 400 || $status > 599) {
                $status = 500;
            }

            $isServerError = $status >= 500;
            $requestId = null;
            if ($isServerError) {
                $requestId = bin2hex(random_bytes(8));
                $this->reportServerError($exception, $request, $requestId, $status);
            }

            if ($this->debug) {
                $message = $exception->getMessage()
                    ?: ($isServerError ? self::GENERIC_SERVER_ERROR_MESSAGE : self::GENERIC_REQUEST_ERROR_MESSAGE);
            } elseif (!$isServerError) {
                // A 4xx status here only ever comes from a Slim HttpException (any other
                // Throwable is forced to 500 above), whose own messages are safe to show.
                $message = $exception->getMessage() ?: self::GENERIC_REQUEST_ERROR_MESSAGE;
            } else {
                $message = self::GENERIC_SERVER_ERROR_MESSAGE;
            }

            $details = $this->debug ? ['exception' => $exception::class, 'trace' => $exception->getTraceAsString()] : [];

            $response = JsonResponder::error(
                new SlimResponse(),
                $isServerError ? 'server_error' : 'request_error',
                $message,
                $status,
                $details
            );

            return $requestId !== null ? $response->withHeader('X-Request-Id', $requestId) : $response;
        }
    }

    /**
     * Server-error seam: every 5xx passes through here exactly once. Logs the exception summary,
     * method and path only (never query string, headers or body) and must never throw.
     */
    private function reportServerError(Throwable $exception, Request $request, string $requestId, int $status): void
    {
        try {
            $path = $request->getUri()->getPath();
            $this->logger->error('Unhandled server error', [
                'exception' => $exception,
                'requestId' => $requestId,
                'method' => $request->getMethod(),
                'path' => $path,
                'http_status' => $status,
                'alert' => str_contains($path, '/webhooks/') ? 'payments.webhook_error' : 'http.server_error',
            ]);
        } catch (Throwable) {
            // Logging must never change the error response.
        }
    }
}

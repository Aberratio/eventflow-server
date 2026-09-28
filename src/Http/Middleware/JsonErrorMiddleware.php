<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Exception\HttpException;
use Slim\Psr7\Response as SlimResponse;
use Throwable;
use ZmierzymyCzas\Support\JsonResponder;

final class JsonErrorMiddleware implements MiddlewareInterface
{
    public const GENERIC_SERVER_ERROR_MESSAGE = 'Wystąpił nieoczekiwany błąd serwera. Spróbuj ponownie później.';
    public const GENERIC_REQUEST_ERROR_MESSAGE = 'Nieprawidłowe żądanie.';

    public function __construct(private readonly bool $debug = false)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $exception) {
            $status = $exception instanceof HttpException ? $exception->getCode() : 500;
            if ($status < 400 || $status > 599) {
                $status = 500;
            }

            $isServerError = $status >= 500;
            if ($isServerError) {
                $this->reportServerError($exception, $request);
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

            return JsonResponder::error(
                new SlimResponse(),
                $isServerError ? 'server_error' : 'request_error',
                $message,
                $status,
                $details
            );
        }
    }

    /**
     * Seam for PP.20 (observability): every 5xx passes through here exactly once.
     * Intentionally a no-op until a PSR-3 logger is injected.
     */
    private function reportServerError(Throwable $exception, Request $request): void
    {
    }
}

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

            $details = $this->debug ? ['exception' => $exception::class, 'trace' => $exception->getTraceAsString()] : [];

            return JsonResponder::error(
                new SlimResponse(),
                $status >= 500 ? 'server_error' : 'request_error',
                $exception->getMessage() ?: 'Unexpected server error',
                $status,
                $details
            );
        }
    }
}

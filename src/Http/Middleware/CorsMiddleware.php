<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

final class CorsMiddleware implements MiddlewareInterface
{
    /** @var array<int, string> */
    private array $allowedOrigins;

    private bool $allowAny;

    public function __construct(string $origins)
    {
        $this->allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $origins))));
        $this->allowAny = in_array('*', $this->allowedOrigins, true);
    }

    public function process(Request $request, Handler $handler): Response
    {
        $isPreflight = $request->getMethod() === 'OPTIONS';
        $response = $isPreflight ? new SlimResponse() : $handler->handle($request);
        $response = $response->withAddedHeader('Vary', 'Origin');

        $origin = $request->getHeaderLine('Origin');
        $allowOrigin = $this->allowAny
            ? '*'
            : (($origin !== '' && in_array($origin, $this->allowedOrigins, true)) ? $origin : null);

        if ($allowOrigin === null) {
            // Foreign or absent origin: no ACAO, no Allow-Methods/Headers.
            return $response;
        }

        // The client authenticates with a Bearer token only (never cookies), so
        // Access-Control-Allow-Credentials is never sent, not even conditionally.
        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS');
    }
}

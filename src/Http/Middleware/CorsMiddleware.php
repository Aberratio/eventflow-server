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

    public function __construct(string $origins)
    {
        $this->allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $origins))));
    }

    public function process(Request $request, Handler $handler): Response
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new SlimResponse();
        } else {
            $response = $handler->handle($request);
        }

        $origin = $request->getHeaderLine('Origin');
        $allowOrigin = in_array('*', $this->allowedOrigins, true)
            ? '*'
            : (in_array($origin, $this->allowedOrigins, true) ? $origin : null);

        if ($allowOrigin === null) {
            return $response
                ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS')
                ->withHeader('Vary', 'Origin');
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Vary', 'Origin');
    }
}

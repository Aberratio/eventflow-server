<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;
use Throwable;
use ZmierzymyCzas\Domain\Auth\JwtService;
use ZmierzymyCzas\Support\JsonResponder;

final class AuthMiddleware implements MiddlewareInterface
{
    /** @param array<int, string> $roles */
    public function __construct(
        private readonly JwtService $jwt,
        private readonly array $roles = []
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return JsonResponder::error(new SlimResponse(), 'unauthorized', 'Missing bearer token', 401);
        }

        try {
            $claims = $this->jwt->decode($matches[1]);
        } catch (Throwable) {
            return JsonResponder::error(new SlimResponse(), 'unauthorized', 'Invalid or expired token', 401);
        }

        $role = (string) ($claims['role'] ?? '');
        if ($this->roles !== [] && !$this->isAllowed($role)) {
            return JsonResponder::error(new SlimResponse(), 'forbidden', 'Insufficient permissions', 403);
        }

        return $handler->handle($request->withAttribute('auth', $claims));
    }

    private function isAllowed(string $role): bool
    {
        if (in_array($role, $this->roles, true)) {
            return true;
        }

        return $role === 'superadmin' && in_array('admin', $this->roles, true);
    }
}

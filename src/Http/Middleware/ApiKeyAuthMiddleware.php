<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;
use ZmierzymyCzas\Domain\Auth\ApplicationApiKeyConfig;
use ZmierzymyCzas\Support\JsonResponder;

/**
 * Gates INCOMING application-to-application traffic (Biuro Zawodów and
 * future callers) behind a static `X-API-Key` header — the same header Central
 * Mailer itself expects from ITS callers,
 * kept consistent for anyone integrating with either service. This is a
 * separate, parallel mechanism to AuthMiddleware's user JWT: no user identity
 * is involved, only "which application is calling", exposed to route handlers
 * via the `application` request attribute (e.g. for activity-log attribution).
 *
 * On any failure — missing, empty, malformed, or unknown key, or no
 * applications configured at all — this rejects with a generic 401 that leaks
 * nothing about which keys exist or why the check failed.
 */
final class ApiKeyAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ApplicationApiKeyConfig $config,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $key = trim($request->getHeaderLine('X-API-Key'));
        $application = $this->config->resolveApplication($key);

        if ($application === null) {
            return JsonResponder::error(new SlimResponse(), 'unauthorized', 'Brak dostępu.', 401);
        }

        return $handler->handle($request->withAttribute('application', $application));
    }
}

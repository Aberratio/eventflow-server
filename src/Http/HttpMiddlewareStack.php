<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http;

use Slim\App;
use ZmierzymyCzas\Config\AppConfig;
use ZmierzymyCzas\Http\Middleware\CorsMiddleware;
use ZmierzymyCzas\Http\Middleware\JsonErrorMiddleware;
use ZmierzymyCzas\Http\Middleware\SecurityHeadersMiddleware;

final class HttpMiddlewareStack
{
    /** Slim runs middleware LIFO: the LAST one added is the OUTERMOST. */
    public static function register(App $app, AppConfig $config): void
    {
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(new JsonErrorMiddleware($config->debug));
        $app->add(new CorsMiddleware($config->corsOriginsHeaderValue()));
        // Outermost: also covers CORS preflight (answered by CorsMiddleware without calling the
        // handler) and every error response rendered by JsonErrorMiddleware.
        $app->add(new SecurityHeadersMiddleware($config->appEnv !== AppConfig::ENV_LOCAL));
    }
}

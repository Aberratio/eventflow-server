<?php

declare(strict_types=1);

use Slim\Factory\AppFactory;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Auth\JwtService;
use ZmierzymyCzas\Http\Middleware\CorsMiddleware;
use ZmierzymyCzas\Http\Middleware\JsonErrorMiddleware;
use ZmierzymyCzas\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$app = AppFactory::create();
$app->setBasePath('');

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(new JsonErrorMiddleware((bool) Env::getBool('APP_DEBUG', false)));
$app->add(new CorsMiddleware((string) Env::get('APP_CORS_ORIGIN', '*')));

$pdo = Connection::fromEnv();
$jwt = new JwtService(
    (string) Env::get('JWT_SECRET', 'change-me-please-32-bytes-min!!!'),
    (int) Env::get('JWT_TTL_SECONDS', 86400)
);

(require __DIR__ . '/../src/Http/routes.php')($app, $pdo, $jwt);

$app->run();

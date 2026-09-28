<?php

declare(strict_types=1);

use Slim\Factory\AppFactory;
use ZmierzymyCzas\Config\AppConfig;
use ZmierzymyCzas\Config\ConfigurationException;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Auth\JwtService;
use ZmierzymyCzas\Http\Middleware\CorsMiddleware;
use ZmierzymyCzas\Http\Middleware\JsonErrorMiddleware;
use ZmierzymyCzas\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

try {
    $config = AppConfig::fromProcessEnv();
} catch (ConfigurationException $e) {
    // Fail closed: never serve requests with an insecure configuration.
    error_log('[zmierzymy_czas] Refusing to boot: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'service_misconfigured',
            'message' => 'Serwis jest chwilowo niedostępny. Spróbuj ponownie później.',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$app = AppFactory::create();
$app->setBasePath('');

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(new JsonErrorMiddleware($config->debug));
$app->add(new CorsMiddleware($config->corsOriginsHeaderValue()));

$pdo = Connection::fromEnv();
$jwt = new JwtService($config->jwtSecret, $config->jwtTtlSeconds);

(require __DIR__ . '/../src/Http/routes.php')($app, $pdo, $jwt);

$app->run();

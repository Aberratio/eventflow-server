<?php

declare(strict_types=1);

use Slim\Factory\AppFactory;
use ZmierzymyCzas\Config\AppConfig;
use ZmierzymyCzas\Config\ConfigurationException;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Auth\JwtService;
use ZmierzymyCzas\Http\HttpMiddlewareStack;
use ZmierzymyCzas\Http\Middleware\SecurityHeadersMiddleware;
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
    // The config failed to parse, so we don't know APP_ENV; HSTS stays off.
    foreach (SecurityHeadersMiddleware::baselineHeaders(false) as $headerName => $headerValue) {
        header($headerName . ': ' . $headerValue);
    }
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

HttpMiddlewareStack::register($app, $config);

$pdo = Connection::fromEnv();
$jwt = new JwtService($config->jwtSecret, $config->jwtTtlSeconds);

(require __DIR__ . '/../src/Http/routes.php')($app, $pdo, $jwt);

$app->run();

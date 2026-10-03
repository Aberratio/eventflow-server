<?php

declare(strict_types=1);

use Monolog\ErrorHandler;
use Psr\Log\LogLevel;
use Slim\Factory\AppFactory;
use ZmierzymyCzas\Config\AppConfig;
use ZmierzymyCzas\Config\ConfigurationException;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Auth\JwtService;
use ZmierzymyCzas\Http\HttpMiddlewareStack;
use ZmierzymyCzas\Http\Middleware\SecurityHeadersMiddleware;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Support\Log\LogConfig;
use ZmierzymyCzas\Support\Log\LoggerFactory;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$logger = LoggerFactory::create(LogConfig::fromEnv());
// Uncaught exceptions and PHP fatals only; PHP warnings/notices are left untouched.
ErrorHandler::register($logger, false, [], LogLevel::CRITICAL);

// Fail closed with a generic 503. HSTS stays off: when the config failed to parse we don't
// know APP_ENV, and the DB failure path keeps the same simple answer.
$serviceUnavailable = static function (string $code): never {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    foreach (SecurityHeadersMiddleware::baselineHeaders(false) as $headerName => $headerValue) {
        header($headerName . ': ' . $headerValue);
    }
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => $code,
            'message' => 'Serwis jest chwilowo niedostępny. Spróbuj ponownie później.',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    $config = AppConfig::fromProcessEnv();
} catch (ConfigurationException $e) {
    // Fail closed: never serve requests with an insecure configuration.
    error_log('[zmierzymy_czas] Refusing to boot: ' . $e->getMessage());
    $logger->critical('Refusing to boot: invalid configuration', ['configErrors' => $e->getMessage(), 'alert' => 'boot.misconfigured']);
    $serviceUnavailable('service_misconfigured');
}

$app = AppFactory::create();
$app->setBasePath('');

HttpMiddlewareStack::register($app, $config, $logger);

try {
    $pdo = Connection::fromEnv();
} catch (\PDOException $e) {
    $logger->critical('Database connection failed at boot', ['exception' => $e, 'alert' => 'db.unavailable']);
    $serviceUnavailable('service_unavailable');
}

$jwt = new JwtService($config->jwtSecret, $config->jwtTtlSeconds);

(require __DIR__ . '/../src/Http/routes.php')($app, $pdo, $jwt, $logger);

$app->run();

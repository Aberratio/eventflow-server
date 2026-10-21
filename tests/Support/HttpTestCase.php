<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Auth\JwtService;
use ZmierzymyCzas\Http\Middleware\CorsMiddleware;
use ZmierzymyCzas\Http\Middleware\JsonErrorMiddleware;
use ZmierzymyCzas\Support\Env;

abstract class HttpTestCase extends TestCase
{
    protected PDO $pdo;
    protected App $app;
    protected JwtService $jwt;

    /** @var array<string, mixed> server params of every request (e.g. REMOTE_ADDR); empty = no client IP. */
    protected array $serverParams = [];

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');

        try {
            $this->pdo = $this->createPdo();
            $this->jwt = new JwtService('phpunit-http-secret-at-least-32-bytes', 3600);
            $this->app = $this->createApp();
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for HTTP integration tests: ' . $e->getMessage());
        }
    }

    /** Hook for tests that need to share one connection (e.g. a savepoint-isolated one). */
    protected function createPdo(): PDO
    {
        return Connection::fromEnv();
    }

    protected function request(string $method, string $path, array $body = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, $this->serverParams);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, (string) $value);
        }
        if ($body !== []) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody($body);
        }

        return $this->app->handle($request);
    }

    protected function json(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        return $payload;
    }

    protected function bearer(string $userId, string $role, string $email = 'phpunit@example.test'): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->jwt->issue([
                'sub' => $userId,
                'role' => $role,
                'email' => $email,
                'tv' => $this->tokenVersionOf($userId),
            ]),
        ];
    }

    /** The session version the guard compares against; 0 for an unknown user (the guard then answers 401). */
    protected function tokenVersionOf(string $userId): int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT token_version FROM users WHERE id = :id');
            $stmt->execute(['id' => $userId]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function createApp(): App
    {
        $app = AppFactory::create();
        $app->setBasePath('');
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(new JsonErrorMiddleware(false));
        $app->add(new CorsMiddleware('*'));

        (require __DIR__ . '/../../src/Http/routes.php')($app, $this->pdo, $this->jwt);

        return $app;
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use PDO;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\FaultInjectingPdo;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * Two requests for the same new e-mail can both pass the findByEmail() check; the loser hits the
 * UNIQUE index (SQLSTATE 23000). That must be a 409 email_taken with the transaction rolled back, not a 500.
 * The INSERT into users is made to fail with 23000 through a PDO double.
 */
final class AccountEmailRaceContractTest extends HttpTestCase
{
    private string $prefix;
    private PDO $real;

    protected function createPdo(): PDO
    {
        $duplicate = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key 'email'");
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($duplicate, '23000');

        return new FaultInjectingPdo('INSERT INTO users', $duplicate);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'http-race-' . bin2hex(random_bytes(4));
        $this->real = Connection::fromEnv();
    }

    protected function tearDown(): void
    {
        if (isset($this->real, $this->prefix)) {
            $like = ['email' => $this->prefix . '-%@example.test'];
            $this->real->prepare('DELETE FROM competitors WHERE user_id IN (SELECT id FROM users WHERE email LIKE :email)')->execute($like);
            $this->real->prepare('DELETE FROM users WHERE email LIKE :email')->execute($like);
        }
    }

    public function testALostUniqueRaceOnAdminCreateIs409AndRolledBack(): void
    {
        $admin = (new UserRepository($this->real))->create([
            'name' => 'Race Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
        $before = (int) $this->real->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $response = $this->request(
            'POST',
            '/api/v1/users',
            ['name' => 'Raced', 'email' => $this->prefix . '-raced@example.test', 'role' => 'participant'],
            $this->bearer((string) $admin['id'], 'admin', (string) $admin['email'])
        );

        self::assertSame(409, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame('email_taken', $error['code']);
        self::assertSame('Ten adres e-mail jest już zajęty.', $error['message']);
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame($before, (int) $this->real->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testALostUniqueRaceOnRegisterIs409(): void
    {
        $response = $this->request('POST', '/api/v1/auth/register', [
            'name' => 'Raced',
            'email' => $this->prefix . '-reg@example.test',
            'password' => 'demo1234',
        ]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('email_taken', $this->json($response)['error']['code']);
    }
}

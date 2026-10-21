<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * A successful POST /auth/login persists users.last_login_at,
 * exposed as `lastLoginAt` (ISO 8601) on user payloads; null until the first login.
 */
final class LastLoginContractTest extends HttpTestCase
{
    private UserRepository $users;
    private string $prefix;
    private array $admin;
    private array $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'lastlogin-' . bin2hex(random_bytes(4));
        $this->admin = $this->users->create(['name' => 'LL Admin', 'email' => $this->prefix . '-admin@example.test', 'password' => 'demo1234', 'role' => 'admin']);
        $this->user = $this->users->create(['name' => 'LL User', 'email' => $this->prefix . '-user@example.test', 'password' => 'demo1234', 'role' => 'participant']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    private function adminListRow(): array
    {
        $response = $this->request(
            'GET',
            '/api/v1/users?search=' . urlencode($this->prefix . '-user'),
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $response->getStatusCode());
        $rows = $this->json($response)['data'];
        self::assertCount(1, $rows);
        return $rows[0];
    }

    public function testLastLoginIsNullBeforeFirstLoginAndSetAfterSuccessfulLogin(): void
    {
        $row = $this->adminListRow();
        self::assertArrayHasKey('lastLoginAt', $row);
        self::assertNull($row['lastLoginAt']);

        $wrong = $this->request('POST', '/api/v1/auth/login', ['email' => $this->user['email'], 'password' => 'wrong-password']);
        self::assertSame(401, $wrong->getStatusCode());
        self::assertNull($this->adminListRow()['lastLoginAt'], 'a failed login must not touch last_login_at');

        $ok = $this->request('POST', '/api/v1/auth/login', ['email' => $this->user['email'], 'password' => 'demo1234']);
        self::assertSame(200, $ok->getStatusCode());

        $lastLoginAt = $this->adminListRow()['lastLoginAt'];
        self::assertIsString($lastLoginAt);
        self::assertLessThan(120, abs(time() - (new \DateTimeImmutable($lastLoginAt))->getTimestamp()));
    }
}

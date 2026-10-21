<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * POST /auth/login is throttled per account (normalised e-mail, existing or not) and per IP:
 * 5 failures per account / 20 per IP in 15 minutes lock the subject with a growing lockout, answered
 * with 429 + Retry-After, a lockout is written to the activity log, a success resets the account.
 */
final class LoginThrottleContractTest extends HttpTestCase
{
    private string $prefix;
    private array $user;
    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'throttle-' . bin2hex(random_bytes(4));
        $this->ip = '203.0.113.' . random_int(1, 254);
        $this->serverParams = ['REMOTE_ADDR' => $this->ip];
        $this->user = (new UserRepository($this->pdo))->create([
            'name' => 'Throttle User',
            'email' => $this->prefix . '-user@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare("DELETE FROM activity_logs WHERE action = 'login_lockout' AND (target_id = :id OR target_name = :ip)")
            ->execute(['id' => $this->user['id'], 'ip' => $this->ip]);
        $this->pdo->exec('DELETE FROM login_throttle');
    }

    private function login(string $email, string $password): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function failFiveTimes(string $email): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $this->login($email, 'wrong-' . $i)->getStatusCode());
        }
    }

    public function testSixthAttemptIsBlockedWith429AndRetryAfter(): void
    {
        $this->failFiveTimes($this->user['email']);

        $blocked = $this->login($this->user['email'], 'wrong-again');
        self::assertSame(429, $blocked->getStatusCode());
        $retry = (int) $blocked->getHeaderLine('Retry-After');
        self::assertGreaterThan(0, $retry);
        self::assertLessThanOrEqual(60, $retry);
        $json = $this->json($blocked);
        self::assertFalse($json['success']);
        self::assertSame('too_many_login_attempts', $json['error']['code']);
        self::assertSame($retry, $json['error']['details']['retryAfterSeconds']);

        // Even the correct password is refused while blocked.
        self::assertSame(429, $this->login($this->user['email'], 'demo1234')->getStatusCode());
    }

    public function testEmailIsNormalisedForCounting(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $email = $i % 2 === 0 ? strtoupper($this->user['email']) : '  ' . $this->user['email'] . ' ';
            self::assertSame(401, $this->login($email, 'wrong')->getStatusCode());
        }
        self::assertSame(429, $this->login($this->user['email'], 'demo1234')->getStatusCode());
    }

    public function testUnknownEmailBehavesTheSameAsAnExistingOne(): void
    {
        $unknown = $this->prefix . '-nobody@example.test';
        $this->failFiveTimes($unknown);

        $blocked = $this->login($unknown, 'whatever');
        self::assertSame(429, $blocked->getStatusCode());
        self::assertGreaterThan(0, (int) $blocked->getHeaderLine('Retry-After'));
        self::assertSame('too_many_login_attempts', $this->json($blocked)['error']['code']);
    }

    public function testSuccessfulLoginResetsTheAccountCounter(): void
    {
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(401, $this->login($this->user['email'], 'wrong')->getStatusCode());
        }
        self::assertSame(200, $this->login($this->user['email'], 'demo1234')->getStatusCode());
        // Four more failures would have been the 8th overall; the counter restarted so still no lockout.
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(401, $this->login($this->user['email'], 'wrong')->getStatusCode());
        }
        self::assertSame(200, $this->login($this->user['email'], 'demo1234')->getStatusCode());
    }

    public function testLockoutExpiresAndThenGrows(): void
    {
        $this->failFiveTimes($this->user['email']);
        self::assertSame(429, $this->login($this->user['email'], 'demo1234')->getStatusCode());

        // Simulate the first lockout (60 s) having elapsed.
        $this->pdo->exec("UPDATE login_throttle SET locked_until = locked_until - 61 WHERE scope = 'account'");
        $this->failFiveTimes($this->user['email']);
        $second = $this->login($this->user['email'], 'demo1234');
        self::assertSame(429, $second->getStatusCode());
        $retry = (int) $second->getHeaderLine('Retry-After');
        self::assertGreaterThan(60, $retry);
        self::assertLessThanOrEqual(300, $retry);
    }

    public function testLockoutIsWrittenToTheActivityLog(): void
    {
        $this->failFiveTimes($this->user['email']);

        $stmt = $this->pdo->prepare("SELECT user_id, description FROM activity_logs WHERE action = 'login_lockout' AND target_id = :id");
        $stmt->execute(['id' => $this->user['id']]);
        $rows = $stmt->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame($this->user['id'], $rows[0]['user_id']);
    }

    public function testIpLimitBlocksDifferentAccounts(): void
    {
        for ($i = 0; $i < 20; $i++) {
            self::assertSame(401, $this->login($this->prefix . '-x' . $i . '@example.test', 'wrong')->getStatusCode());
        }
        $blocked = $this->login($this->user['email'], 'demo1234');
        self::assertSame(429, $blocked->getStatusCode());
        self::assertGreaterThan(0, (int) $blocked->getHeaderLine('Retry-After'));

        $this->serverParams = ['REMOTE_ADDR' => '198.51.100.7'];
        self::assertSame(200, $this->login($this->user['email'], 'demo1234')->getStatusCode());
    }

    public function testOtherAccountsAreNotAffectedByOneLockout(): void
    {
        $this->failFiveTimes($this->user['email']);
        $other = (new UserRepository($this->pdo))->create([
            'name' => 'Other',
            'email' => $this->prefix . '-other@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        self::assertSame(200, $this->login($other['email'], 'demo1234')->getStatusCode());
    }
}

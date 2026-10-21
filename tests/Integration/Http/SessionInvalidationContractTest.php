<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Domain\Auth\JwtService;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * A session is a login token for a user that still exists, whose
 * token_version matches and whose jti was not logged out. Mail-link (purpose)
 * tokens are never sessions. Tokens here come from POST /auth/login so the
 * real claims (tv, jti) are exercised.
 */
final class SessionInvalidationContractTest extends HttpTestCase
{
    private UserRepository $users;
    private string $prefix;
    /** @var array<string, mixed> */
    private array $user;
    /** @var array<string, mixed> */
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'http-si-' . bin2hex(random_bytes(4));
        $this->user = $this->users->create([
            'name' => 'Session User',
            'email' => $this->prefix . '-u@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $this->admin = $this->users->create([
            'name' => 'Session Admin',
            'email' => $this->prefix . '-a@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM revoked_session_tokens WHERE user_id IN (SELECT id FROM users WHERE email LIKE :email)')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    private function login(array $user, string $password = 'demo1234'): string
    {
        $response = $this->request('POST', '/api/v1/auth/login', ['email' => $user['email'], 'password' => $password]);
        self::assertSame(200, $response->getStatusCode());
        return (string) $this->json($response)['data']['token'];
    }

    private function me(string $token): ResponseInterface
    {
        return $this->request('GET', '/api/v1/auth/me', [], ['Authorization' => 'Bearer ' . $token]);
    }

    public function testPurposeJwtIsNotASession(): void
    {
        foreach (['email_verification', 'password_reset', 'anything'] as $purpose) {
            $token = $this->jwt->issue(['sub' => $this->user['id'], 'purpose' => $purpose]);
            $response = $this->me($token);

            self::assertSame(401, $response->getStatusCode(), $purpose);
            self::assertSame('unauthorized', $this->json($response)['error']['code']);
        }
    }

    public function testPurposeJwtIsAnonymousOnOptionalAuthRoute(): void
    {
        $stmt = $this->pdo->query("SELECT id FROM events WHERE publication_status = 'draft' AND deleted_at IS NULL LIMIT 1");
        $eventId = (string) $stmt->fetchColumn();
        self::assertNotSame('', $eventId, 'The seed must contain a draft event.');

        $real = $this->login($this->admin);
        self::assertSame(
            200,
            $this->request('GET', '/api/v1/events/' . $eventId, [], ['Authorization' => 'Bearer ' . $real])->getStatusCode()
        );

        $purpose = $this->jwt->issue(['sub' => $this->admin['id'], 'role' => 'admin', 'purpose' => 'password_reset']);
        self::assertSame(
            404,
            $this->request('GET', '/api/v1/events/' . $eventId, [], ['Authorization' => 'Bearer ' . $purpose])->getStatusCode()
        );
    }

    public function testLegacyTokenWithoutTvSurvivesUntilVersionBump(): void
    {
        $legacy = $this->jwt->issue(['sub' => $this->user['id'], 'role' => 'participant', 'email' => $this->user['email']]);

        self::assertSame(200, $this->me($legacy)->getStatusCode());

        $this->users->bumpTokenVersion((string) $this->user['id']);

        self::assertSame(401, $this->me($legacy)->getStatusCode());
    }

    public function testUnknownSubIs401(): void
    {
        $token = $this->jwt->issue(['sub' => 'no-such-user-' . $this->prefix, 'role' => 'admin', 'tv' => 0]);

        self::assertSame(401, $this->me($token)->getStatusCode());
    }

    public function testLogoutRevokesOnlyThisSession(): void
    {
        $t1 = $this->login($this->user);
        $t2 = $this->login($this->user);
        self::assertSame(200, $this->me($t1)->getStatusCode());

        $logout = $this->request('POST', '/api/v1/auth/logout', [], ['Authorization' => 'Bearer ' . $t1]);
        self::assertSame(200, $logout->getStatusCode());

        self::assertSame(401, $this->me($t1)->getStatusCode());
        self::assertSame(200, $this->me($t2)->getStatusCode());
    }

    public function testLogoutIsAlways200(): void
    {
        $expiredJwt = new JwtService('phpunit-http-secret-at-least-32-bytes', -60);
        $expired = $expiredJwt->issue([
            'sub' => $this->user['id'],
            'role' => 'participant',
            'tv' => 0,
            'jti' => JwtService::newJti(),
        ]);

        foreach ([[], ['Authorization' => 'Bearer garbage'], ['Authorization' => 'Bearer ' . $expired], ['Authorization' => 'Basic abc']] as $headers) {
            self::assertSame(200, $this->request('POST', '/api/v1/auth/logout', [], $headers)->getStatusCode());
        }

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM revoked_session_tokens WHERE user_id = :id');
        $count->execute(['id' => $this->user['id']]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testPasswordChangeReturnsFreshTokenAndKillsOtherSessions(): void
    {
        $t1 = $this->login($this->user);
        $t2 = $this->login($this->user);

        $response = $this->request(
            'POST',
            '/api/v1/auth/password/change',
            ['oldPassword' => 'demo1234', 'newPassword' => 'NoweHaslo123'],
            ['Authorization' => 'Bearer ' . $t1]
        );
        self::assertSame(200, $response->getStatusCode());
        $t3 = $this->json($response)['data']['token'] ?? null;
        self::assertIsString($t3);
        self::assertNotSame('', $t3);

        self::assertSame(401, $this->me($t1)->getStatusCode());
        self::assertSame(401, $this->me($t2)->getStatusCode());
        $fresh = $this->me($t3);
        self::assertSame(200, $fresh->getStatusCode());
        self::assertSame($this->user['id'], $this->json($fresh)['data']['id']);
    }

    public function testLoginTokenCarriesTvAndJtiButDtoDoesNot(): void
    {
        $login = $this->request('POST', '/api/v1/auth/login', ['email' => $this->user['email'], 'password' => 'demo1234']);
        $data = $this->json($login)['data'];
        $claims = $this->jwt->decode((string) $data['token']);

        self::assertIsInt($claims['tv']);
        self::assertSame(0, $claims['tv']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $claims['jti']);

        $adminToken = $this->login($this->admin);
        $adminHeaders = ['Authorization' => 'Bearer ' . $adminToken];
        $payloads = [
            $data['user'],
            $this->json($this->me((string) $data['token']))['data'],
            $this->json($this->request('GET', '/api/v1/users/' . $this->user['id'], [], $adminHeaders))['data'],
            $this->json($this->request('GET', '/api/v1/users?search=' . $this->prefix, [], $adminHeaders))['data'][0],
        ];
        foreach ($payloads as $dto) {
            foreach (['tokenVersion', 'token_version', 'tv', 'jti'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $dto);
            }
        }
    }
}

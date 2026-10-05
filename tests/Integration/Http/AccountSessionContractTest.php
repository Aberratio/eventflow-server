<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * PP.70 (+ PP.78): blocking, role changes and staff e-mail edits end the
 * account's sessions; a blocked account never gets a session (login 403, guard
 * 401). Tokens come from POST /auth/login so the real claims (tv, jti) apply.
 */
final class AccountSessionContractTest extends HttpTestCase
{
    private UserRepository $users;
    private string $prefix;
    /** @var array<string, mixed> */
    private array $user;
    /** @var array<string, mixed> */
    private array $other;
    /** @var array<string, mixed> */
    private array $organizer;
    /** @var array<string, mixed> */
    private array $admin;
    /** @var array<string, mixed> */
    private array $moderator;

    protected function setUp(): void
    {
        if ($this->name() === 'testAccountLockedMailIsAttemptedOnlyOnTheActiveToBlockedEdge') {
            // Must be set before the app is built: the mailer config is read at route registration.
            Env::set('CENTRAL_MAILER_API_KEY', 'phpunit-key');
            Env::set('CENTRAL_MAILER_BASE_URL', 'unusable://mailer.invalid');
            Env::set('APP_URL', 'https://app.example.test');
        }
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'http-as-' . bin2hex(random_bytes(4));
        $this->user = $this->makeUser('u', 'participant');
        $this->other = $this->makeUser('v', 'participant');
        $this->organizer = $this->makeUser('o', 'organizer');
        $this->admin = $this->makeUser('a', 'admin');
        $this->moderator = $this->makeUser('m', 'moderator');
    }

    protected function tearDown(): void
    {
        Env::restore('CENTRAL_MAILER_API_KEY');
        Env::restore('CENTRAL_MAILER_BASE_URL');
        Env::restore('APP_URL');
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $like = ['email' => $this->prefix . '-%@example.test'];
        $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE recipient LIKE :email')->execute($like);
        $this->pdo->prepare('DELETE FROM revoked_session_tokens WHERE user_id IN (SELECT id FROM users WHERE email LIKE :email)')->execute($like);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute($like);
    }

    /** @return array<string, mixed> */
    private function makeUser(string $tag, string $role): array
    {
        return $this->users->create([
            'name' => 'Account ' . $tag,
            'email' => $this->prefix . '-' . $tag . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ]);
    }

    private function loginResponse(string $email, string $password = 'demo1234'): ResponseInterface
    {
        return $this->request('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    /** @param array<string, mixed> $user */
    private function login(array $user): string
    {
        $response = $this->loginResponse((string) $user['email']);
        self::assertSame(200, $response->getStatusCode());
        return (string) $this->json($response)['data']['token'];
    }

    /** @return array<string, string> */
    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    private function me(string $token): ResponseInterface
    {
        return $this->request('GET', '/api/v1/auth/me', [], $this->auth($token));
    }

    /** @param array<string, mixed> $body */
    private function patch(string $path, array $body, string $token): ResponseInterface
    {
        return $this->request('PATCH', '/api/v1/users/' . $path, $body, $this->auth($token));
    }

    /** @param array<string, mixed> $user */
    private function tokenVersion(array $user): int
    {
        return $this->users->tokenVersion((string) $user['id']);
    }

    private function draftEventId(): string
    {
        $id = (string) $this->pdo->query("SELECT id FROM events WHERE publication_status = 'draft' AND deleted_at IS NULL LIMIT 1")->fetchColumn();
        self::assertNotSame('', $id, 'The seed must contain a draft event.');
        return $id;
    }

    private function assertSessionDead(string $token, string $label = ''): void
    {
        $response = $this->me($token);
        self::assertSame(401, $response->getStatusCode(), $label);
        self::assertSame('unauthorized', $this->json($response)['error']['code'], $label);
    }

    public function testBlockingEndsExistingSessions(): void
    {
        $t1 = $this->login($this->user);
        $t2 = $this->login($this->user);
        $adminToken = $this->login($this->admin);
        $otherToken = $this->login($this->other);
        $modToken = $this->login($this->moderator);

        $block = $this->patch($this->user['id'] . '/status', ['status' => 'blocked'], $modToken);

        self::assertSame(200, $block->getStatusCode());
        self::assertSame('blocked', $this->json($block)['data']['status']);
        $this->assertSessionDead($t1, 't1');
        $this->assertSessionDead($t2, 't2');
        self::assertSame(200, $this->me($adminToken)->getStatusCode());
        self::assertSame(200, $this->me($otherToken)->getStatusCode());
        self::assertSame(200, $this->me($modToken)->getStatusCode());
    }

    public function testBlockedUserCannotLogIn(): void
    {
        $modToken = $this->login($this->moderator);
        $this->patch($this->user['id'] . '/status', ['status' => 'blocked'], $modToken);

        $response = $this->loginResponse((string) $this->user['email']);

        self::assertSame(403, $response->getStatusCode());
        $payload = $this->json($response);
        self::assertSame('account_blocked', $payload['error']['code']);
        self::assertStringContainsString('zablokowane', (string) $payload['error']['message']);
        self::assertStringContainsString((string) Env::get('SUPPORT_EMAIL', 'kontakt@zmierzymyczas.pl'), (string) $payload['error']['message']);
        self::assertArrayNotHasKey('data', $payload);
        self::assertStringNotContainsString('"token"', (string) $response->getBody());

        $wrong = $this->loginResponse((string) $this->user['email'], 'wrong-password');
        self::assertSame(401, $wrong->getStatusCode());
        self::assertSame('invalid_credentials', $this->json($wrong)['error']['code']);
    }

    public function testUnblockDoesNotReviveOldSessions(): void
    {
        $t1 = $this->login($this->user);
        $modToken = $this->login($this->moderator);

        self::assertSame(200, $this->patch($this->user['id'] . '/status', ['status' => 'blocked'], $modToken)->getStatusCode());
        self::assertSame(200, $this->patch($this->user['id'] . '/status', ['status' => 'active'], $modToken)->getStatusCode());

        $this->assertSessionDead($t1);
        $fresh = $this->login($this->user);
        self::assertSame(200, $this->me($fresh)->getStatusCode());
    }

    public function testStatusIsCheckedOnEveryRequestWithoutVersionBump(): void
    {
        $eventId = $this->draftEventId();
        $token = $this->login($this->admin);
        $draftUrl = '/api/v1/events/' . $eventId;
        self::assertSame(200, $this->request('GET', $draftUrl, [], $this->auth($token))->getStatusCode());
        $before = $this->tokenVersion($this->admin);

        $this->pdo->prepare("UPDATE users SET status = 'blocked' WHERE id = :id")->execute(['id' => $this->admin['id']]);

        self::assertSame($before, $this->tokenVersion($this->admin), 'Direct SQL does not bump the version.');
        $this->assertSessionDead($token);
        self::assertSame(
            404,
            $this->request('GET', $draftUrl, [], $this->auth($token))->getStatusCode(),
            'A blocked user is anonymous on an optional-auth route (the draft stays hidden), not a 401.'
        );
    }

    public function testRoleChangeEndsSessions(): void
    {
        $t1 = $this->login($this->organizer);
        $adminToken = $this->login($this->admin);

        $response = $this->patch($this->organizer['id'] . '/role', ['role' => 'participant'], $adminToken);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('participant', $this->json($response)['data']['role']);
        $this->assertSessionDead($t1);
        $fresh = $this->login($this->organizer);
        $parts = explode('.', $fresh);
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        self::assertSame('participant', $claims['role']);
        self::assertSame(200, $this->me($fresh)->getStatusCode());
    }

    public function testNoOpRoleAndStatusWritesKeepSessions(): void
    {
        $t1 = $this->login($this->organizer);
        $adminToken = $this->login($this->admin);
        $before = $this->tokenVersion($this->organizer);

        $role = $this->patch($this->organizer['id'] . '/role', ['role' => 'organizer'], $adminToken);
        $status = $this->patch($this->organizer['id'] . '/status', ['status' => 'active'], $adminToken);

        self::assertSame(200, $role->getStatusCode());
        self::assertSame(200, $status->getStatusCode());
        self::assertSame($before, $this->tokenVersion($this->organizer));
        self::assertSame(200, $this->me($t1)->getStatusCode());
    }

    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function invalidWrites(): array
    {
        return [
            'unknown role' => ['role', ['role' => 'god'], 'invalid_role'],
            'missing role' => ['role', [], 'invalid_role'],
            'non-string role' => ['role', ['role' => ['admin']], 'invalid_role'],
            'unknown status' => ['status', ['status' => 'deleted'], 'invalid_status'],
            'missing status' => ['status', [], 'invalid_status'],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidWrites')]
    public function testInvalidRoleOrStatusIs422(string $route, array $body, string $code): void
    {
        $adminToken = $this->login($this->admin);
        $t1 = $this->login($this->user);
        $before = $this->users->find((string) $this->user['id']);
        $version = $this->tokenVersion($this->user);

        $response = $this->patch($this->user['id'] . '/' . $route, $body, $adminToken);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame($code, $this->json($response)['error']['code']);
        self::assertSame($before, $this->users->find((string) $this->user['id']));
        self::assertSame($version, $this->tokenVersion($this->user));
        self::assertSame(200, $this->me($t1)->getStatusCode());
    }

    public function testMissingAccountIs404(): void
    {
        $adminToken = $this->login($this->admin);

        foreach ([['role', ['role' => 'participant']], ['status', ['status' => 'blocked']]] as [$route, $body]) {
            $response = $this->patch('no-such-user-' . $this->prefix . '/' . $route, $body, $adminToken);
            self::assertSame(404, $response->getStatusCode(), $route);
            self::assertSame('not_found', $this->json($response)['error']['code'], $route);
        }
    }

    public function testAdminEmailChangeUnverifiesAndEndsSessions(): void
    {
        $this->users->markEmailVerified((string) $this->user['id']);
        $t1 = $this->login($this->user);
        $adminToken = $this->login($this->admin);
        $newEmail = $this->prefix . '-u-new@example.test';

        $response = $this->patch((string) $this->user['id'], ['email' => $newEmail], $adminToken);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame($newEmail, $data['email']);
        self::assertFalse($data['emailVerified']);
        $stmt = $this->pdo->prepare('SELECT email_verified_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $this->user['id']]);
        self::assertNull($stmt->fetchColumn());
        $this->assertSessionDead($t1);
        self::assertSame(200, $this->loginResponse($newEmail)->getStatusCode());
        self::assertSame(401, $this->loginResponse((string) $this->user['email'])->getStatusCode());
    }

    public function testAdminEditWithoutEmailChangeKeepsSessions(): void
    {
        $this->users->markEmailVerified((string) $this->user['id']);
        $t1 = $this->login($this->user);
        $adminToken = $this->login($this->admin);
        $before = $this->tokenVersion($this->user);

        $name = $this->patch((string) $this->user['id'], ['name' => 'Renamed Account'], $adminToken);
        $sameEmail = $this->patch((string) $this->user['id'], ['email' => strtoupper((string) $this->user['email'])], $adminToken);

        self::assertSame(200, $name->getStatusCode());
        self::assertSame(200, $sameEmail->getStatusCode());
        self::assertTrue($this->json($name)['data']['emailVerified']);
        self::assertTrue($this->json($sameEmail)['data']['emailVerified']);
        self::assertSame($before, $this->tokenVersion($this->user));
        self::assertSame(200, $this->me($t1)->getStatusCode());
    }

    public function testUnrelatedSessionsAreUnaffected(): void
    {
        $adminToken = $this->login($this->admin);
        $modToken = $this->login($this->moderator);
        $otherToken = $this->login($this->other);
        $organizerToken = $this->login($this->organizer);
        $otherVersion = $this->tokenVersion($this->other);

        self::assertSame(200, $this->patch($this->user['id'] . '/status', ['status' => 'blocked'], $modToken)->getStatusCode());
        self::assertSame(200, $this->patch($this->user['id'] . '/role', ['role' => 'organizer'], $adminToken)->getStatusCode());
        self::assertSame(200, $this->patch((string) $this->user['id'], ['email' => $this->prefix . '-u-moved@example.test'], $adminToken)->getStatusCode());

        foreach (['admin' => $adminToken, 'moderator' => $modToken, 'other' => $otherToken, 'organizer' => $organizerToken] as $label => $token) {
            self::assertSame(200, $this->me($token)->getStatusCode(), $label);
        }
        self::assertSame($otherVersion, $this->tokenVersion($this->other));
    }

    public function testAccountLockedMailIsAttemptedOnlyOnTheActiveToBlockedEdge(): void
    {
        // (setUp configures an unusable mailer for this test.) So an attempted send surfaces as 500 after the status
        // was already written (pre-existing behaviour, reported as out-of-scope). That makes the
        // mail edge observable without a fake transport: 500 = mail attempted, 200 = no mail.
        $modToken = $this->login($this->moderator);

        $first = $this->patch($this->user['id'] . '/status', ['status' => 'blocked'], $modToken);
        self::assertSame(500, $first->getStatusCode(), 'active -> blocked attempts the mail.');
        self::assertSame('blocked', $this->users->find((string) $this->user['id'])['status']);

        $again = $this->patch($this->user['id'] . '/status', ['status' => 'blocked'], $modToken);
        self::assertSame(200, $again->getStatusCode(), 'Re-blocking a blocked account attempts no mail.');
    }

    public function testAdminCreatedAccountStartsUnverified(): void
    {
        $adminToken = $this->login($this->admin);
        $email = $this->prefix . '-created@example.test';

        $response = $this->request(
            'POST',
            '/api/v1/users',
            ['name' => 'Created By Staff', 'email' => $email, 'role' => 'participant'],
            $this->auth($adminToken)
        );

        self::assertSame(201, $response->getStatusCode());
        $created = $this->json($response)['data'];
        self::assertFalse($created['emailVerified']);
        $fetched = $this->request('GET', '/api/v1/users/' . $created['id'], [], $this->auth($adminToken));
        self::assertFalse($this->json($fetched)['data']['emailVerified']);
        // PP.82: no known default password; the owner sets one from the e-mailed link.
        self::assertSame(401, $this->loginResponse($email)->getStatusCode());
    }

    public function testDeletedAccountAndSelfEscalationRegressions(): void
    {
        $adminToken = $this->login($this->admin);
        $deletedToken = $this->login($this->other);
        $selfToken = $this->login($this->user);

        self::assertSame(200, $this->request('DELETE', '/api/v1/users/' . $this->other['id'], [], $this->auth($adminToken))->getStatusCode());
        $this->assertSessionDead($deletedToken);

        $self = $this->patch((string) $this->user['id'], ['role' => 'admin'], $selfToken);
        self::assertSame(422, $self->getStatusCode());
        self::assertSame('field_not_editable', $this->json($self)['error']['code']);

        self::assertSame(403, $this->patch($this->user['id'] . '/role', ['role' => 'admin'], $selfToken)->getStatusCode());
    }
}

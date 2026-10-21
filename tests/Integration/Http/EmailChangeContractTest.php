<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Domain\Auth\ActionTokenPurpose;
use ZmierzymyCzas\Domain\Auth\ActionTokenRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * Verified self e-mail change. Central Mailer is "configured" with an unusable URL scheme
 * (see PasswordResetContractTest): the outbox row is written, the HTTP call fails at once and the
 * route swallows it.
 */
final class EmailChangeContractTest extends HttpTestCase
{
    private const CHANGE = '/api/v1/auth/email/change';
    private const CONFIRM = '/api/v1/auth/email/change/confirm';

    private UserRepository $users;
    private string $prefix;
    /** @var array<string, mixed> */
    private array $user;
    /** @var array<string, mixed> */
    private array $other;

    protected function setUp(): void
    {
        Env::set('CENTRAL_MAILER_API_KEY', 'phpunit-key');
        Env::set('CENTRAL_MAILER_BASE_URL', 'unusable://mailer.invalid');
        Env::set('APP_URL', 'https://app.example.test');
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'http-ec-' . bin2hex(random_bytes(4));
        $this->user = $this->users->create([
            'name' => 'Change User',
            'email' => $this->prefix . '-u@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $this->other = $this->users->create([
            'name' => 'Other User',
            'email' => $this->prefix . '-other@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
    }

    protected function tearDown(): void
    {
        Env::restore('CENTRAL_MAILER_API_KEY');
        Env::restore('CENTRAL_MAILER_BASE_URL');
        Env::restore('APP_URL');
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE recipient LIKE :email')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM user_action_tokens WHERE user_id IN (SELECT id FROM users WHERE email LIKE :email)')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    private function newAddress(string $suffix = 'new'): string
    {
        return $this->prefix . '-' . $suffix . '@example.test';
    }

    /** @return array<string, string> */
    private function loginHeaders(string $email, string $password = 'demo1234'): array
    {
        $login = $this->request('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password]);
        self::assertSame(200, $login->getStatusCode());
        return ['Authorization' => 'Bearer ' . $this->json($login)['data']['token']];
    }

    private function requestChange(array $body, ?array $headers = null): ResponseInterface
    {
        return $this->request('POST', self::CHANGE, $body, $headers ?? $this->loginHeaders((string) $this->user['email']));
    }

    private function emailOf(string $id): string
    {
        $stmt = $this->pdo->prepare('SELECT email FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return (string) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function tokenRows(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_action_tokens WHERE user_id = :id AND purpose = :purpose ORDER BY created_at, id');
        $stmt->execute(['id' => $this->user['id'], 'purpose' => ActionTokenPurpose::EMAIL_CHANGE]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** The raw token of the open change link, read from the outbox row keyed by that token's id. */
    private function rawToken(): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.html FROM central_mailer_outbox o JOIN user_action_tokens t ON t.id = o.source_entity_id
             WHERE t.user_id = :id AND t.purpose = :purpose AND t.used_at IS NULL'
        );
        $stmt->execute(['id' => $this->user['id'], 'purpose' => ActionTokenPurpose::EMAIL_CHANGE]);
        $html = $stmt->fetchColumn();
        self::assertIsString($html);
        self::assertSame(1, preg_match('~https://app\.example\.test/confirm-email-change\?token=([0-9a-f]{64})~', $html, $m));
        return $m[1];
    }

    private function confirm(string $token): ResponseInterface
    {
        return $this->request('POST', self::CONFIRM, ['token' => $token]);
    }

    public function testAnonymousIsRejected(): void
    {
        $response = $this->request('POST', self::CHANGE, ['newEmail' => $this->newAddress(), 'currentPassword' => 'demo1234']);

        self::assertSame(401, $response->getStatusCode());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: int, 2: string}> */
    public static function rejectedRequests(): array
    {
        return [
            'missing fields' => [[], 422, 'validation_error'],
            'missing password' => [['newEmail' => 'x@example.test'], 422, 'validation_error'],
            'non-string email' => [['newEmail' => 5, 'currentPassword' => 'demo1234'], 422, 'validation_error'],
            'bad address' => [['newEmail' => 'not-an-email', 'currentPassword' => 'demo1234'], 422, 'invalid_email'],
            'wrong password' => [['newEmail' => '@new', 'currentPassword' => 'wrong-password'], 422, 'invalid_current_password'],
            'own address' => [['newEmail' => '@own', 'currentPassword' => 'demo1234'], 422, 'email_unchanged'],
            'own address other case' => [['newEmail' => '@OWN', 'currentPassword' => 'demo1234'], 422, 'email_unchanged'],
            'taken address' => [['newEmail' => '@other', 'currentPassword' => 'demo1234'], 409, 'email_taken'],
            'taken address other case' => [['newEmail' => '@OTHER', 'currentPassword' => 'demo1234'], 409, 'email_taken'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @dataProvider rejectedRequests
     */
    public function testRejectedRequestsChangeNothing(array $body, int $status, string $code): void
    {
        $replace = [
            '@new' => $this->newAddress(),
            '@own' => (string) $this->user['email'],
            '@OWN' => strtoupper((string) $this->user['email']),
            '@other' => (string) $this->other['email'],
            '@OTHER' => strtoupper((string) $this->other['email']),
        ];
        if (isset($body['newEmail']) && is_string($body['newEmail']) && isset($replace[$body['newEmail']])) {
            $body['newEmail'] = $replace[$body['newEmail']];
        }

        $response = $this->requestChange($body);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($code, $this->json($response)['error']['code']);
        self::assertCount(0, $this->tokenRows());
        self::assertSame($this->user['email'], $this->emailOf((string) $this->user['id']));
    }

    public function testValidRequestQueuesLinkToNewAddressAndKeepsCurrentEmail(): void
    {
        $response = $this->requestChange(['newEmail' => '  ' . strtoupper($this->newAddress()) . ' ', 'currentPassword' => 'demo1234']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->newAddress(), $this->json($response)['data']['pendingEmail']);
        self::assertSame($this->user['email'], $this->emailOf((string) $this->user['id']));
        $rows = $this->tokenRows();
        self::assertCount(1, $rows);
        self::assertSame($this->newAddress(), $rows[0]['new_email']);

        $stmt = $this->pdo->prepare('SELECT idempotency_key FROM central_mailer_outbox WHERE recipient = :email');
        $stmt->execute(['email' => $this->newAddress()]);
        self::assertStringStartsWith($rows[0]['id'] . ':', (string) $stmt->fetchColumn());
        $old = $this->pdo->prepare('SELECT COUNT(*) FROM central_mailer_outbox WHERE recipient = :email');
        $old->execute(['email' => $this->user['email']]);
        self::assertSame(0, (int) $old->fetchColumn(), 'Nothing is mailed to the old address.');
    }

    public function testConfirmChangesEmailVerifiesItAndEndsAllSessions(): void
    {
        $t1 = $this->loginHeaders((string) $this->user['email']);
        $t2 = $this->loginHeaders((string) $this->user['email']);
        $this->requestChange(['newEmail' => $this->newAddress(), 'currentPassword' => 'demo1234'], $t1);

        $response = $this->confirm($this->rawToken());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->newAddress(), $this->emailOf((string) $this->user['id']));
        $account = $this->users->find((string) $this->user['id']);
        self::assertTrue($account['emailVerified']);
        foreach ([$t1, $t2] as $headers) {
            self::assertSame(401, $this->request('GET', '/api/v1/auth/me', [], $headers)->getStatusCode());
        }
        self::assertSame(200, $this->request('POST', '/api/v1/auth/login', ['email' => $this->newAddress(), 'password' => 'demo1234'])->getStatusCode());
        self::assertSame(401, $this->request('POST', '/api/v1/auth/login', ['email' => $this->user['email'], 'password' => 'demo1234'])->getStatusCode());
    }

    public function testConfirmTokenIsSingleUseAndRejectsGarbage(): void
    {
        $this->requestChange(['newEmail' => $this->newAddress(), 'currentPassword' => 'demo1234']);
        $raw = $this->rawToken();
        self::assertSame(200, $this->confirm($raw)->getStatusCode());

        $legacy = $this->jwt->issue(['sub' => $this->user['id'], 'purpose' => 'email_change']);
        foreach ([$raw, $legacy, 'garbage', str_repeat('0', 64), ''] as $token) {
            $response = $this->confirm($token);
            self::assertSame(400, $response->getStatusCode(), $token);
            self::assertSame('invalid_token', $this->json($response)['error']['code']);
        }
    }

    public function testOtherPurposeTokenCannotConfirmAnEmailChange(): void
    {
        $tokens = new ActionTokenRepository($this->pdo);
        $verification = $tokens->issue((string) $this->user['id'], ActionTokenPurpose::EMAIL_VERIFICATION, 3600, $this->newAddress());

        self::assertSame(400, $this->confirm($verification['raw'])->getStatusCode());
        self::assertSame($this->user['email'], $this->emailOf((string) $this->user['id']));
    }

    public function testAddressTakenBetweenRequestAndConfirmIs409(): void
    {
        $this->requestChange(['newEmail' => $this->newAddress(), 'currentPassword' => 'demo1234']);
        $raw = $this->rawToken();
        $this->pdo->prepare('UPDATE users SET email = :email WHERE id = :id')
            ->execute(['email' => $this->newAddress(), 'id' => $this->other['id']]);

        $response = $this->confirm($raw);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('email_taken', $this->json($response)['error']['code']);
        self::assertSame($this->user['email'], $this->emailOf((string) $this->user['id']));
    }

    public function testNewRequestInvalidatesTheOlderLinkAndCooldownApplies(): void
    {
        $this->requestChange(['newEmail' => $this->newAddress('first'), 'currentPassword' => 'demo1234']);
        $first = $this->rawToken();

        $repeat = $this->requestChange(['newEmail' => $this->newAddress('second'), 'currentPassword' => 'demo1234']);
        self::assertSame(429, $repeat->getStatusCode());
        self::assertSame('too_many_requests', $this->json($repeat)['error']['code']);

        $this->pdo->prepare('UPDATE user_action_tokens SET created_at = created_at - INTERVAL 2 MINUTE WHERE user_id = :id')
            ->execute(['id' => $this->user['id']]);
        self::assertSame(200, $this->requestChange(['newEmail' => $this->newAddress('second'), 'currentPassword' => 'demo1234'])->getStatusCode());

        self::assertSame(400, $this->confirm($first)->getStatusCode(), 'The older link must be invalidated.');
        self::assertSame(200, $this->confirm($this->rawToken())->getStatusCode());
        self::assertSame($this->newAddress('second'), $this->emailOf((string) $this->user['id']));
    }

    public function testSelfPatchStillRejectsEmailChangeWithPointerToTheNewFlow(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/users/' . $this->user['id'],
            ['email' => $this->newAddress()],
            $this->loginHeaders((string) $this->user['email'])
        );

        self::assertSame(422, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame('email_change_not_allowed', $error['code']);
        self::assertStringContainsString('Zmień adres e-mail', $error['message']);
        self::assertSame($this->user['email'], $this->emailOf((string) $this->user['id']));
    }

    public function testAdminCanStillEditAnotherUsersEmail(): void
    {
        $admin = $this->users->create([
            'name' => 'Change Admin',
            'email' => $this->newAddress('admin'),
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
        $target = $this->newAddress('edited');

        $response = $this->request(
            'PATCH',
            '/api/v1/users/' . $this->user['id'],
            ['email' => $target],
            $this->loginHeaders((string) $admin['email'])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($target, $this->emailOf((string) $this->user['id']));
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Auth\ActionTokenPurpose;
use ZmierzymyCzas\Domain\Auth\ActionTokenRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * E-mail verification. Central Mailer is "configured" with an unusable URL scheme so the
 * outbox row (idempotency key, html with the link) is written and the HTTP call then fails at once;
 * the routes must swallow that failure (see PasswordResetContractTest).
 */
final class EmailVerificationContractTest extends HttpTestCase
{
    private const VERIFY = '/api/v1/auth/email/verify';
    private const RESEND = '/api/v1/auth/email/verification/resend';

    private UserRepository $users;
    private string $prefix;

    protected function setUp(): void
    {
        Env::set('CENTRAL_MAILER_API_KEY', 'phpunit-key');
        Env::set('CENTRAL_MAILER_BASE_URL', 'unusable://mailer.invalid');
        Env::set('APP_URL', 'https://app.example.test');
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'http-ev-' . bin2hex(random_bytes(4));
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

    /** @return array<string, mixed> */
    private function register(string $suffix = 'u'): array
    {
        $response = $this->request('POST', '/api/v1/auth/register', [
            'name' => 'Verify User',
            'email' => $this->prefix . '-' . $suffix . '@example.test',
            'password' => 'demo1234',
        ]);
        self::assertSame(201, $response->getStatusCode());
        return $this->json($response)['data'];
    }

    /** @return array<string, string> */
    private function headersFor(array $user): array
    {
        $login = $this->request('POST', '/api/v1/auth/login', ['email' => $user['email'], 'password' => 'demo1234']);
        self::assertSame(200, $login->getStatusCode());
        return ['Authorization' => 'Bearer ' . $this->json($login)['data']['token']];
    }

    /** @return list<array<string, mixed>> */
    private function tokenRows(string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_action_tokens WHERE user_id = :id ORDER BY created_at, id');
        $stmt->execute(['id' => $userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function outboxRows(string $email): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM central_mailer_outbox WHERE recipient = :email ORDER BY id');
        $stmt->execute(['email' => $email]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** The raw token of the newest (still open) link, read from the outbox row keyed by that token's id. */
    private function rawTokenFromOutbox(string $email): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.html FROM central_mailer_outbox o
             JOIN user_action_tokens t ON t.id = o.source_entity_id
             JOIN users u ON u.id = t.user_id
             WHERE o.recipient = :email AND t.used_at IS NULL AND u.email = :email2'
        );
        $stmt->execute(['email' => $email, 'email2' => $email]);
        $html = $stmt->fetchColumn();
        self::assertIsString($html, 'No open token has a queued mail.');
        self::assertSame(
            1,
            preg_match('~https://app\.example\.test/verify-email\?token=([0-9a-f]{64})~', $html, $m),
            'The mail must contain the verification link.'
        );
        return $m[1];
    }

    private function verify(string $token): ResponseInterface
    {
        return $this->request('POST', self::VERIFY, ['token' => $token]);
    }

    public function testRegisterCreatesVerificationTokenAndMailKeyedByTokenId(): void
    {
        $user = $this->register();

        $rows = $this->tokenRows((string) $user['id']);
        self::assertCount(1, $rows);
        self::assertSame('email_verification', $rows[0]['purpose']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rows[0]['token_hash']);

        $outbox = $this->outboxRows((string) $user['email']);
        self::assertCount(1, $outbox);
        self::assertStringStartsWith($rows[0]['id'] . ':', $outbox[0]['idempotency_key']);
        self::assertSame(hash('sha256', $this->rawTokenFromOutbox((string) $user['email'])), $rows[0]['token_hash']);
        self::assertFalse($user['emailVerified']);
    }

    public function testVerifyMarksEmailVerifiedAndTokenIsSingleUse(): void
    {
        $user = $this->register();
        $headers = $this->headersFor($user);
        $me = fn () => $this->json($this->request('GET', '/api/v1/auth/me', [], $headers))['data'];
        self::assertFalse($me()['emailVerified']);

        $raw = $this->rawTokenFromOutbox((string) $user['email']);
        self::assertSame(200, $this->verify($raw)->getStatusCode());

        self::assertTrue($me()['emailVerified'], 'Verifying must not end the session.');
        $again = $this->verify($raw);
        self::assertSame(400, $again->getStatusCode());
        self::assertSame('invalid_token', $this->json($again)['error']['code']);
    }

    public function testVerifyRejectsBadAndWrongPurposeTokens(): void
    {
        $user = $this->register();
        $tokens = new ActionTokenRepository($this->pdo);
        $wrong = $tokens->issue((string) $user['id'], ActionTokenPurpose::PASSWORD_RESET, 1800);
        $legacy = $this->jwt->issue(['sub' => $user['id'], 'purpose' => 'email_verification']);

        foreach ([$wrong['raw'], $legacy, 'garbage', str_repeat('0', 64)] as $token) {
            $response = $this->verify($token);
            self::assertSame(400, $response->getStatusCode(), $token);
            self::assertSame('invalid_token', $this->json($response)['error']['code']);
        }
        $missing = $this->request('POST', self::VERIFY);
        self::assertSame(400, $missing->getStatusCode());
    }

    public function testNewAccountIsUnverifiedAndPreCutoffAccountIsVerified(): void
    {
        $fresh = $this->register('fresh');
        self::assertFalse($this->users->find((string) $fresh['id'])['emailVerified']);

        // The backfill cutoff is a literal date: pretend this account was created after it.
        $this->pdo->prepare("UPDATE users SET created_at = '2037-01-01 10:00:00' WHERE id = :id")->execute(['id' => $fresh['id']]);
        $old = $this->register('old');
        $this->pdo->prepare("UPDATE users SET created_at = '2020-01-01 10:00:00', email_verified_at = NULL WHERE id = :id")
            ->execute(['id' => $old['id']]);
        Migrator::applyFile($this->pdo, Migrator::migrationsDir() . '/064_pp7_auth_tokens.sql');

        self::assertTrue($this->users->find((string) $old['id'])['emailVerified']);
        self::assertFalse($this->users->find((string) $fresh['id'])['emailVerified'], 'Accounts created after the cutoff are never auto-verified.');
    }

    public function testResendRequiresASession(): void
    {
        self::assertSame(401, $this->request('POST', self::RESEND)->getStatusCode());
    }

    public function testResendForVerifiedAccountDoesNothing(): void
    {
        $user = $this->register();
        $this->verify($this->rawTokenFromOutbox((string) $user['email']));
        $before = count($this->tokenRows((string) $user['id']));

        $response = $this->request('POST', self::RESEND, [], $this->headersFor($user));

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->json($response)['data']['alreadyVerified']);
        self::assertCount($before, $this->tokenRows((string) $user['id']));
    }

    public function testResendForUnverifiedAccountSendsNewLinkThenCoolsDown(): void
    {
        $user = $this->register();
        $headers = $this->headersFor($user);
        $firstRaw = $this->rawTokenFromOutbox((string) $user['email']);
        $this->pdo->prepare('UPDATE user_action_tokens SET created_at = created_at - INTERVAL 2 MINUTE WHERE user_id = :id')
            ->execute(['id' => $user['id']]);

        $response = $this->request('POST', self::RESEND, [], $headers);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->json($response)['data']['alreadyVerified']);
        self::assertCount(2, $this->tokenRows((string) $user['id']));
        self::assertCount(2, $this->outboxRows((string) $user['email']));
        self::assertSame(400, $this->verify($firstRaw)->getStatusCode(), 'The older link must be invalidated.');
        self::assertSame(200, $this->verify($this->rawTokenFromOutbox((string) $user['email']))->getStatusCode());

        // Immediate repeat (for a fresh unverified account) is the cooldown.
        $other = $this->register('two');
        $otherHeaders = $this->headersFor($other);
        $repeat = $this->request('POST', self::RESEND, [], $otherHeaders);
        self::assertSame(429, $repeat->getStatusCode());
        self::assertSame('too_many_requests', $this->json($repeat)['error']['code']);
        self::assertCount(1, $this->tokenRows((string) $other['id']));
    }
}

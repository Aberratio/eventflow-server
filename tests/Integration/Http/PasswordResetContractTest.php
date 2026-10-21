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
 * Password reset with hashed one-time DB tokens.
 *
 * Central Mailer is "configured" here with an unusable URL scheme: CriticalMailer writes the outbox
 * row (idempotency key, rendered html with the link) and the HTTP call then fails at once. That is
 * enough to assert what is queued and to read the raw token back from the outbox html, exactly as
 * the verification recipe does; the route must swallow the failure (no 500 for known e-mails only).
 */
final class PasswordResetContractTest extends HttpTestCase
{
    private const RESET = '/api/v1/auth/password/reset';
    private const CONFIRM = '/api/v1/auth/password/reset/confirm';

    private UserRepository $users;
    private ActionTokenRepository $tokens;
    private string $prefix;
    /** @var array<string, mixed> */
    private array $user;
    private string $errorLogFile = '';
    private string|false $previousErrorLog = false;

    protected function setUp(): void
    {
        Env::set('CENTRAL_MAILER_API_KEY', 'phpunit-key');
        Env::set('CENTRAL_MAILER_BASE_URL', 'unusable://mailer.invalid');
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->tokens = new ActionTokenRepository($this->pdo);
        $this->prefix = 'http-pr-' . bin2hex(random_bytes(4));
        $this->user = $this->users->create([
            'name' => 'Reset User',
            'email' => $this->prefix . '-u@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);

        $this->errorLogFile = (string) tempnam(sys_get_temp_dir(), 'pp7log');
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        if ($this->errorLogFile !== '') {
            ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
            @unlink($this->errorLogFile);
        }
        Env::restore('CENTRAL_MAILER_API_KEY');
        Env::restore('CENTRAL_MAILER_BASE_URL');
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE recipient LIKE :email')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM user_action_tokens WHERE user_id IN (SELECT id FROM users WHERE email LIKE :email)')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    private function requestReset(string $email): ResponseInterface
    {
        return $this->request('POST', self::RESET, ['email' => $email]);
    }

    private function confirm(string $token, string $password): ResponseInterface
    {
        return $this->request('POST', self::CONFIRM, ['token' => $token, 'newPassword' => $password]);
    }

    /** @return list<array<string, mixed>> */
    private function tokenRows(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_action_tokens WHERE user_id = :id ORDER BY created_at, id');
        $stmt->execute(['id' => $this->user['id']]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function outboxRows(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM central_mailer_outbox WHERE recipient = :email ORDER BY id');
        $stmt->execute(['email' => $this->user['email']]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function ageTokens(int $minutes): void
    {
        $this->pdo->prepare('UPDATE user_action_tokens SET created_at = created_at - INTERVAL ' . $minutes . ' MINUTE WHERE user_id = :id')
            ->execute(['id' => $this->user['id']]);
    }

    /** The raw token the way the e-mail carries it: read from the newest outbox html. */
    private function rawTokenFromOutbox(): string
    {
        $rows = $this->outboxRows();
        self::assertNotEmpty($rows, 'No outbox row was queued.');
        $html = (string) end($rows)['html'];
        self::assertSame(1, preg_match('~/reset-password\?token=([0-9a-f]{64})~', $html, $m), 'The mail must contain the reset link.');
        return $m[1];
    }

    private function login(string $password): ResponseInterface
    {
        return $this->request('POST', '/api/v1/auth/login', ['email' => $this->user['email'], 'password' => $password]);
    }

    public function testResponseIdenticalForKnownUnknownAndEmpty(): void
    {
        $known = $this->requestReset((string) $this->user['email']);
        $cooldownHit = $this->requestReset((string) $this->user['email']);
        $unknown = $this->requestReset($this->prefix . '-nobody@example.test');
        $empty = $this->request('POST', self::RESET);

        $describe = static function (ResponseInterface $r): array {
            $headers = $r->getHeaders();
            unset($headers['Date']);
            return [$r->getStatusCode(), (string) $r->getBody(), $headers];
        };
        $reference = $describe($known);
        self::assertSame(200, $reference[0]);
        self::assertSame($reference, $describe($cooldownHit));
        self::assertSame($reference, $describe($unknown));
        self::assertSame($reference, $describe($empty));
        self::assertCount(1, $this->tokenRows());
    }

    public function testRequestCreatesHashedTokenKeyedByTokenId(): void
    {
        self::assertSame(200, $this->requestReset((string) $this->user['email'])->getStatusCode());

        $rows = $this->tokenRows();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('password_reset', $row['purpose']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['token_hash']);
        self::assertNull($row['used_at']);
        $seconds = (int) $this->pdo->query(
            'SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) FROM user_action_tokens WHERE id = ' . $this->pdo->quote($row['id'])
        )->fetchColumn();
        self::assertGreaterThan(1790, $seconds);
        self::assertLessThanOrEqual(1800, $seconds);

        $outbox = $this->outboxRows();
        self::assertCount(1, $outbox);
        self::assertStringStartsWith($row['id'] . ':', $outbox[0]['idempotency_key']);
        self::assertSame($row['id'], $outbox[0]['source_entity_id']);
        self::assertStringNotContainsString((string) $this->user['id'], $outbox[0]['idempotency_key']);
        self::assertSame(hash('sha256', $this->rawTokenFromOutbox()), $row['token_hash']);
    }

    public function testSecondRequestAfterCooldownSendsSecondMail(): void
    {
        $this->requestReset((string) $this->user['email']);
        $this->ageTokens(2);
        $this->requestReset((string) $this->user['email']);

        $outbox = $this->outboxRows();
        self::assertCount(2, $outbox, 'The mail idempotency key must not collapse repeated requests.');
        self::assertNotSame($outbox[0]['idempotency_key'], $outbox[1]['idempotency_key']);

        $rows = $this->tokenRows();
        self::assertCount(2, $rows);
        self::assertNotNull($rows[0]['used_at'], 'The older link must be invalidated by the newer request.');
        self::assertNull($rows[1]['used_at']);
    }

    public function testCooldownAndHourlyCap(): void
    {
        $this->requestReset((string) $this->user['email']);
        $this->requestReset((string) $this->user['email']);
        self::assertCount(1, $this->tokenRows(), 'A request within 60 s does nothing.');

        for ($i = 0; $i < 4; $i++) {
            $this->ageTokens(2);
            $this->requestReset((string) $this->user['email']);
        }
        self::assertCount(5, $this->tokenRows());

        $this->ageTokens(2);
        self::assertSame(200, $this->requestReset((string) $this->user['email'])->getStatusCode());
        self::assertCount(5, $this->tokenRows(), 'The sixth request within an hour does nothing.');
        self::assertCount(5, $this->outboxRows());
    }

    public function testConfirmSetsPasswordAndKillsSessions(): void
    {
        $t1 = (string) $this->json($this->login('demo1234'))['data']['token'];
        $t2 = (string) $this->json($this->login('demo1234'))['data']['token'];
        $this->requestReset((string) $this->user['email']);

        $response = $this->confirm($this->rawTokenFromOutbox(), 'BrandNewPass1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(200, $this->login('BrandNewPass1')->getStatusCode());
        self::assertSame(401, $this->login('demo1234')->getStatusCode());
        foreach ([$t1, $t2] as $old) {
            self::assertSame(401, $this->request('GET', '/api/v1/auth/me', [], ['Authorization' => 'Bearer ' . $old])->getStatusCode());
        }
    }

    public function testTokenSingleUse(): void
    {
        $this->requestReset((string) $this->user['email']);
        $raw = $this->rawTokenFromOutbox();

        self::assertSame(200, $this->confirm($raw, 'BrandNewPass1')->getStatusCode());
        $again = $this->confirm($raw, 'AnotherPass22');

        self::assertSame(400, $again->getStatusCode());
        self::assertSame('invalid_token', $this->json($again)['error']['code']);
        self::assertSame(401, $this->login('AnotherPass22')->getStatusCode());
    }

    public function testExpiredTokenRejected(): void
    {
        $this->requestReset((string) $this->user['email']);
        $raw = $this->rawTokenFromOutbox();
        $this->pdo->prepare('UPDATE user_action_tokens SET expires_at = NOW() - INTERVAL 1 SECOND WHERE user_id = :id')
            ->execute(['id' => $this->user['id']]);

        $response = $this->confirm($raw, 'BrandNewPass1');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_token', $this->json($response)['error']['code']);
        self::assertSame(200, $this->login('demo1234')->getStatusCode());
    }

    public function testMalformedAndLegacyJwtRejected(): void
    {
        $legacy = $this->jwt->issue(['sub' => $this->user['id'], 'purpose' => 'password_reset']);
        $bad = [$legacy, 'not-hex', str_repeat('0', 64), str_repeat('g', 64), substr(bin2hex(random_bytes(32)), 0, 63)];

        foreach ($bad as $token) {
            $response = $this->confirm($token, 'BrandNewPass1');
            self::assertSame(400, $response->getStatusCode(), $token);
            self::assertSame('invalid_token', $this->json($response)['error']['code']);
        }
        self::assertSame(200, $this->login('demo1234')->getStatusCode());
    }

    public function testMissingFieldsAre422(): void
    {
        foreach ([[], ['token' => 'x'], ['newPassword' => 'BrandNewPass1'], ['token' => 123, 'newPassword' => 'BrandNewPass1']] as $body) {
            $response = $this->request('POST', self::CONFIRM, $body);
            self::assertSame(422, $response->getStatusCode());
            self::assertSame('validation_error', $this->json($response)['error']['code']);
        }
    }

    public function testWeakPasswordDoesNotBurnToken(): void
    {
        $this->requestReset((string) $this->user['email']);
        $raw = $this->rawTokenFromOutbox();

        $short = $this->confirm($raw, 'short');
        self::assertSame(422, $short->getStatusCode());
        self::assertSame('password_too_short', $this->json($short)['error']['code']);
        $long = $this->confirm($raw, str_repeat('a', 73));
        self::assertSame(422, $long->getStatusCode());
        self::assertSame('password_too_long', $this->json($long)['error']['code']);

        self::assertNull($this->tokenRows()[0]['used_at']);
        self::assertSame(200, $this->confirm($raw, 'BrandNewPass1')->getStatusCode());
    }

    public function testWrongPurposeRejected(): void
    {
        foreach ([ActionTokenPurpose::EMAIL_VERIFICATION, ActionTokenPurpose::EMAIL_CHANGE] as $purpose) {
            $token = $this->tokens->issue((string) $this->user['id'], $purpose, 3600, $purpose === ActionTokenPurpose::EMAIL_CHANGE ? $this->prefix . '-new@example.test' : null);

            $response = $this->confirm($token['raw'], 'BrandNewPass1');

            self::assertSame(400, $response->getStatusCode(), $purpose);
            self::assertSame('invalid_token', $this->json($response)['error']['code']);
        }
        self::assertSame(200, $this->login('demo1234')->getStatusCode());
    }

    public function testRawTokenNotStoredAndNotLogged(): void
    {
        $this->requestReset((string) $this->user['email']);
        $raw = $this->rawTokenFromOutbox();
        $this->confirm($raw, 'BrandNewPass1');
        $this->confirm($raw, 'BrandNewPass1');

        foreach ($this->tokenRows() as $row) {
            foreach ($row as $value) {
                self::assertStringNotContainsString($raw, (string) $value);
            }
        }
        $userRow = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $userRow->execute(['id' => $this->user['id']]);
        foreach ((array) $userRow->fetch(\PDO::FETCH_ASSOC) as $value) {
            self::assertStringNotContainsString($raw, (string) $value);
        }
        foreach ($this->outboxRows() as $row) {
            foreach ($row as $column => $value) {
                if ($column !== 'html') {
                    self::assertStringNotContainsString($raw, (string) $value, $column);
                }
            }
        }

        $log = (string) file_get_contents($this->errorLogFile);
        // A mailer failure no longer throws; the durable outbox row waits for mail.outbox_retry.
        $statuses = array_column($this->outboxRows(), 'status');
        self::assertNotSame([], $statuses);
        self::assertSame(['failed'], array_values(array_unique($statuses)), 'The undelivered mail stays in the outbox for the retry job.');
        self::assertStringNotContainsString($raw, $log);
        self::assertStringNotContainsString('token=', $log);
        self::assertStringNotContainsString((string) $this->user['email'], $log);
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Auth\ActionTokenPurpose;
use ZmierzymyCzas\Domain\Auth\ActionTokenRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;

final class ActionTokenRepositoryTest extends TestCase
{
    private PDO $pdo;
    private ActionTokenRepository $tokens;
    private UserRepository $users;
    private string $prefix;
    /** @var array<string, mixed> */
    private array $user;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->pdo = Connection::fromEnv();
        Migrator::applyFile($this->pdo, Migrator::migrationsDir() . '/064_pp7_auth_tokens.sql');
        $this->tokens = new ActionTokenRepository($this->pdo);
        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'uat-' . bin2hex(random_bytes(4));
        $this->user = $this->users->create([
            'name' => 'Token User',
            'email' => $this->prefix . '@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->user)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM user_action_tokens WHERE user_id = :id')->execute(['id' => $this->user['id']]);
        $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->user['id']]);
    }

    private function row(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_action_tokens WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function testIssueStoresOnlyTheHashAndTheDbDerivedExpiry(): void
    {
        $token = $this->tokens->issue((string) $this->user['id'], ActionTokenPurpose::PASSWORD_RESET, 1800);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token['raw']);
        $row = $this->row($token['id']);
        self::assertSame(hash('sha256', $token['raw']), $row['token_hash']);
        self::assertNotSame($token['raw'], $row['token_hash']);
        self::assertSame('password_reset', $row['purpose']);
        self::assertNull($row['used_at']);
        self::assertNull($row['new_email']);

        $seconds = (int) $this->pdo->query(
            'SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) FROM user_action_tokens WHERE id = ' . $this->pdo->quote($token['id'])
        )->fetchColumn();
        self::assertGreaterThan(1790, $seconds);
        self::assertLessThanOrEqual(1800, $seconds);
        foreach ($row as $column => $value) {
            if ($column !== 'token_hash') {
                self::assertStringNotContainsString($token['raw'], (string) $value);
            }
        }
    }

    public function testConsumeReturnsTheTokenOnceAndOnlyOnce(): void
    {
        $token = $this->tokens->issue((string) $this->user['id'], ActionTokenPurpose::EMAIL_CHANGE, 3600, 'new@example.test');

        $first = $this->tokens->consume($token['raw'], ActionTokenPurpose::EMAIL_CHANGE);
        self::assertSame(
            ['id' => $token['id'], 'userId' => (string) $this->user['id'], 'newEmail' => 'new@example.test'],
            $first
        );
        self::assertNotNull($this->row($token['id'])['used_at']);
        self::assertNull($this->tokens->consume($token['raw'], ActionTokenPurpose::EMAIL_CHANGE));
    }

    public function testConsumeRejectsWrongPurposeMalformedUnknownAndExpired(): void
    {
        $token = $this->tokens->issue((string) $this->user['id'], ActionTokenPurpose::PASSWORD_RESET, 1800);

        self::assertNull($this->tokens->consume($token['raw'], ActionTokenPurpose::EMAIL_VERIFICATION));
        self::assertNull($this->tokens->consume('not-hex', ActionTokenPurpose::PASSWORD_RESET));
        self::assertNull($this->tokens->consume(strtoupper($token['raw']), ActionTokenPurpose::PASSWORD_RESET));
        self::assertNull($this->tokens->consume(str_repeat('0', 64), ActionTokenPurpose::PASSWORD_RESET));
        self::assertNull($this->row($token['id'])['used_at'], 'a rejected attempt must not burn the token');

        $this->pdo->prepare('UPDATE user_action_tokens SET expires_at = NOW() - INTERVAL 1 SECOND WHERE id = :id')
            ->execute(['id' => $token['id']]);
        self::assertNull($this->tokens->consume($token['raw'], ActionTokenPurpose::PASSWORD_RESET));
    }

    public function testInvalidateOpenOnlyTouchesThatPurposeAndUser(): void
    {
        $reset = $this->tokens->issue((string) $this->user['id'], ActionTokenPurpose::PASSWORD_RESET, 1800);
        $verify = $this->tokens->issue((string) $this->user['id'], ActionTokenPurpose::EMAIL_VERIFICATION, 1800);

        $this->tokens->invalidateOpen((string) $this->user['id'], ActionTokenPurpose::PASSWORD_RESET);

        self::assertNull($this->tokens->consume($reset['raw'], ActionTokenPurpose::PASSWORD_RESET));
        self::assertNotNull($this->tokens->consume($verify['raw'], ActionTokenPurpose::EMAIL_VERIFICATION));
    }

    public function testCountRecentWindow(): void
    {
        $userId = (string) $this->user['id'];
        self::assertSame(0, $this->tokens->countRecent($userId, ActionTokenPurpose::PASSWORD_RESET, 3600));

        $old = $this->tokens->issue($userId, ActionTokenPurpose::PASSWORD_RESET, 1800);
        $this->tokens->issue($userId, ActionTokenPurpose::PASSWORD_RESET, 1800);
        $this->tokens->issue($userId, ActionTokenPurpose::EMAIL_VERIFICATION, 1800);
        $this->pdo->prepare('UPDATE user_action_tokens SET created_at = created_at - INTERVAL 2 MINUTE WHERE id = :id')
            ->execute(['id' => $old['id']]);

        self::assertSame(2, $this->tokens->countRecent($userId, ActionTokenPurpose::PASSWORD_RESET, 3600));
        self::assertSame(1, $this->tokens->countRecent($userId, ActionTokenPurpose::PASSWORD_RESET, 60));
    }

    public function testTokenOfADeletedUserIsInert(): void
    {
        $token = $this->tokens->issue((string) $this->user['id'], ActionTokenPurpose::PASSWORD_RESET, 1800);
        $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->user['id']]);

        self::assertNull($this->tokens->consume($token['raw'], ActionTokenPurpose::PASSWORD_RESET));
    }
}

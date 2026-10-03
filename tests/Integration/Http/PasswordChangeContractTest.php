<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

final class PasswordChangeContractTest extends HttpTestCase
{
    private const PATH = '/api/v1/auth/password/change';

    private UserRepository $users;
    private string $prefix;
    private array $participant;
    private array $participant2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'http-pc-' . bin2hex(random_bytes(4));

        $this->participant = $this->users->create([
            'name' => 'P One',
            'email' => $this->prefix . '-p@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $this->participant2 = $this->users->create([
            'name' => 'Q Two',
            'email' => $this->prefix . '-q@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE recipient LIKE :email')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    private function storedHash(string $id): string
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return (string) $stmt->fetchColumn();
    }

    private function change(array $body, ?array $user = null): \Psr\Http\Message\ResponseInterface
    {
        $user ??= $this->participant;
        return $this->request('POST', self::PATH, $body, $this->bearer($user['id'], 'participant', $user['email']));
    }

    private function assertUnchanged(array $user): void
    {
        self::assertTrue(password_verify('demo1234', $this->storedHash($user['id'])));
    }

    public function testAnonymousIsRejected(): void
    {
        $response = $this->request('POST', self::PATH, ['oldPassword' => 'demo1234', 'newPassword' => 'NoweHaslo123']);
        self::assertSame(401, $response->getStatusCode());
        $this->assertUnchanged($this->participant);
    }

    public function testValidChangePersistsAndLoginUsesNewPassword(): void
    {
        $response = $this->change(['oldPassword' => 'demo1234', 'newPassword' => 'NoweHaslo123']);
        self::assertSame(200, $response->getStatusCode());

        $login = fn (string $password) => $this->request('POST', '/api/v1/auth/login', [
            'email' => $this->participant['email'],
            'password' => $password,
        ]);
        self::assertSame(200, $login('NoweHaslo123')->getStatusCode());
        self::assertSame(401, $login('demo1234')->getStatusCode());
    }

    public function testWrongCurrentPasswordIs422AndHashUnchanged(): void
    {
        $response = $this->change(['oldPassword' => 'zle-haslo', 'newPassword' => 'NoweHaslo123']);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_current_password', $this->json($response)['error']['code']);
        $this->assertUnchanged($this->participant);
    }

    public function testMissingFieldsIs422(): void
    {
        foreach ([[], ['oldPassword' => 'demo1234']] as $body) {
            $response = $this->change($body);
            self::assertSame(422, $response->getStatusCode());
            self::assertSame('validation_error', $this->json($response)['error']['code']);
        }
        $this->assertUnchanged($this->participant);
    }

    public function testTooShortNewPasswordIs422(): void
    {
        $response = $this->change(['oldPassword' => 'demo1234', 'newPassword' => 'short7!']);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('password_too_short', $this->json($response)['error']['code']);
        $this->assertUnchanged($this->participant);
    }

    public function testTooLongNewPasswordIs422(): void
    {
        $response = $this->change(['oldPassword' => 'demo1234', 'newPassword' => str_repeat('a', 73)]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('password_too_long', $this->json($response)['error']['code']);
        $this->assertUnchanged($this->participant);
    }

    public function testSameAsCurrentIs422(): void
    {
        $response = $this->change(['oldPassword' => 'demo1234', 'newPassword' => 'demo1234']);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('password_unchanged', $this->json($response)['error']['code']);
        $this->assertUnchanged($this->participant);
    }

    public function testCannotChangeAnotherAccountsPassword(): void
    {
        $response = $this->change([
            'userId' => $this->participant['id'],
            'oldPassword' => 'demo1234',
            'newPassword' => 'NoweHaslo123',
        ], $this->participant2);
        self::assertSame(200, $response->getStatusCode());

        $this->assertUnchanged($this->participant);
        self::assertTrue(password_verify('NoweHaslo123', $this->storedHash($this->participant2['id'])));
    }
}

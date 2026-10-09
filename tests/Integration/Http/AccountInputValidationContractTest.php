<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * PP.89: POST /users (admin) and POST /auth/register validate their input. A bad body is a 422 with a
 * Polish message and details.field (never a PHP warning or a 500 from strict MySQL), a taken e-mail is 409,
 * and self-registration applies the shared PasswordRule. Nothing is created on any 4xx.
 */
final class AccountInputValidationContractTest extends HttpTestCase
{
    private UserRepository $users;
    private string $prefix;
    /** @var array<string, mixed> */
    private array $admin;

    protected function setUp(): void
    {
        Env::set('CENTRAL_MAILER_API_KEY', '');
        Env::set('CENTRAL_MAILER_BASE_URL', '');
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'http-aiv-' . bin2hex(random_bytes(4));
        $this->admin = $this->users->create([
            'name' => 'Validation Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        Env::restore('CENTRAL_MAILER_API_KEY');
        Env::restore('CENTRAL_MAILER_BASE_URL');
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $like = ['email' => $this->prefix . '-%@example.test'];
        $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE recipient LIKE :email')->execute($like);
        $this->pdo->prepare('DELETE FROM user_action_tokens WHERE user_id IN (SELECT id FROM users WHERE email LIKE :email)')->execute($like);
        $this->pdo->prepare('DELETE FROM competitors WHERE user_id IN (SELECT id FROM users WHERE email LIKE :email)')->execute($like);
        $this->pdo->prepare('DELETE FROM revoked_session_tokens WHERE user_id IN (SELECT id FROM users WHERE email LIKE :email)')->execute($like);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute($like);
    }

    /** @return array<string, string> */
    private function adminAuth(): array
    {
        return $this->bearer((string) $this->admin['id'], 'admin', (string) $this->admin['email']);
    }

    /** @param array<string, mixed> $body */
    private function createViaApi(array $body): ResponseInterface
    {
        return $this->request('POST', '/api/v1/users', $body, $this->adminAuth());
    }

    /** @return array<string, mixed> */
    private function validBody(string $tag = 'ok'): array
    {
        return ['name' => 'Valid ' . $tag, 'email' => $this->prefix . '-' . $tag . '@example.test', 'role' => 'participant'];
    }

    private function userCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    private function rowCountFor(string $table, string $column): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE :email");
        $stmt->execute(['email' => $this->prefix . '-%@example.test']);

        return (int) $stmt->fetchColumn();
    }

    private function assertNothingCreated(int $usersBefore): void
    {
        self::assertSame($usersBefore, $this->userCount(), 'no users row');
        self::assertSame(0, $this->rowCountFor('central_mailer_outbox', 'recipient'), 'no mail outbox row');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> */
    public static function invalidAdminBodies(): array
    {
        $ok = ['name' => 'N', 'email' => 'x-valid@example.test', 'role' => 'participant'];

        return [
            'empty body' => [[], 'name', 'validation_error'],
            'blank name' => [['name' => '   '] + $ok, 'name', 'validation_error'],
            'name too long' => [['name' => str_repeat('a', 181)] + $ok, 'name', 'validation_error'],
            'name is an array' => [['name' => ['x']] + $ok, 'name', 'validation_error'],
            'missing email' => [['name' => 'N'], 'email', 'validation_error'],
            'invalid email' => [['email' => 'x@'] + $ok, 'email', 'invalid_email'],
            'email too long' => [['email' => str_repeat('a', 250) . '@example.test'] + $ok, 'email', 'invalid_email'],
            'email is an array' => [['email' => ['x']] + $ok, 'email', 'invalid_email'],
            'unknown role' => [['role' => 'king'] + $ok, 'role', 'invalid_role'],
            'unknown status' => [['status' => 'x'] + $ok, 'status', 'invalid_status'],
            'bad gender' => [['gender' => 'X'] + $ok, 'gender', 'validation_error'],
            'birthYear not a number' => [['birthYear' => 'abc'] + $ok, 'birthYear', 'validation_error'],
            'birthYear too old' => [['birthYear' => 1800] + $ok, 'birthYear', 'validation_error'],
            'negative eventLimit' => [['eventLimit' => -1] + $ok, 'eventLimit', 'validation_error'],
            'impossible birthDate' => [['birthDate' => '2024-02-30'] + $ok, 'birthDate', 'validation_error'],
            'phone too long' => [['phone' => str_repeat('1', 65)] + $ok, 'phone', 'validation_error'],
            'address is a string' => [['address' => 'str'] + $ok, 'address', 'validation_error'],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidAdminBodies')]
    public function testAdminCreateRejectsInvalidInputWith422(array $body, string $field, string $code): void
    {
        if (isset($body['email']) && $body['email'] === 'x-valid@example.test') {
            $body['email'] = $this->prefix . '-bad@example.test';
        }
        $before = $this->userCount();

        $response = $this->createViaApi($body);

        self::assertSame(422, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame($code, $error['code']);
        self::assertSame($field, $error['details']['field']);
        self::assertNotSame('', (string) $error['message']);
        self::assertMatchesRegularExpression('/[ąćęłńóśźż]|Podaj|Wybierz|Pole/u', (string) $error['message']);
        $this->assertNothingCreated($before);
        self::assertSame(0, $this->rowCountFor('user_action_tokens', 'user_id'), 'no setup token');
    }

    public function testEmptyBodyIsA422WithoutPhpWarnings(): void
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $response = $this->createViaApi([]);
        } finally {
            restore_error_handler();
        }

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $warnings);
        self::assertSame('name', $this->json($response)['error']['details']['field']);
    }

    public function testModeratorStillGets403BeforeValidation(): void
    {
        $moderator = $this->users->create([
            'name' => 'Mod',
            'email' => $this->prefix . '-mod@example.test',
            'password' => 'demo1234',
            'role' => 'moderator',
        ]);
        $response = $this->request('POST', '/api/v1/users', [], $this->bearer((string) $moderator['id'], 'moderator'));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAdminCreateWithTakenEmailIs409InEveryVariant(): void
    {
        $existing = $this->users->create([
            'name' => 'Existing',
            'email' => $this->prefix . '-taken@example.test',
            'password' => 'demo1234',
        ]);
        $before = $this->userCount();

        foreach (
            [
            (string) $existing['email'],
            strtoupper((string) $existing['email']),
            '  ' . $existing['email'] . '  ',
            ] as $variant
        ) {
            $response = $this->createViaApi(['email' => $variant] + $this->validBody('dup'));

            self::assertSame(409, $response->getStatusCode(), $variant);
            $error = $this->json($response)['error'];
            self::assertSame('email_taken', $error['code']);
            self::assertSame('Ten adres e-mail jest już zajęty.', $error['message']);
        }
        self::assertSame($before, $this->userCount());
        self::assertSame(0, $this->rowCountFor('user_action_tokens', 'user_id'));
    }

    public function testAdminCreateStoresATrimmedLowerCasedEmail(): void
    {
        $response = $this->createViaApi(['email' => '  ' . strtoupper($this->prefix) . '-Mixed@Example.TEST '] + $this->validBody('mixed'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($this->prefix . '-mixed@example.test', $this->json($response)['data']['email']);
    }

    public function testAdminCreateWithValidBodyIs201(): void
    {
        $response = $this->createViaApi($this->validBody() + [
            'gender' => 'K',
            'birthYear' => 1990,
            'birthDate' => '1990-05-17',
            'eventLimit' => 3,
            'phone' => '+48 500 100 200',
            'address' => ['city' => 'Poznań'],
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Valid ok', $this->json($response)['data']['name']);
    }

    // ── POST /auth/register ────────────────────────────────────────────────────

    /** @param array<string, mixed> $extra */
    private function register(array $extra): ResponseInterface
    {
        return $this->request('POST', '/api/v1/auth/register', array_merge(
            ['name' => 'Reg User', 'email' => $this->prefix . '-reg@example.test', 'password' => 'demo1234'],
            $extra
        ));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> */
    public static function invalidRegistrations(): array
    {
        return [
            'password too short' => [['password' => 'short7!'], 'password_too_short', 'password'],
            'password too long' => [['password' => str_repeat('a', 73)], 'password_too_long', 'password'],
            'password is an array' => [['password' => ['x']], 'validation_error', 'password'],
            'name is an array' => [['name' => []], 'validation_error', 'name'],
            'name is an int' => [['name' => 5], 'validation_error', 'name'],
            'email is an array' => [['email' => ['a@b.c']], 'invalid_email', 'email'],
            'email is an int' => [['email' => 7], 'invalid_email', 'email'],
            'invalid email' => [['email' => 'nope'], 'invalid_email', 'email'],
            'name too long' => [['name' => str_repeat('a', 181)], 'validation_error', 'name'],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidRegistrations')]
    public function testRegisterRejectsInvalidInputWith422(array $override, string $code, string $field): void
    {
        $before = $this->userCount();

        $response = $this->register($override);

        self::assertSame(422, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame($code, $error['code']);
        self::assertSame($field, $error['details']['field']);
        $this->assertNothingCreated($before);
        self::assertSame(0, $this->rowCountFor('user_action_tokens', 'user_id'), 'no verification token');
    }

    public function testRegisterWithMissingFieldsIsStillAValidationError(): void
    {
        $response = $this->request('POST', '/api/v1/auth/register', ['email' => $this->prefix . '-m@example.test']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_error', $this->json($response)['error']['code']);
    }

    public function testRegisterDuplicateIs409WithAPolishMessage(): void
    {
        self::assertSame(201, $this->register([])->getStatusCode());
        $before = $this->userCount();

        $response = $this->register(['email' => strtoupper($this->prefix) . '-REG@example.test']);

        self::assertSame(409, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame('email_taken', $error['code']);
        self::assertSame('Ten adres e-mail jest już zajęty.', $error['message']);
        self::assertSame($before, $this->userCount());
    }

    public function testRegisterForcesTheParticipantRoleAndNormalisesTheEmail(): void
    {
        $response = $this->register([
            'email' => ' ' . strtoupper($this->prefix) . '-Reg@Example.test ',
            'role' => 'admin',
            'status' => 'blocked',
            'eventLimit' => 99,
        ]);

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('participant', $data['role']);
        self::assertSame('active', $data['status']);
        self::assertSame(0, (int) $data['eventLimit']);
        self::assertSame($this->prefix . '-reg@example.test', $data['email']);
    }
}

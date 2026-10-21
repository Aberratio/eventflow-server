<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * An account created through POST /users never gets a known password. The
 * admin cannot supply one (422), the stored hash is an unusable bcrypt hash, and the
 * owner receives a one-time password-set link (PASSWORD_RESET token, 72 h) by mail.
 */
final class AdminCreatedAccountContractTest extends HttpTestCase
{
    private const MAILER_TESTS = [
        'testSetupLinkIsIssuedAndTheMailAttempted',
        'testSetupLinkSetsThePasswordThroughResetConfirm',
        'testPermanentMailerRejectionGetsTheHonestFallbackMessage',
        'testTransientMailerFailureAnnouncesAScheduledSend',
    ];

    /** @var resource|null */
    private $stubProcess = null;
    private ?string $stubScript = null;

    private UserRepository $users;
    private string $prefix;
    /** @var array<string, mixed> */
    private array $admin;

    protected function setUp(): void
    {
        if (in_array($this->name(), self::MAILER_TESTS, true)) {
            // Must be set before the app is built: the mailer config is read at route registration.
            Env::set('CENTRAL_MAILER_API_KEY', 'phpunit-key');
            Env::set('CENTRAL_MAILER_BASE_URL', $this->name() === 'testPermanentMailerRejectionGetsTheHonestFallbackMessage'
                ? $this->startStubMailer(422)
                : 'unusable://mailer.invalid');
            Env::set('APP_URL', 'https://app.example.test');
        } else {
            Env::set('CENTRAL_MAILER_API_KEY', '');
            Env::set('CENTRAL_MAILER_BASE_URL', '');
        }
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'http-aca-' . bin2hex(random_bytes(4));
        $this->admin = $this->users->create([
            'name' => 'Account Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->stubProcess !== null) {
            proc_terminate($this->stubProcess);
            proc_close($this->stubProcess);
            $this->stubProcess = null;
        }
        if ($this->stubScript !== null) {
            @unlink($this->stubScript);
        }
        Env::restore('CENTRAL_MAILER_API_KEY');
        Env::restore('CENTRAL_MAILER_BASE_URL');
        Env::restore('APP_URL');
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

    private function loginResponse(string $email, string $password): ResponseInterface
    {
        return $this->request('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    /** @return array<string, string> */
    private function adminAuth(): array
    {
        $response = $this->loginResponse((string) $this->admin['email'], 'demo1234');
        self::assertSame(200, $response->getStatusCode());
        return ['Authorization' => 'Bearer ' . (string) $this->json($response)['data']['token']];
    }

    /** @param array<string, mixed> $extra */
    private function createViaApi(string $tag, array $extra = []): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/users',
            array_merge(['name' => 'Created ' . $tag, 'email' => $this->prefix . '-' . $tag . '@example.test', 'role' => 'participant'], $extra),
            $this->adminAuth()
        );
    }

    private function storedHash(string $email): string
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        return (string) $stmt->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> */
    private function tokenRows(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT purpose, used_at, TIMESTAMPDIFF(HOUR, NOW(), expires_at) AS hours_left
             FROM user_action_tokens WHERE user_id = :id'
        );
        $stmt->execute(['id' => $userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    private function outboxRows(string $email): array
    {
        $stmt = $this->pdo->prepare('SELECT source_entity_type, priority, html FROM central_mailer_outbox WHERE recipient = :email');
        $stmt->execute(['email' => $email]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testAccountCreatedWithoutPasswordCannotSignInWithAnyKnownPassword(): void
    {
        $email = $this->prefix . '-plain@example.test';
        $response = $this->createViaApi('plain');

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame($email, $data['email']);
        self::assertFalse($data['emailVerified']);
        self::assertArrayNotHasKey('passwordHash', $data);
        self::assertArrayNotHasKey('password_hash', $data);
        self::assertArrayNotHasKey('password', $data);

        foreach (['demo1234', '', 'demo123'] as $password) {
            $login = $this->loginResponse($email, $password);
            self::assertSame(401, $login->getStatusCode(), 'password "' . $password . '" must not log in');
            self::assertSame('invalid_credentials', $this->json($login)['error']['code']);
        }

        $hash = $this->storedHash($email);
        self::assertSame(password_get_info(password_hash('x', PASSWORD_DEFAULT))['algo'], password_get_info($hash)['algo']);
        self::assertSame(password_get_info(password_hash('x', PASSWORD_DEFAULT))['options'], password_get_info($hash)['options']);
        self::assertFalse(password_verify('demo1234', $hash));

        $this->createViaApi('plain2');
        self::assertNotSame($hash, $this->storedHash($this->prefix . '-plain2@example.test'));
    }

    public function testRepositoryCreateWithoutPasswordNeverStoresAKnownPassword(): void
    {
        $variants = ['nokey' => [], 'null' => ['password' => null], 'empty' => ['password' => '']];
        $hashes = [];
        foreach ($variants as $tag => $extra) {
            $this->users->create(['name' => 'Repo ' . $tag, 'email' => $this->prefix . '-' . $tag . '@example.test', 'role' => 'participant'] + $extra);
            $hash = $this->storedHash($this->prefix . '-' . $tag . '@example.test');
            self::assertFalse(password_verify('demo1234', $hash), $tag);
            self::assertFalse(password_verify('', $hash), $tag);
            $hashes[] = $hash;
        }
        self::assertCount(3, array_unique($hashes));
    }

    #[DataProvider('passwordValues')]
    public function testPasswordInBodyIsRejectedAndNothingIsCreated(mixed $password): void
    {
        $response = $this->createViaApi('withpw', ['password' => $password]);

        self::assertSame(422, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame('field_not_editable', $error['code']);
        self::assertSame(['password'], $error['details']['fields']);
        self::assertNull($this->users->findByEmail($this->prefix . '-withpw@example.test'));
    }

    /** @return array<string, array{0: mixed}> */
    public static function passwordValues(): array
    {
        return ['strong' => ['Str0ngHaslo!'], 'empty' => [''], 'null' => [null]];
    }

    public function testSetupLinkIsIssuedAndTheMailAttempted(): void
    {
        $email = $this->prefix . '-mail@example.test';
        $response = $this->createViaApi('mail');

        self::assertSame(201, $response->getStatusCode());
        self::assertStringStartsWith('Konto zostało utworzone', (string) $this->json($response)['message']);

        $created = $this->users->findByEmail($email);
        self::assertNotNull($created);
        $tokens = $this->tokenRows((string) $created['id']);
        self::assertCount(1, $tokens);
        self::assertSame('password_reset', $tokens[0]['purpose']);
        self::assertNull($tokens[0]['used_at']);
        self::assertGreaterThanOrEqual(71, (int) $tokens[0]['hours_left']);
        self::assertLessThanOrEqual(73, (int) $tokens[0]['hours_left']);

        $outbox = $this->outboxRows($email);
        self::assertCount(1, $outbox);
        self::assertSame('account_setup', $outbox[0]['source_entity_type']);
        self::assertSame('technical', $outbox[0]['priority']);
        self::assertStringContainsString('https://app.example.test/reset-password?token=', (string) $outbox[0]['html']);
    }

    public function testSetupLinkSetsThePasswordThroughResetConfirm(): void
    {
        $email = $this->prefix . '-link@example.test';
        self::assertSame(201, $this->createViaApi('link')->getStatusCode());

        $outbox = $this->outboxRows($email);
        self::assertCount(1, $outbox);
        self::assertSame(1, preg_match('/reset-password\?token=([a-f0-9]{64})/', (string) $outbox[0]['html'], $m));
        $token = $m[1];

        $confirm = $this->request('POST', '/api/v1/auth/password/reset/confirm', ['token' => $token, 'newPassword' => 'NoweHaslo123']);
        self::assertSame(200, $confirm->getStatusCode());
        self::assertSame(200, $this->loginResponse($email, 'NoweHaslo123')->getStatusCode());

        $again = $this->request('POST', '/api/v1/auth/password/reset/confirm', ['token' => $token, 'newPassword' => 'InneHaslo456']);
        self::assertSame(400, $again->getStatusCode());
        self::assertSame('invalid_token', $this->json($again)['error']['code']);
    }

    /** Starts a throwaway PHP built-in server that answers every request with the given status; returns its base URL. */
    private function startStubMailer(int $status): string
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
        fclose($probe);

        $this->stubScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zc-stub-mailer-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($this->stubScript, '<?php http_response_code(' . $status . '); header("Content-Type: application/json"); echo "{}";');
        $this->stubProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $this->stubScript],
            [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zc-stub-mailer.log', 'a'], 2 => ['file', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zc-stub-mailer.log', 'a']],
            $pipes
        );
        self::assertIsResource($this->stubProcess);
        for ($i = 0; $i < 50; $i++) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                return 'http://127.0.0.1:' . $port;
            }
            usleep(100000);
        }
        self::fail('stub mailer did not start');
    }

    public function testPermanentMailerRejectionGetsTheHonestFallbackMessage(): void
    {
        $email = $this->prefix . '-perm@example.test';
        $response = $this->createViaApi('perm');

        self::assertSame(201, $response->getStatusCode());
        $message = (string) $this->json($response)['message'];
        self::assertStringNotContainsString('Wysłaliśmy', $message);
        self::assertStringContainsString('nie udało się wysłać linku', $message);

        $stmt = $this->pdo->prepare('SELECT status, next_attempt_at, central_mailer_email_id FROM central_mailer_outbox WHERE recipient = :email');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('failed', $row['status']);
        self::assertNull($row['next_attempt_at']);
        self::assertNull($row['central_mailer_email_id']);
    }

    public function testTransientMailerFailureAnnouncesAScheduledSend(): void
    {
        $response = $this->createViaApi('trans');

        self::assertSame(201, $response->getStatusCode());
        $message = (string) $this->json($response)['message'];
        self::assertStringNotContainsString('Wysłaliśmy', $message);
        self::assertStringContainsString('zostanie wysłany', $message);
    }

    public function testMailerNotConfiguredStillCreatesAccountAndToken(): void
    {
        $email = $this->prefix . '-nomail@example.test';
        $response = $this->createViaApi('nomail');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            'Konto zostało utworzone, ale nie udało się wysłać linku do ustawienia hasła. Użytkownik może ustawić hasło przez „Nie pamiętam hasła” na stronie logowania.',
            $this->json($response)['message']
        );
        $created = $this->users->findByEmail($email);
        self::assertNotNull($created);
        self::assertCount(1, $this->tokenRows((string) $created['id']));
        self::assertCount(0, $this->outboxRows($email));
    }

    public function testAdminCannotCreateAdminAccount(): void
    {
        $response = $this->createViaApi('adm', ['role' => 'admin']);

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($this->users->findByEmail($this->prefix . '-adm@example.test'));
    }
}

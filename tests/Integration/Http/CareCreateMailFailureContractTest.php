<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/** PP.19: a Central Mailer outage must not turn creating a Zawodnik into a 500; the mail row waits for the retry. */
final class CareCreateMailFailureContractTest extends HttpTestCase
{
    private string $prefix;
    /** @var array<string, mixed> */
    private array $parent;

    protected function setUp(): void
    {
        // Must be set before the app is built: the mailer config is read at route registration.
        Env::set('CENTRAL_MAILER_API_KEY', 'phpunit-key');
        Env::set('CENTRAL_MAILER_BASE_URL', 'unusable://mailer.invalid');
        Env::set('APP_URL', 'https://app.example.test');
        parent::setUp();

        $this->prefix = 'http-carefail-' . bin2hex(random_bytes(4));
        $this->parent = (new UserRepository($this->pdo))->create([
            'name' => 'Care Fail Parent',
            'email' => $this->prefix . '-parent@example.test',
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
        $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE recipient LIKE :email')->execute(['email' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE cr FROM care_relations cr JOIN users u ON u.id = cr.konto_id WHERE u.email LIKE :email')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM competitors WHERE last_name = :last')->execute(['last' => $this->prefix]);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testCareCreateSucceedsWhenCentralMailerFails(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/care',
            ['firstName' => 'Zosia', 'lastName' => $this->prefix, 'birthDate' => '2018-05-01'],
            $this->bearer($this->parent['id'], 'participant', $this->parent['email'])
        );

        self::assertSame(201, $response->getStatusCode());
        $competitorId = $this->json($response)['data']['competitor']['id'];

        $stmt = $this->pdo->prepare(
            "SELECT status, attempts, next_attempt_at, central_mailer_email_id FROM central_mailer_outbox
             WHERE source_entity_type = 'identity_code' AND source_entity_id = :id"
        );
        $stmt->execute(['id' => $competitorId]);
        $rows = $stmt->fetchAll();
        self::assertCount(1, $rows, 'the identity-code mail is durably recorded');
        self::assertSame('failed', $rows[0]['status']);
        self::assertSame(1, (int) $rows[0]['attempts']);
        self::assertNotNull($rows[0]['next_attempt_at'], 'and scheduled for a retry');
        self::assertNull($rows[0]['central_mailer_email_id']);
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Database\SeedManifest;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * POST /dev/seed-event creates a whole event with registrations and results.
 * Locally it keeps the convenient demo123 accounts; on staging (reachable from the internet) the accounts
 * it creates never get a known password, and the caller must hold a live session.
 */
final class DevSeedEventContractTest extends HttpTestCase
{
    private UserRepository $users;
    private string $prefix;
    private string $startedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'http-dse-' . bin2hex(random_bytes(4));
        $this->startedAt = (string) $this->pdo->query('SELECT NOW()')->fetchColumn();
    }

    protected function tearDown(): void
    {
        Env::restore('APP_ENV');
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        // Remove everything the seed call recorded in the manifest, then the test's own accounts.
        $pk = SeedManifest::primaryKeys($this->pdo);
        $rows = $this->pdo->prepare('SELECT table_name, record_key FROM seed_records WHERE seeded_at >= :s');
        $rows->execute(['s' => $this->startedAt]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($rows->fetchAll() as $row) {
                $table = (string) $row['table_name'];
                $values = json_decode((string) $row['record_key'], true);
                if (!isset($pk[$table]) || !is_array($values) || count($values) !== count($pk[$table])) {
                    continue;
                }
                $where = implode(' AND ', array_map(static fn (string $c): string => '`' . $c . '` <=> ?', $pk[$table]));
                $this->pdo->prepare("DELETE FROM `{$table}` WHERE {$where}")->execute(array_values($values));
            }
            $this->pdo->prepare('DELETE FROM seed_records WHERE seeded_at >= :s')->execute(['s' => $this->startedAt]);
            $like = ['email' => $this->prefix . '-%@example.test'];
            $this->pdo->prepare('DELETE FROM revoked_session_tokens WHERE user_id IN (SELECT id FROM users WHERE email LIKE :email)')->execute($like);
            $this->pdo->prepare('DELETE FROM competitors WHERE user_id IN (SELECT id FROM users WHERE email LIKE :email)')->execute($like);
            $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute($like);
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /** @return array<string, mixed> */
    private function account(string $role): array
    {
        return $this->users->create([
            'name' => 'Seed ' . $role,
            'email' => $this->prefix . '-' . $role . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ]);
    }

    /** @param array<string, string> $headers */
    private function seed(array $headers = [], int $count = 3): ResponseInterface
    {
        return $this->request('POST', '/api/v1/dev/seed-event', ['participantCount' => $count], $headers);
    }

    /** @return list<string> ids of users that the seed call recorded in the manifest */
    private function seededUserIds(): array
    {
        $stmt = $this->pdo->prepare("SELECT record_key FROM seed_records WHERE table_name = 'users' AND seeded_at >= :s");
        $stmt->execute(['s' => $this->startedAt]);

        return array_map(
            static fn (string $key): string => (string) (json_decode($key, true)[0] ?? ''),
            $stmt->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    private function passwordOf(string $id): string
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return (string) $stmt->fetchColumn();
    }

    public function testLocalSeedCreatesRegistrationsAndResultsAndKeepsTheDemoPassword(): void
    {
        Env::set('APP_ENV', 'local');

        $response = $this->seed();

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertSame(3, $data['registrationsCreated']);
        self::assertSame(3, $data['resultsCreated']);
        $ids = $this->seededUserIds();
        self::assertNotSame([], $ids);
        foreach ($ids as $id) {
            self::assertTrue(password_verify('demo123', $this->passwordOf($id)), 'local convenience: ' . $id);
        }
    }

    public function testStagingSeedCreatesNoAccountWithAKnownPassword(): void
    {
        Env::set('APP_ENV', 'staging');
        $organizer = $this->account('organizer');

        $response = $this->seed($this->bearer((string) $organizer['id'], 'organizer', (string) $organizer['email']));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $ids = $this->seededUserIds();
        self::assertNotSame([], $ids, 'participant accounts are created and recorded');
        foreach ($ids as $id) {
            $hash = $this->passwordOf($id);
            self::assertFalse(password_verify('demo123', $hash), $id);
            self::assertFalse(password_verify('demo1234', $hash), $id);
        }
    }

    public function testDevFollowsTheStagingRules(): void
    {
        Env::set('APP_ENV', 'dev');
        $organizer = $this->account('organizer');

        self::assertSame(403, $this->seed()->getStatusCode(), 'no session');

        $response = $this->seed($this->bearer((string) $organizer['id'], 'organizer', (string) $organizer['email']));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $ids = $this->seededUserIds();
        self::assertNotSame([], $ids);
        foreach ($ids as $id) {
            self::assertFalse(password_verify('demo123', $this->passwordOf($id)), $id);
        }
    }

    public function testStagingStaffCallCreatesAnOrganizerWithoutAKnownPassword(): void
    {
        Env::set('APP_ENV', 'staging');
        $admin = $this->account('admin');

        $response = $this->seed($this->bearer((string) $admin['id'], 'admin', (string) $admin['email']));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $created = $this->users->findByEmail((string) $this->json($response)['data']['organizerEmail']);
        self::assertNotNull($created);
        self::assertFalse(password_verify('demo123', $this->passwordOf((string) $created['id'])));
        self::assertContains((string) $created['id'], $this->seededUserIds());
    }

    public function testStagingRejectsTokensThatAreNotLiveSessions(): void
    {
        Env::set('APP_ENV', 'staging');
        $organizer = $this->account('organizer');
        $id = (string) $organizer['id'];
        $email = (string) $organizer['email'];

        // 1. logged-out session (jti on the deny list)
        $jti = bin2hex(random_bytes(16));
        $loggedOut = ['Authorization' => 'Bearer ' . $this->jwt->issue([
            'sub' => $id, 'role' => 'organizer', 'email' => $email, 'tv' => $this->tokenVersionOf($id), 'jti' => $jti,
        ])];
        $this->pdo->prepare('INSERT INTO revoked_session_tokens (jti, user_id, expires_at) VALUES (:jti, :id, DATE_ADD(NOW(), INTERVAL 1 HOUR))')
            ->execute(['jti' => $jti, 'id' => $id]);
        self::assertSame(403, $this->seed($loggedOut)->getStatusCode(), 'logged-out jti');

        // 2. stale token_version
        $stale = $this->bearer($id, 'organizer', $email);
        $this->users->bumpTokenVersion($id);
        self::assertSame(403, $this->seed($stale)->getStatusCode(), 'bumped token_version');

        // 3. blocked account
        $blocked = $this->bearer($id, 'organizer', $email);
        $this->pdo->prepare("UPDATE users SET status = 'blocked' WHERE id = :id")->execute(['id' => $id]);
        self::assertSame(403, $this->seed($blocked)->getStatusCode(), 'blocked account');

        // 4. a one-time mail-link token is not a session
        $this->pdo->prepare("UPDATE users SET status = 'active' WHERE id = :id")->execute(['id' => $id]);
        $purpose = ['Authorization' => 'Bearer ' . $this->jwt->issue([
            'sub' => $id, 'role' => 'organizer', 'email' => $email, 'tv' => $this->tokenVersionOf($id), 'purpose' => 'x',
        ])];
        self::assertSame(403, $this->seed($purpose)->getStatusCode(), 'purpose token');
    }

    public function testStagingIgnoresTheCallerClaimedInTheBody(): void
    {
        Env::set('APP_ENV', 'staging');
        $participant = $this->account('participant');
        $admin = $this->account('admin');

        $response = $this->request(
            'POST',
            '/api/v1/dev/seed-event',
            ['participantCount' => 3, 'callerRole' => 'admin', 'callerId' => (string) $admin['id']],
            $this->bearer((string) $participant['id'], 'participant', (string) $participant['email'])
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testProductionIsClosedForEveryone(): void
    {
        Env::set('APP_ENV', 'production');
        $admin = $this->account('admin');

        self::assertSame(403, $this->seed($this->bearer((string) $admin['id'], 'admin', (string) $admin['email']))->getStatusCode());
        self::assertSame(403, $this->seed()->getStatusCode());
    }
}

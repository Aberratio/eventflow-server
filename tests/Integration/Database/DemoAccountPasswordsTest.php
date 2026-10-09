<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\DemoAccountPasswords;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Database\SeedManifest;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;

/**
 * PP.90: seeded demo accounts must not keep a known password outside local. scrub() replaces the password
 * of every manifest account and every account that still carries the seeders' demo hash (and, in deep mode,
 * any account that verifies demo123/demo1234) and ends its sessions.
 */
final class DemoAccountPasswordsTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $users;
    private string $prefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
        SeedManifest::ensureTable($this->pdo);
        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'dap-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $this->pdo->prepare("DELETE FROM seed_records WHERE table_name = 'users' AND record_key LIKE :p")->execute(['p' => '%' . $this->prefix . '%']);
        $like = ['email' => $this->prefix . '-%@example.test'];
        $this->pdo->prepare('DELETE FROM competitors WHERE user_id IN (SELECT id FROM users WHERE email LIKE :email)')->execute($like);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute($like);
    }

    /** @return array<string, mixed> */
    private function user(string $tag, string $password): array
    {
        return $this->users->create([
            'name' => 'Demo ' . $tag,
            'email' => $this->prefix . '-' . $tag . '@example.test',
            'password' => $password,
        ]);
    }

    private function track(string $id): void
    {
        $this->pdo->prepare("INSERT INTO seed_records (table_name, record_key) VALUES ('users', :k)")
            ->execute(['k' => json_encode([$id])]);
    }

    /** @return array{hash: string, tv: int} */
    private function row(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT password_hash AS hash, token_version AS tv FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return ['hash' => (string) $row['hash'], 'tv' => (int) $row['tv']];
    }

    private function setHash(string $id, string $hash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute(['h' => $hash, 'id' => $id]);
    }

    public function testScrubTargetsManifestAndKnownHashAccountsOnly(): void
    {
        $manifest = $this->user('manifest', 'demo1234');
        $this->track((string) $manifest['id']);
        $knownHash = $this->user('knownhash', 'something-else-1');
        $this->setHash((string) $knownHash['id'], DemoAccountPasswords::KNOWN_DEMO_HASHES[0]);
        $real = $this->user('real', 'a-real-password');
        $legacyDemo = $this->user('legacy', 'demo123');
        $realBefore = $this->row((string) $real['id']);
        $legacyBefore = $this->row((string) $legacyDemo['id']);

        $count = DemoAccountPasswords::scrub($this->pdo);

        self::assertGreaterThanOrEqual(2, $count);
        foreach ([$manifest, $knownHash] as $user) {
            $row = $this->row((string) $user['id']);
            self::assertFalse(password_verify('demo1234', $row['hash']));
            self::assertSame(1, $row['tv'], 'sessions ended');
        }
        self::assertSame($realBefore, $this->row((string) $real['id']));
        self::assertSame($legacyBefore, $this->row((string) $legacyDemo['id']), 'non-deep leaves non-manifest demo123 alone');
    }

    public function testDeepAlsoCatchesAccountsThatVerifyADemoPassword(): void
    {
        $legacy = $this->user('legacy', 'demo123');
        $legacy4 = $this->user('legacy4', 'demo1234');
        $real = $this->user('real', 'a-real-password');
        $realBefore = $this->row((string) $real['id']);

        $count = DemoAccountPasswords::scrub($this->pdo, true);

        self::assertGreaterThanOrEqual(2, $count);
        self::assertFalse(password_verify('demo123', $this->row((string) $legacy['id'])['hash']));
        self::assertFalse(password_verify('demo1234', $this->row((string) $legacy4['id'])['hash']));
        self::assertSame($realBefore, $this->row((string) $real['id']));
    }

    public function testSecondRunChangesNothingFurther(): void
    {
        $manifest = $this->user('manifest', 'demo1234');
        $this->track((string) $manifest['id']);

        DemoAccountPasswords::scrub($this->pdo);
        $first = $this->row((string) $manifest['id']);
        DemoAccountPasswords::scrub($this->pdo, true);

        self::assertFalse(password_verify('demo1234', $this->row((string) $manifest['id'])['hash']));
        self::assertSame(1, $first['tv']);
    }

    public function testDryRunCountsWithoutWriting(): void
    {
        $manifest = $this->user('manifest', 'demo1234');
        $this->track((string) $manifest['id']);
        $before = $this->row((string) $manifest['id']);

        $count = DemoAccountPasswords::scrub($this->pdo, false, true);

        self::assertGreaterThanOrEqual(1, $count);
        self::assertSame($before, $this->row((string) $manifest['id']));
    }
}

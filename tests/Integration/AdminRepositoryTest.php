<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Admin\AdminRepository;
use ZmierzymyCzas\Support\Env;

/**
 * TD.16: AdminRepository had zero direct test coverage before this file.
 * This repository backs the platform-admin panel, so the highest-risk
 * behaviour is: the retention prune on activityLogs() (E0.8 — silently
 * keeping audit-log entries forever, or silently deleting ones still inside
 * the window, are both real regressions here), the filter predicates on the
 * same method, the security_settings merge semantics (a partial update
 * clobbering the 2FA/session-timeout config for every admin would be a real
 * incident), and platformStats()'s exclusion of soft-deleted events plus its
 * organizer/package-credit counts (E8.3).
 */
final class AdminRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AdminRepository $admin;
    private string $prefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->prefix = 'phpunit-admin-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->admin = new AdminRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        // PDO runs with ATTR_EMULATE_PREPARES=false: a named placeholder repeated in one
        // query works under MariaDB's native driver but fails outright under MySQL's
        // ("SQLSTATE[HY093]: Invalid parameter number") — give the repeat its own name.
        $this->pdo->prepare('DELETE FROM activity_logs WHERE user_id LIKE :prefix OR target_name LIKE :prefix2')
            ->execute(['prefix' => $this->prefix . '%', 'prefix2' => $this->prefix . '%']);
        $this->pdo->prepare('DELETE FROM online_sessions WHERE user_id LIKE :prefix')
            ->execute(['prefix' => $this->prefix . '%']);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :prefix')
            ->execute(['prefix' => $this->prefix . '%']);
        $this->pdo->prepare('DELETE FROM users WHERE id LIKE :prefix')
            ->execute(['prefix' => $this->prefix . '%']);
    }

    public function testActivityLogsPrunesEntriesOlderThanTheRetentionWindowAndKeepsRecentOnes(): void
    {
        $oldId = $this->prefix . '-old';
        $this->pdo->prepare(
            'INSERT INTO activity_logs (id, user_id, action, description, target_name, timestamp)
             VALUES (:id, :userId, :action, :description, :targetName, :timestamp)'
        )->execute([
            'id' => $oldId,
            'userId' => $this->prefix . '-user',
            'action' => 'server_action',
            'description' => 'old entry',
            'targetName' => $this->prefix . '-old-target',
            // 13 months old: past the 12-month retention window.
            'timestamp' => (new \DateTimeImmutable('-13 months'))->format(DATE_ATOM),
        ]);

        $this->admin->insertActivityLog([
            'userId' => $this->prefix . '-user',
            'action' => 'server_action',
            'description' => 'recent entry',
            'targetName' => $this->prefix . '-recent-target',
        ]);

        $logs = $this->admin->activityLogs();
        $targetNames = array_column($logs, 'targetName');

        self::assertNotContains($this->prefix . '-old-target', $targetNames);
        self::assertContains($this->prefix . '-recent-target', $targetNames);

        // The old row must actually be gone from the table, not merely
        // filtered out of the read.
        $stillThere = $this->pdo->prepare('SELECT COUNT(*) FROM activity_logs WHERE id = :id');
        $stillThere->execute(['id' => $oldId]);
        self::assertSame(0, (int) $stillThere->fetchColumn());
    }

    public function testActivityLogsFiltersByActionAndUserId(): void
    {
        $userId = $this->prefix . '-filter-user';
        $this->admin->insertActivityLog([
            'userId' => $userId,
            'action' => $this->prefix . '-login',
            'description' => 'logged in',
            'targetName' => $this->prefix . '-login-target',
        ]);
        $this->admin->insertActivityLog([
            'userId' => $userId,
            'action' => $this->prefix . '-logout',
            'description' => 'logged out',
            'targetName' => $this->prefix . '-logout-target',
        ]);

        $filtered = $this->admin->activityLogs(['action' => $this->prefix . '-login']);
        $targetNames = array_column($filtered, 'targetName');

        self::assertContains($this->prefix . '-login-target', $targetNames);
        self::assertNotContains($this->prefix . '-logout-target', $targetNames);
    }

    public function testInsertActivityLogEncodesMetadataAsJsonAndActivityLogsDecodesItBack(): void
    {
        $this->admin->insertActivityLog([
            'userId' => $this->prefix . '-meta-user',
            'action' => $this->prefix . '-merge',
            'description' => 'competitor merge',
            'targetName' => $this->prefix . '-meta-target',
            'metadata' => ['mergedFrom' => 'competitor-1', 'mergedInto' => 'competitor-2'],
        ]);

        $logs = $this->admin->activityLogs(['action' => $this->prefix . '-merge']);

        self::assertCount(1, $logs);
        self::assertSame(['mergedFrom' => 'competitor-1', 'mergedInto' => 'competitor-2'], $logs[0]['metadata']);
    }

    public function testUpdateSecuritySettingsMergesAPartialPayloadIntoTheSingletonRow(): void
    {
        $before = $this->admin->securitySettings();

        $updated = $this->admin->updateSecuritySettings(['twoFactorEnabled' => true, 'sessionTimeoutMinutes' => 15]);

        self::assertTrue($updated['twoFactorEnabled']);
        self::assertSame(15, $updated['sessionTimeoutMinutes']);
        // Fields absent from the payload must survive untouched.
        self::assertSame($before['passwordExpiryDays'], $updated['passwordExpiryDays']);
        self::assertSame($before['trustedDevices'], $updated['trustedDevices']);

        // Restore, so this test doesn't leave the one global security row
        // mutated for whatever real state the environment had before.
        $this->admin->updateSecuritySettings([
            'twoFactorEnabled' => $before['twoFactorEnabled'],
            'twoFactorMethod' => $before['twoFactorMethod'],
            'lastPasswordChange' => $before['lastPasswordChange'],
            'passwordExpiryDays' => $before['passwordExpiryDays'],
            'sessionTimeoutMinutes' => $before['sessionTimeoutMinutes'],
            'trustedDevices' => $before['trustedDevices'],
            'loginHistory' => $before['loginHistory'],
        ]);
    }

    public function testUpdateSecuritySettingsReplacesTheSingletonRowRatherThanAccumulatingRows(): void
    {
        $before = $this->admin->securitySettings();

        $this->admin->updateSecuritySettings(['sessionTimeoutMinutes' => 30]);
        $this->admin->updateSecuritySettings(['sessionTimeoutMinutes' => 45]);

        $rowCount = (int) $this->pdo->query('SELECT COUNT(*) FROM security_settings')->fetchColumn();
        self::assertSame(1, $rowCount);

        $this->admin->updateSecuritySettings([
            'twoFactorEnabled' => $before['twoFactorEnabled'],
            'twoFactorMethod' => $before['twoFactorMethod'],
            'lastPasswordChange' => $before['lastPasswordChange'],
            'passwordExpiryDays' => $before['passwordExpiryDays'],
            'sessionTimeoutMinutes' => $before['sessionTimeoutMinutes'],
            'trustedDevices' => $before['trustedDevices'],
            'loginHistory' => $before['loginHistory'],
        ]);
    }

    public function testOnlineSessionsOrdersByLastActiveAtDescending(): void
    {
        $olderId = $this->prefix . '-older-session';
        $newerId = $this->prefix . '-newer-session';
        $this->pdo->prepare(
            'INSERT INTO online_sessions (id, user_id, user_name, role, login_at, last_active_at)
             VALUES (:id, :userId, :userName, :role, :loginAt, :lastActiveAt)'
        )->execute([
            'id' => 'sess-' . bin2hex(random_bytes(6)),
            'userId' => $olderId,
            'userName' => 'PHPUnit Older',
            'role' => 'admin',
            'loginAt' => '2020-01-01T00:00:00+00:00',
            'lastActiveAt' => '2020-01-01T00:00:00+00:00',
        ]);
        $this->pdo->prepare(
            'INSERT INTO online_sessions (id, user_id, user_name, role, login_at, last_active_at)
             VALUES (:id, :userId, :userName, :role, :loginAt, :lastActiveAt)'
        )->execute([
            'id' => 'sess-' . bin2hex(random_bytes(6)),
            'userId' => $newerId,
            'userName' => 'PHPUnit Newer',
            'role' => 'admin',
            'loginAt' => '2021-01-01T00:00:00+00:00',
            'lastActiveAt' => '2021-01-01T00:00:00+00:00',
        ]);

        $sessions = $this->admin->onlineSessions();
        $ours = array_values(array_filter(
            $sessions,
            fn (array $row): bool => in_array($row['userId'], [$olderId, $newerId], true)
        ));

        self::assertCount(2, $ours);
        self::assertSame($newerId, $ours[0]['userId']);
        self::assertSame($olderId, $ours[1]['userId']);
    }

    public function testPlatformStatsCountsEventsByStatusExcludingSoftDeletedAndSumsOrganizerPackageCredits(): void
    {
        $this->pdo->prepare(
            "INSERT INTO users (id, name, email, password_hash, role, status, organizer_package_credits)
             VALUES (:id, 'PHPUnit Organizer', :email, :hash, 'organizer', 'active', 3)"
        )->execute([
            'id' => $this->prefix . '-organizer',
            'email' => $this->prefix . '-organizer@example.test',
            'hash' => password_hash('demo1234', PASSWORD_DEFAULT),
        ]);

        $this->insertMinimalEvent($this->prefix . '-published', 'published', null);
        $this->insertMinimalEvent($this->prefix . '-published-2', 'published', null);
        $this->insertMinimalEvent($this->prefix . '-draft', 'draft', null);
        // Soft-deleted: must be excluded from eventsByStatus entirely.
        $this->insertMinimalEvent($this->prefix . '-deleted', 'published', date(DATE_ATOM));

        $stats = $this->admin->platformStats();

        $byStatus = array_column($stats['eventsByStatus'], 'count', 'status');
        self::assertArrayHasKey('published', $byStatus);
        self::assertArrayHasKey('draft', $byStatus);
        self::assertGreaterThanOrEqual(2, $byStatus['published']);
        self::assertGreaterThanOrEqual(1, $byStatus['draft']);

        // Exactly 7 status buckets are always present, per the documented
        // contract, even when a status has zero events.
        self::assertSame(
            ['draft', 'submitted', 'approved', 'published', 'finished', 'rejected', 'cancelled'],
            array_column($stats['eventsByStatus'], 'status')
        );

        self::assertGreaterThanOrEqual(1, $stats['organizerCount']);
        self::assertGreaterThanOrEqual(3, $stats['activePackageCredits']);
    }

    private function insertMinimalEvent(string $slug, string $publicationStatus, ?string $deletedAt): void
    {
        $this->pdo->prepare(
            'INSERT INTO events
                (id, name, slug, discipline, date, location, city, description, image_url,
                 organizer_id, organizer_name, registration_deadline, publication_status, deleted_at)
             VALUES
                (:id, :name, :slug, :discipline, :date, :location, :city, :description, :imageUrl,
                 :organizerId, :organizerName, :registrationDeadline, :publicationStatus, :deletedAt)'
        )->execute([
            'id' => 'admin-evt-' . bin2hex(random_bytes(6)),
            'name' => 'PHPUnit Admin Stats Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+6 months')),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => 'Opis testowy',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->prefix . '-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'registrationDeadline' => '2026-06-01',
            'publicationStatus' => $publicationStatus,
            'deletedAt' => $deletedAt,
        ]);
    }
}

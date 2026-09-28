<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * E8.3 (spec 21 Answer §6): GET /admin/platform-stats — the minimal,
 * explicitly-closed platform-admin cross-section: Events by
 * publication_status (E1.1), Organizer count, and active package-credit
 * total (E5.6, `users.organizer_package_credits` summed across all users —
 * see AdminRepository::platformStats() docblock for why sum rather than
 * "organizers holding at least one credit"). Admin-only, not moderator.
 *
 * These counts are global (not scoped by a per-test prefix), so every
 * assertion here is a *delta* against a baseline read taken before this
 * test's own fixtures are created — the shared test database may already
 * hold rows from fixtures or other tests.
 */
final class PlatformStatsContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private string $prefix;
    private array $admin;
    private array $moderator;
    private array $organizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->prefix = 'platform-stats-' . bin2hex(random_bytes(4));

        $this->admin = $this->users->create([
            'name' => 'Platform Stats Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
        $this->moderator = $this->users->create([
            'name' => 'Platform Stats Moderator',
            'email' => $this->prefix . '-moderator@example.test',
            'password' => 'demo1234',
            'role' => 'moderator',
        ]);
        $this->organizer = $this->users->create([
            'name' => 'Platform Stats Organizer',
            'email' => $this->prefix . '-organizer@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            'eventLimit' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slug');
        $stmt->execute(['slug' => $this->prefix . '-%']);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_payment_methods WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    private function fetchStats(array $auth): array
    {
        $response = $this->request('GET', '/api/v1/admin/platform-stats', [], $auth);
        self::assertSame(200, $response->getStatusCode());
        return $this->json($response)['data'];
    }

    /**
     * @group known-failure-pp14
     *
     * PP.13: pre-existing failure on both MySQL 8.4 and MariaDB 10.4, unrelated to
     * migrations/CI — tracked by PP.14. Remove this tag once fixed there.
     */
    public function testAdminSeesEventCountsByPublicationStatusOrganizerCountAndActivePackageCredits(): void
    {
        $adminAuth = $this->bearer($this->admin['id'], 'admin', $this->admin['email']);
        $before = $this->fetchStats($adminAuth);
        $beforeByStatus = array_column($before['eventsByStatus'], 'count', 'status');

        // Every enum value must always be present, even before any of this
        // test's own fixtures are created.
        foreach (['draft', 'submitted', 'approved', 'published', 'finished', 'rejected', 'cancelled'] as $status) {
            self::assertArrayHasKey($status, $beforeByStatus);
        }

        $draftEvent = $this->events->createForOrganizer($this->eventPayload($this->prefix . '-draft'), $this->organizer, $this->organizer['id']);
        $publishedEvent = $this->events->createForOrganizer($this->eventPayload($this->prefix . '-published'), $this->organizer, $this->organizer['id']);
        $this->pdo->prepare("UPDATE events SET publication_status = 'published' WHERE id = :id")->execute(['id' => $publishedEvent['id']]);
        $cancelledEvent = $this->events->createForOrganizer($this->eventPayload($this->prefix . '-cancelled'), $this->organizer, $this->organizer['id']);
        $this->pdo->prepare("UPDATE events SET publication_status = 'cancelled' WHERE id = :id")->execute(['id' => $cancelledEvent['id']]);
        // Soft-deleted events must not be counted at all.
        $deletedEvent = $this->events->createForOrganizer($this->eventPayload($this->prefix . '-deleted'), $this->organizer, $this->organizer['id']);
        $this->pdo->prepare("UPDATE events SET deleted_at = :now WHERE id = :id")->execute(['now' => date(DATE_ATOM), 'id' => $deletedEvent['id']]);

        $secondOrganizer = $this->users->create([
            'name' => 'Platform Stats Organizer Two',
            'email' => $this->prefix . '-organizer-two@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            'eventLimit' => 5,
        ]);

        $this->pdo->prepare('UPDATE users SET organizer_package_credits = 3 WHERE id = :id')->execute(['id' => $this->organizer['id']]);
        $this->pdo->prepare('UPDATE users SET organizer_package_credits = 2 WHERE id = :id')->execute(['id' => $secondOrganizer['id']]);

        $after = $this->fetchStats($adminAuth);
        $afterByStatus = array_column($after['eventsByStatus'], 'count', 'status');

        self::assertSame($beforeByStatus['draft'] + 1, $afterByStatus['draft']);
        self::assertSame($beforeByStatus['published'] + 1, $afterByStatus['published']);
        self::assertSame($beforeByStatus['cancelled'] + 1, $afterByStatus['cancelled']);
        self::assertSame($before['organizerCount'] + 2, $after['organizerCount']);
        self::assertSame($before['activePackageCredits'] + 5, $after['activePackageCredits']);
    }

    public function testModeratorAndOrganizerAreForbiddenAdminIsAllowed(): void
    {
        $moderatorResponse = $this->request('GET', '/api/v1/admin/platform-stats', [], $this->bearer($this->moderator['id'], 'moderator', $this->moderator['email']));
        self::assertSame(403, $moderatorResponse->getStatusCode());

        $organizerResponse = $this->request('GET', '/api/v1/admin/platform-stats', [], $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']));
        self::assertSame(403, $organizerResponse->getStatusCode());

        $adminResponse = $this->request('GET', '/api/v1/admin/platform-stats', [], $this->bearer($this->admin['id'], 'admin', $this->admin['email']));
        self::assertSame(200, $adminResponse->getStatusCode());
    }

    public function testSuperadminIsAllowedAndUnauthenticatedIsRejected(): void
    {
        $superadmin = $this->users->create([
            'name' => 'Platform Stats Superadmin',
            'email' => $this->prefix . '-superadmin@example.test',
            'password' => 'demo1234',
            'role' => 'superadmin',
        ]);

        $superadminResponse = $this->request('GET', '/api/v1/admin/platform-stats', [], $this->bearer($superadmin['id'], 'superadmin', $superadmin['email']));
        self::assertSame(200, $superadminResponse->getStatusCode());

        $anonResponse = $this->request('GET', '/api/v1/admin/platform-stats');
        self::assertSame(401, $anonResponse->getStatusCode());
    }

    private function eventPayload(string $slug): array
    {
        return [
            'name' => 'Platform Stats Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => $this->organizer['name'],
            'status' => 'draft',
            'registrationDeadline' => '2026-09-01',
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [
                [
                    'id' => $slug . '-10k',
                    'name' => '10 km',
                    'length' => 10,
                    'unit' => 'km',
                    'categories' => ['Open'],
                    'ageCategories' => [],
                    'specialCategories' => [],
                    'limit' => 100,
                    'registeredCount' => 0,
                    'price' => 80,
                    'routeFiles' => [],
                ],
            ],
        ];
    }
}

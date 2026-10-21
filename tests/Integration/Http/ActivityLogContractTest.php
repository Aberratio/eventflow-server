<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * The activity/audit log must be server-authored only — no route lets a client
 * forge an entry attributed to someone else, staff mutations are logged automatically
 * with the actor pulled from the verified JWT, global reads are admin/superadmin only,
 * and an event's organizer can see the slice of the log scoped to their own event.
 */
final class ActivityLogContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private string $prefix;
    private array $admin;
    private array $moderator;
    private array $organizer;
    private array $otherOrganizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->prefix = 'activity-log-' . bin2hex(random_bytes(4));

        $this->admin = $this->users->create([
            'name' => 'Activity Log Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
        $this->moderator = $this->users->create([
            'name' => 'Activity Log Moderator',
            'email' => $this->prefix . '-moderator@example.test',
            'password' => 'demo1234',
            'role' => 'moderator',
        ]);
        $this->organizer = $this->users->create([
            'name' => 'Activity Log Organizer',
            'email' => $this->prefix . '-organizer@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            'eventLimit' => 5,
        ]);
        $this->otherOrganizer = $this->users->create([
            'name' => 'Activity Log Other Organizer',
            'email' => $this->prefix . '-other-organizer@example.test',
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

        $this->pdo->prepare('DELETE FROM activity_logs WHERE user_id IN (SELECT id FROM users WHERE email LIKE :email)')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => $this->prefix . '-%']);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $id) {
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_payment_methods WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute([
            'email' => $this->prefix . '-%@example.test',
        ]);
    }

    public function testClientLogsRouteNoLongerExists(): void
    {
        $response = $this->request('POST', '/api/v1/admin/client-logs', [
            'logs' => [[
                'action' => 'forged_action',
                'userId' => $this->admin['id'],
                'userName' => 'Forged Admin',
            ]],
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testUserRoleChangeIsLoggedServerSideWithActorFromJwt(): void
    {
        $target = $this->users->create([
            'name' => 'Activity Log Target',
            'email' => $this->prefix . '-target@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);

        $response = $this->request(
            'PATCH',
            '/api/v1/users/' . $target['id'] . '/role',
            ['role' => 'moderator'],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $response->getStatusCode());

        $logs = $this->request('GET', '/api/v1/admin/activity-logs?action=user_role_changed', [], $this->bearer($this->admin['id'], 'admin', $this->admin['email']));
        $payload = $this->json($logs);
        self::assertTrue($payload['success']);

        $entry = null;
        foreach ($payload['data'] as $row) {
            if ($row['targetId'] === $target['id']) {
                $entry = $row;
                break;
            }
        }

        self::assertNotNull($entry, 'Expected a server-written activity log entry for the role change');
        self::assertSame($this->admin['id'], $entry['userId'], 'Actor must come from the verified JWT, not the request body');
    }

    public function testGlobalActivityLogsAreAdminOnlyAndModeratorLosesAccess(): void
    {
        $moderatorResponse = $this->request('GET', '/api/v1/admin/activity-logs', [], $this->bearer($this->moderator['id'], 'moderator', $this->moderator['email']));
        self::assertSame(403, $moderatorResponse->getStatusCode());

        $adminResponse = $this->request('GET', '/api/v1/admin/activity-logs', [], $this->bearer($this->admin['id'], 'admin', $this->admin['email']));
        self::assertSame(200, $adminResponse->getStatusCode());
    }

    public function testOrganizerCanReadOwnEventActivityLogsButNotAnotherOrganizersEvent(): void
    {
        $event = $this->events->createForOrganizer($this->eventPayload(), $this->organizer, $this->organizer['id']);

        $updateResponse = $this->request(
            'PATCH',
            '/api/v1/events/' . $event['id'],
            ['name' => 'Renamed by organizer'],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        self::assertSame(200, $updateResponse->getStatusCode());

        $ownerResponse = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'] . '/activity-logs',
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        $ownerPayload = $this->json($ownerResponse);
        self::assertSame(200, $ownerResponse->getStatusCode());
        self::assertNotEmpty($ownerPayload['data']);
        foreach ($ownerPayload['data'] as $row) {
            self::assertSame($event['id'], $row['targetId']);
        }

        $strangerResponse = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'] . '/activity-logs',
            [],
            $this->bearer($this->otherOrganizer['id'], 'organizer', $this->otherOrganizer['email'])
        );
        self::assertSame(403, $strangerResponse->getStatusCode());
    }

    private function eventPayload(array $overrides = []): array
    {
        $slug = $this->prefix . '-event';

        return array_replace_recursive([
            'name' => 'Activity Log Event',
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
        ], $overrides);
    }
}

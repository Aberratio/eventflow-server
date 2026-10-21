<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;
use ZmierzymyCzas\Tests\Support\SavepointPdo;

/**
 * Private / soft-deleted Events never leak through the public Event reads, and a
 * non-manager payload is the PublicEventPresenter allowlist shape.
 */
final class PublicEventVisibilityContractTest extends HttpTestCase
{
    private const PASSWORD = 'pp55-pass-1234';

    private SavepointPdo $savepointPdo;
    private string $prefix;
    /** @var array<string, string> */
    private array $ids = [];
    /** @var array<string, string> */
    private array $slugs = [];
    /** @var array<string, array{id: string, role: string, email: string}> */
    private array $users = [];

    protected function createPdo(): \PDO
    {
        $this->savepointPdo = new SavepointPdo();
        $this->savepointPdo->beginOuter();

        return $this->savepointPdo;
    }

    protected function tearDown(): void
    {
        if (isset($this->savepointPdo)) {
            $this->savepointPdo->rollbackOuter();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'pp55-' . bin2hex(random_bytes(4));
        $usersRepo = new UserRepository($this->pdo);
        $roles = ['O' => 'organizer', 'F' => 'organizer', 'P' => 'participant', 'D' => 'participant', 'Q' => 'participant', 'X' => 'participant', 'A' => 'admin', 'S' => 'superadmin', 'M' => 'moderator'];
        foreach ($roles as $key => $role) {
            $email = $this->prefix . '-' . strtolower($key) . '@example.test';
            $user = $usersRepo->create(['name' => 'Pp55 ' . $key, 'email' => $email, 'password' => self::PASSWORD, 'role' => $role]);
            $this->users[$key] = ['id' => (string) $user['id'], 'role' => $role, 'email' => $email];
        }

        $events = new EventRepository($this->pdo);
        $o = $this->users['O']['id'];
        $pub = $events->create($this->payload('pub', $o, 'active', false, [
            'accessCode' => 'stale-code',
            'bzIntegrationEnabled' => true,
            'bzOrganizationToken' => 'ORG-PP55',
            'discountCodes' => [[
                'id' => $this->prefix . '-dc',
                'code' => 'PP55',
                'discountType' => 'percent',
                'discountValue' => 10,
                'active' => true,
                'usedCount' => 1,
                'usedBy' => [[
                    'userId' => $this->users['P']['id'],
                    'userName' => 'Jan Tajny',
                    'usedAt' => date(DATE_ATOM),
                    'registrationId' => 'r-pp55',
                    'distanceName' => '10 km',
                ]],
            ]],
        ]));
        $priv = $events->create($this->payload('priv', $o, 'active', true, [
            'accessCode' => 'pp55-code',
            'organizerContact' => ['email' => 'tajny@example.test', 'phone' => '+48 500 000 000'],
        ]));
        $draft = $events->create($this->payload('draft', $o, 'draft', false));
        $del = $events->create($this->payload('del', $o, 'active', false));
        $events->delete((string) $del['id']);
        foreach ([$pub, $priv, $del] as $e) {
            $this->pdo->prepare('UPDATE events SET featured = 1, is_live = 1 WHERE id = :id')->execute(['id' => $e['id']]);
        }
        $this->ids = ['pub' => (string) $pub['id'], 'priv' => (string) $priv['id'], 'del' => (string) $del['id'], 'draft' => (string) $draft['id']];
        $this->slugs = ['pub' => (string) $pub['slug'], 'priv' => (string) $priv['slug']];

        // P holds a Zapis on Priv as user_id; D as the registering delegate for X.
        $this->zapis('P', 'P', null, $priv);
        $this->zapis('X', 'X', 'D', $priv);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(string $name, string $organizerId, string $status, bool $private, array $overrides = []): array
    {
        $slug = $this->prefix . '-' . $name;

        return array_replace([
            'name' => $this->prefix . ' ' . $name,
            'slug' => $slug,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+60 days')),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $organizerId,
            'organizerName' => 'Pp55 organizer',
            'status' => $status,
            'registrationDeadline' => date('Y-m-d', strtotime('+50 days')),
            'featured' => false,
            'isPrivate' => $private,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
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
            ]],
        ], $overrides);
    }

    /** @param array<string, mixed> $event */
    private function zapis(string $tag, string $user, ?string $registeredBy, array $event): void
    {
        $this->pdo->prepare(
            'INSERT INTO registrations (id, event_id, event_name, user_id, registered_by_id, user_name, user_email, distance_id, distance_name, category, registered_at, status, qr_code)
             VALUES (:id, :eventId, :eventName, :userId, :registeredBy, :userName, :userEmail, :distanceId, :distanceName, :category, :registeredAt, :status, :qrCode)'
        )->execute([
            'id' => $this->prefix . '-reg-' . $tag,
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'userId' => $this->users[$user]['id'],
            'registeredBy' => $registeredBy === null ? null : $this->users[$registeredBy]['id'],
            'userName' => 'Pp55 ' . $tag,
            'userEmail' => $this->users[$user]['email'],
            'distanceId' => $event['distances'][0]['id'],
            'distanceName' => $event['distances'][0]['name'],
            'category' => 'Open',
            'registeredAt' => date(DATE_ATOM),
            'status' => 'confirmed',
            'qrCode' => 'pp55_' . bin2hex(random_bytes(16)),
        ]);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function get(string $path, ?string $who = null): array
    {
        $headers = $who === null ? [] : $this->bearer($this->users[$who]['id'], $this->users[$who]['role'], $this->users[$who]['email']);
        $response = $this->request('GET', $path, [], $headers);

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true) ?? []];
    }

    /** @return array{0: list<string>, 1: array<string, array<string, mixed>>, 2: int} ids, rows by id, total */
    private function listing(string $query, ?string $who = null): array
    {
        $r = $this->get('/api/v1/events?search=' . $this->prefix . '&pageSize=100' . $query, $who);
        self::assertSame(200, $r['status'], $query);
        $rows = [];
        foreach ($r['body']['data'] as $row) {
            $rows[(string) $row['id']] = $row;
        }

        return [array_map('strval', array_keys($rows)), $rows, (int) $r['body']['total']];
    }

    public function testAnonymousIncludePrivateNeverListsPrivateOrDeleted(): void
    {
        [, , $plainTotal] = $this->listing('');
        foreach (['&includePrivate=1', '&includePrivate=true', '&includePrivate=1&includeUnpublished=1&includeDeleted=1', '&includePrivate[]=1'] as $q) {
            [$ids, , $total] = $this->listing($q);
            self::assertContains($this->ids['pub'], $ids, $q);
            self::assertNotContains($this->ids['priv'], $ids, $q);
            self::assertNotContains($this->ids['del'], $ids, $q);
            self::assertNotContains($this->ids['draft'], $ids, $q);
            self::assertSame($plainTotal, $total, $q);
        }
    }

    public function testParticipantSeesOnlyPrivateEventsWithOwnZapis(): void
    {
        foreach (['P', 'D'] as $who) {
            [$ids, $rows, $total] = $this->listing('&includePrivate=1', $who);
            self::assertContains($this->ids['priv'], $ids, $who);
            self::assertNull($rows[$this->ids['priv']]['accessCode'], $who);
            self::assertNotEmpty($rows[$this->ids['priv']]['organizerContact'], $who);
            self::assertSame(count($ids), $total, $who);
        }
        [$ids] = $this->listing('&includePrivate=1', 'Q');
        self::assertNotContains($this->ids['priv'], $ids);
        [$ids] = $this->listing('', 'P');
        self::assertNotContains($this->ids['priv'], $ids);
    }

    public function testOrganizerSeesOwnPrivateEventsOnly(): void
    {
        [$ids, $rows] = $this->listing('&includePrivate=1', 'O');
        self::assertContains($this->ids['priv'], $ids);
        self::assertSame('pp55-code', $rows[$this->ids['priv']]['accessCode']);
        [$ids] = $this->listing('&includePrivate=1', 'F');
        self::assertNotContains($this->ids['priv'], $ids);
    }

    public function testStaffListKeepsPrivateAndRawPayload(): void
    {
        foreach (['&includeUnpublished=1&includePrivate=1', '&includePrivate=1'] as $q) {
            [$ids, $rows] = $this->listing($q, 'A');
            self::assertContains($this->ids['priv'], $ids, $q);
            self::assertSame('pp55-code', $rows[$this->ids['priv']]['accessCode'], $q);
        }
        [$ids] = $this->listing('&includeUnpublished=1&includeDeleted=1', 'S');
        self::assertContains($this->ids['del'], $ids);
    }

    public function testFeaturedAndLiveHidePrivateAndDeleted(): void
    {
        foreach ([null, 'A'] as $who) {
            foreach (['featured', 'live'] as $feed) {
                $label = $feed . ' / ' . ($who ?? 'anon');
                $r = $this->get('/api/v1/events/' . $feed, $who);
                self::assertSame(200, $r['status'], $label);
                $ids = array_map(static fn (array $e): string => (string) $e['id'], $r['body']['data']);
                self::assertContains($this->ids['pub'], $ids, $label);
                self::assertNotContains($this->ids['priv'], $ids, $label);
                self::assertNotContains($this->ids['del'], $ids, $label);
            }
        }
    }

    /** @param array<string, mixed> $event */
    private function assertPublicShape(array $event, string $where): void
    {
        self::assertArrayHasKey('accessCode', $event, $where);
        self::assertNull($event['accessCode'], $where);
        self::assertNull($event['bzOrganizationToken'], $where);
        foreach (['createdBy', 'rejectionReason', 'eventCreditKind', 'deletedAt', 'submittedAt', 'approvedAt', 'approvedBy', 'publishedAt', 'publishedBy'] as $key) {
            self::assertArrayNotHasKey($key, $event, "{$where}: {$key}");
        }
        self::assertSame('PP55', $event['discountCodes'][0]['code'] ?? null, $where);
        self::assertArrayNotHasKey('usedBy', $event['discountCodes'][0], $where);
    }

    public function testPublicPayloadShapeOnEveryReadPath(): void
    {
        [, $rows] = $this->listing('');
        $this->assertPublicShape($rows[$this->ids['pub']], 'list');
        foreach (['featured', 'live'] as $feed) {
            $r = $this->get('/api/v1/events/' . $feed);
            $matches = array_values(array_filter($r['body']['data'], fn (array $e): bool => $e['id'] === $this->ids['pub']));
            self::assertCount(1, $matches, $feed);
            $this->assertPublicShape($matches[0], $feed);
        }
        $this->assertPublicShape($this->get('/api/v1/events/' . $this->ids['pub'])['body']['data'], 'by id');
        $this->assertPublicShape($this->get('/api/v1/events/slug/' . $this->slugs['pub'])['body']['data'], 'by slug');
    }

    public function testUnknownEventSlugAndIdReturn404ErrorContract(): void
    {
        foreach (['/api/v1/events/slug/no-such-slug-' . $this->prefix, '/api/v1/events/no-such-id-' . $this->prefix] as $path) {
            foreach ([null, 'A'] as $who) {
                $r = $this->get($path, $who);
                self::assertSame(404, $r['status'], $path);
                self::assertFalse($r['body']['success'], $path);
                self::assertSame('not_found', $r['body']['error']['code'], $path);
                self::assertArrayNotHasKey('data', $r['body'], $path);
            }
        }
    }

    public function testPrivateEventByLinkWithCodeHidesTheCode(): void
    {
        foreach (['/api/v1/events/' . $this->ids['priv'], '/api/v1/events/slug/' . $this->slugs['priv']] as $path) {
            $ok = $this->get($path . '?accessCode=pp55-code');
            self::assertSame(200, $ok['status'], $path);
            self::assertNull($ok['body']['data']['accessCode'], $path);
            self::assertSame(404, $this->get($path)['status'], $path);
            self::assertSame(404, $this->get($path . '?accessCode=wrong')['status'], $path);
            foreach (['O', 'A', 'M'] as $who) {
                $r = $this->get($path, $who);
                self::assertSame(200, $r['status'], "{$path} / {$who}");
                self::assertSame('pp55-code', $r['body']['data']['accessCode'], "{$path} / {$who}");
            }
        }
        $owner = $this->get('/api/v1/events/' . $this->ids['pub'], 'O');
        self::assertArrayHasKey('usedBy', $owner['body']['data']['discountCodes'][0]);
        $draft = $this->get('/api/v1/events/' . $this->ids['draft'], 'A');
        self::assertSame(200, $draft['status']);
        self::assertArrayHasKey('createdBy', $draft['body']['data']);
    }
}

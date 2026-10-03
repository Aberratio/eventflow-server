<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

final class RouteContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private string $prefix;
    private array $admin;
    private array $participant;
    private array $activeEvent;
    private array $privateEvent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->prefix = 'http-contract-' . bin2hex(random_bytes(4));

        $this->ensureEventStatusWorkflowSchema();
        $this->admin = $this->users->create([
            'name' => 'PHPUnit Contract Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
        $this->participant = $this->users->create([
            'name' => 'PHPUnit Contract Participant',
            'email' => $this->prefix . '-participant@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $this->activeEvent = $this->events->create($this->eventPayload([
            'slug' => $this->prefix . '-active',
            'name' => 'PHPUnit Contract Active Event',
            'status' => 'active',
        ]));
        $this->events->create($this->eventPayload([
            'slug' => $this->prefix . '-draft',
            'name' => 'PHPUnit Contract Draft Event',
            'status' => 'draft',
        ]));
        $this->privateEvent = $this->events->create($this->eventPayload([
            'slug' => $this->prefix . '-private',
            'name' => 'PHPUnit Contract Private Event',
            'status' => 'active',
            'isPrivate' => true,
            'accessCode' => 'phpunit-secret',
        ]));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $this->deleteContractEvents();
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute([
            'email' => $this->prefix . '-%@example.test',
        ]);
    }

    public function testHealthReturnsStableSuccessShape(): void
    {
        $response = $this->request('GET', '/api/v1/health');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame('ok', $payload['data']['status']);
        self::assertSame('ok', $payload['data']['checks']['database']);
        self::assertArrayNotHasKey('env', $payload['data']);
    }

    public function testEventsListIsPaginatedAndHidesDraftAndPrivateEvents(): void
    {
        $response = $this->request('GET', '/api/v1/events?search=PHPUnit%20Contract');
        $payload = $this->json($response);
        $names = array_column($payload['data'], 'name');

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('total', $payload);
        self::assertArrayHasKey('page', $payload);
        self::assertContains('PHPUnit Contract Active Event', $names);
        self::assertNotContains('PHPUnit Contract Draft Event', $names);
        self::assertNotContains('PHPUnit Contract Private Event', $names);
    }

    public function testPrivateEventSlugIsHiddenPubliclyButVisibleToStaffToken(): void
    {
        // E1.1: a Published+Private event without the right access code is hidden from
        // an anonymous caller entirely (not even reachable via the direct link).
        $publicResponse = $this->request('GET', '/api/v1/events/slug/' . $this->privateEvent['slug']);
        $publicPayload = $this->json($publicResponse);

        self::assertSame(404, $publicResponse->getStatusCode());
        self::assertFalse($publicPayload['success']);
        self::assertSame('not_found', $publicPayload['error']['code']);

        // Wrong code: still hidden.
        $wrongCodeResponse = $this->request('GET', '/api/v1/events/slug/' . $this->privateEvent['slug'] . '?accessCode=not-the-code');
        self::assertSame(404, $wrongCodeResponse->getStatusCode());

        // Correct code: the direct link now works for an anonymous caller, per the
        // "accessible via direct link, optionally with an access code" spec.
        $codeResponse = $this->request('GET', '/api/v1/events/slug/' . $this->privateEvent['slug'] . '?accessCode=phpunit-secret');
        $codePayload = $this->json($codeResponse);
        self::assertSame(200, $codeResponse->getStatusCode());
        self::assertTrue($codePayload['success']);
        self::assertSame($this->privateEvent['id'], $codePayload['data']['id']);

        $staffResponse = $this->request(
            'GET',
            '/api/v1/events/slug/' . $this->privateEvent['slug'],
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        $staffPayload = $this->json($staffResponse);

        self::assertSame(200, $staffResponse->getStatusCode());
        self::assertTrue($staffPayload['success']);
        self::assertSame($this->privateEvent['id'], $staffPayload['data']['id']);
    }

    /**
     * E3.2 (issue 12 §3): the pasted BZ organization token is credential-shaped and
     * must never reach a participant/anonymous reader of a published Event, even
     * though the rest of the Event payload (including bank details inside
     * paymentConfig) is already returned unfiltered on this same public route —
     * see routes.php's `$stripBzToken`. Staff (a manager, per $canManageEvent) still
     * sees it, so the organizer-facing settings tab keeps working.
     */
    public function testBzOrganizationTokenIsHiddenFromPublicButVisibleToStaff(): void
    {
        $this->events->update((string) $this->activeEvent['id'], [
            'bzIntegrationEnabled' => true,
            'bzOrganizationToken' => 'ORG-SECRET-TOKEN',
        ]);

        $publicById = $this->request('GET', '/api/v1/events/' . $this->activeEvent['id']);
        $publicByIdPayload = $this->json($publicById);
        self::assertSame(200, $publicById->getStatusCode());
        self::assertTrue($publicByIdPayload['data']['bzIntegrationEnabled']);
        self::assertNull($publicByIdPayload['data']['bzOrganizationToken']);

        $publicBySlug = $this->request('GET', '/api/v1/events/slug/' . $this->activeEvent['slug']);
        self::assertNull($this->json($publicBySlug)['data']['bzOrganizationToken']);

        $listResponse = $this->request('GET', '/api/v1/events?search=PHPUnit%20Contract');
        $listPayload = $this->json($listResponse);
        foreach ($listPayload['data'] as $row) {
            self::assertNull($row['bzOrganizationToken']);
        }

        $staffResponse = $this->request(
            'GET',
            '/api/v1/events/' . $this->activeEvent['id'],
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame('ORG-SECRET-TOKEN', $this->json($staffResponse)['data']['bzOrganizationToken']);
    }

    public function testAuthLoginSuccessAndFailureContracts(): void
    {
        $success = $this->request('POST', '/api/v1/auth/login', [
            'email' => $this->participant['email'],
            'password' => 'demo1234',
        ]);
        $successPayload = $this->json($success);

        self::assertSame(200, $success->getStatusCode());
        self::assertTrue($successPayload['success']);
        self::assertSame($this->participant['id'], $successPayload['data']['user']['id']);
        self::assertIsString($successPayload['data']['token']);

        $failure = $this->request('POST', '/api/v1/auth/login', [
            'email' => $this->participant['email'],
            'password' => 'wrong',
        ]);
        $failurePayload = $this->json($failure);

        self::assertSame(401, $failure->getStatusCode());
        self::assertFalse($failurePayload['success']);
        self::assertSame('invalid_credentials', $failurePayload['error']['code']);
    }

    public function testProtectedUsersEndpointRequiresTokenAndStaffRole(): void
    {
        $missingToken = $this->request('GET', '/api/v1/users');
        self::assertSame(401, $missingToken->getStatusCode());
        self::assertSame('unauthorized', $this->json($missingToken)['error']['code']);

        $wrongRole = $this->request(
            'GET',
            '/api/v1/users',
            [],
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        self::assertSame(403, $wrongRole->getStatusCode());
        self::assertSame('forbidden', $this->json($wrongRole)['error']['code']);

        $allowed = $this->request(
            'GET',
            '/api/v1/users?search=PHPUnit%20Contract',
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        $payload = $this->json($allowed);

        self::assertSame(200, $allowed->getStatusCode());
        self::assertArrayHasKey('data', $payload);
        self::assertArrayHasKey('total', $payload);
    }

    public function testValidationAndReferenceEndpointContracts(): void
    {
        $register = $this->request('POST', '/api/v1/auth/register', [
            'email' => 'missing-fields@example.test',
        ]);
        self::assertSame(422, $register->getStatusCode());
        self::assertSame('validation_error', $this->json($register)['error']['code']);

        $shopCategories = $this->request('GET', '/api/v1/shop/categories');
        self::assertSame(200, $shopCategories->getStatusCode());
        self::assertTrue($this->json($shopCategories)['success']);

        $pages = $this->request('GET', '/api/v1/info-pages');
        self::assertSame(200, $pages->getStatusCode());
        self::assertTrue($this->json($pages)['success']);
    }

    /**
     * E1.1 dropped the free-text `status` column entirely (see
     * 031_e11_event_lifecycle.sql), so there is no ENUM to patch in place any more — the
     * schema this test needs only exists once every migration has actually run. Calling
     * the real Migrator (idempotent, no ledger — see its docblock) is the only thing that
     * still makes sense here.
     */
    private function ensureEventStatusWorkflowSchema(): void
    {
        \ZmierzymyCzas\Database\Migrator::applyAll($this->pdo);
    }

    private function eventPayload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? $this->prefix);

        return array_replace_recursive([
            'name' => 'PHPUnit Contract Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->admin['id'],
            'organizerName' => 'PHPUnit Contract Admin',
            'status' => 'active',
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

    private function deleteContractEvents(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => $this->prefix . '-%']);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $id) {
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_payment_methods WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_merchandise WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_discount_codes WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_gallery_albums WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
    }
}

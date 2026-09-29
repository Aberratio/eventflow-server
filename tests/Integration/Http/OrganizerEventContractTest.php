<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

final class OrganizerEventContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private string $prefix;
    private array $organizerA;
    private array $organizerB;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->prefix = 'horg-' . bin2hex(random_bytes(4));

        $this->ensureEventStatusSchema();

        $this->organizerA = $this->users->create([
            'name' => 'Organizer A',
            'email' => $this->prefix . '-orgA@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            // New organizers start with event_limit=0 (an admin grants slots after
            // onboarding); these HTTP contract tests exercise real event creation,
            // so the fixture needs slots of its own instead of relying on the default.
            'eventLimit' => 5,
        ]);
        $this->organizerB = $this->users->create([
            'name' => 'Organizer B',
            'email' => $this->prefix . '-orgB@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            'eventLimit' => 5,
        ]);
        $this->admin = $this->users->create([
            'name' => 'Org Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slug');
        $stmt->execute(['slug' => $this->prefix . '-%']);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $id) {
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_discount_codes WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_payment_methods WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_merchandise WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_gallery_albums WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testOrganizerCanCreateEventForSelf(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-create']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $payload = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame($this->organizerA['id'], $payload['data']['organizerId']);
        // Organizer-created events start as draft
        self::assertSame('draft', $payload['data']['publicationStatus']);
    }

    public function testOrganizerCannotSetFeaturedFlag(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-featured', 'featured' => true]),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $payload = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        // featured flag is stripped for non-staff
        self::assertFalse((bool) $payload['data']['featured']);
    }

    public function testOrganizerCannotEditAnotherOrganizersEvent(): void
    {
        $event = $this->events->create($this->eventPayload([
            'slug' => $this->prefix . '-other',
            'organizerId' => $this->organizerA['id'],
            'organizerName' => 'Organizer A',
            'status' => 'draft',
        ]));

        $response = $this->request(
            'PATCH',
            '/api/v1/events/' . $event['id'],
            ['name' => 'Hacked Name'],
            $this->bearer($this->organizerB['id'], 'organizer', $this->organizerB['email'])
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->json($response)['error']['code']);
    }

    public function testOrganizerCanAccessOwnEventsList(): void
    {
        $response = $this->request(
            'GET',
            '/api/v1/organizers/' . $this->organizerA['id'] . '/events',
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
    }

    public function testOrganizerCannotAccessOtherOrganizersEventsList(): void
    {
        $response = $this->request(
            'GET',
            '/api/v1/organizers/' . $this->organizerA['id'] . '/events',
            [],
            $this->bearer($this->organizerB['id'], 'organizer', $this->organizerB['email'])
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->json($response)['error']['code']);
    }

    public function testAdminCanAccessAnyOrganizersEventsList(): void
    {
        $response = $this->request(
            'GET',
            '/api/v1/organizers/' . $this->organizerA['id'] . '/events',
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->json($response)['success']);
    }

    public function testModerationWorkflowViaHttpSubmitAndApprove(): void
    {
        // Organizer creates a draft event
        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-workflow']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(201, $createResp->getStatusCode());
        $eventId = $this->json($createResp)['data']['id'];

        // Organizer submits for review
        $submitResp = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/submit',
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(200, $submitResp->getStatusCode());
        self::assertSame('submitted', $this->json($submitResp)['data']['publicationStatus']);

        // Admin approves — E1.1: this lands directly on `published`, there is no
        // separate publish step any more.
        $approveResp = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/approve',
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $approveResp->getStatusCode());
        self::assertSame('published', $this->json($approveResp)['data']['publicationStatus']);
    }

    public function testOwnerCanCancelPublishedEventButNotDraft(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-cancel']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];

        $cancelDraftResp = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/cancel',
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(409, $cancelDraftResp->getStatusCode());

        $this->request('POST', '/api/v1/events/' . $eventId . '/submit', [], $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email']));
        $this->request('POST', '/api/v1/events/' . $eventId . '/approve', [], $this->bearer($this->admin['id'], 'admin', $this->admin['email']));

        $cancelResp = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/cancel',
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(200, $cancelResp->getStatusCode());
        self::assertSame('cancelled', $this->json($cancelResp)['data']['publicationStatus']);
    }

    public function testOwnerCanChangeVisibilityOfPublishedEventButNotDraft(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-visibility']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];

        // Only meaningful once Published — the underlying event is still draft here.
        $visibilityDraftResp = $this->request(
            'PATCH',
            '/api/v1/events/' . $eventId . '/visibility',
            ['visibility' => 'private'],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(409, $visibilityDraftResp->getStatusCode());

        $this->request('POST', '/api/v1/events/' . $eventId . '/submit', [], $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email']));
        $this->request('POST', '/api/v1/events/' . $eventId . '/approve', [], $this->bearer($this->admin['id'], 'admin', $this->admin['email']));

        $visibilityResp = $this->request(
            'PATCH',
            '/api/v1/events/' . $eventId . '/visibility',
            ['visibility' => 'private'],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(200, $visibilityResp->getStatusCode());
        self::assertSame('private', $this->json($visibilityResp)['data']['visibility']);
    }

    public function testAdminCanChangeVisibilityOfPublishedEvent(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-visibility-admin']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];
        $this->request('POST', '/api/v1/events/' . $eventId . '/submit', [], $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email']));
        $this->request('POST', '/api/v1/events/' . $eventId . '/approve', [], $this->bearer($this->admin['id'], 'admin', $this->admin['email']));

        $visibilityResp = $this->request(
            'PATCH',
            '/api/v1/events/' . $eventId . '/visibility',
            ['visibility' => 'private'],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $visibilityResp->getStatusCode());
        self::assertSame('private', $this->json($visibilityResp)['data']['visibility']);
    }

    public function testOtherOrganizerCannotChangeVisibility(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-visibility-other']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];
        $this->request('POST', '/api/v1/events/' . $eventId . '/submit', [], $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email']));
        $this->request('POST', '/api/v1/events/' . $eventId . '/approve', [], $this->bearer($this->admin['id'], 'admin', $this->admin['email']));

        $visibilityResp = $this->request(
            'PATCH',
            '/api/v1/events/' . $eventId . '/visibility',
            ['visibility' => 'private'],
            $this->bearer($this->organizerB['id'], 'organizer', $this->organizerB['email'])
        );
        self::assertSame(403, $visibilityResp->getStatusCode());
        self::assertSame('forbidden', $this->json($visibilityResp)['error']['code']);
    }

    public function testChangeVisibilityWithInvalidValueReturns422(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-vis-invalid']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];
        $this->request('POST', '/api/v1/events/' . $eventId . '/submit', [], $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email']));
        $this->request('POST', '/api/v1/events/' . $eventId . '/approve', [], $this->bearer($this->admin['id'], 'admin', $this->admin['email']));

        $visibilityResp = $this->request(
            'PATCH',
            '/api/v1/events/' . $eventId . '/visibility',
            ['visibility' => 'hidden'],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(422, $visibilityResp->getStatusCode());
        self::assertSame('validation_error', $this->json($visibilityResp)['error']['code']);
    }

    /**
     * E3.2 (issue 12 §3): enabling "Biuro Zawodów" without pasting an organization
     * token is rejected — the platform never creates a BZ organization itself, so
     * there is nothing to fall back to.
     */
    public function testEnablingBzIntegrationWithoutTokenReturns422(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-bz-no-token']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];

        $emptyTokenResp = $this->request(
            'PATCH',
            '/api/v1/events/' . $eventId,
            ['bzIntegrationEnabled' => true, 'bzOrganizationToken' => '   '],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(422, $emptyTokenResp->getStatusCode());
        self::assertSame('validation_error', $this->json($emptyTokenResp)['error']['code']);

        $missingTokenResp = $this->request(
            'PATCH',
            '/api/v1/events/' . $eventId,
            ['bzIntegrationEnabled' => true],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(422, $missingTokenResp->getStatusCode());
        self::assertSame('validation_error', $this->json($missingTokenResp)['error']['code']);
    }

    /**
     * E3.2: disabling never requires a token, and the token round-trips through the
     * real HTTP PATCH route (not just the repository) — the exact "generic PATCH
     * /events/{id}" wiring the ticket asks for.
     */
    public function testBzIntegrationTokenRoundTripsAndDisableNeedsNoToken(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-bz-roundtrip']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];

        $enableResp = $this->request(
            'PATCH',
            '/api/v1/events/' . $eventId,
            ['bzIntegrationEnabled' => true, 'bzOrganizationToken' => 'ORG-HTTP-1'],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(200, $enableResp->getStatusCode());
        $enabled = $this->json($enableResp)['data'];
        self::assertTrue($enabled['bzIntegrationEnabled']);
        self::assertSame('ORG-HTTP-1', $enabled['bzOrganizationToken']);

        $disableResp = $this->request(
            'PATCH',
            '/api/v1/events/' . $eventId,
            ['bzIntegrationEnabled' => false],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(200, $disableResp->getStatusCode());
        $disabled = $this->json($disableResp)['data'];
        self::assertFalse($disabled['bzIntegrationEnabled']);
        // Preserved, not wiped — re-enabling later shouldn't require re-pasting it.
        self::assertSame('ORG-HTTP-1', $disabled['bzOrganizationToken']);

        $reEnableResp = $this->request(
            'PATCH',
            '/api/v1/events/' . $eventId,
            ['bzIntegrationEnabled' => true],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(200, $reEnableResp->getStatusCode());
        self::assertTrue($this->json($reEnableResp)['data']['bzIntegrationEnabled']);
    }

    /**
     * E3.2: only the owning organizer (or admin) may write the BZ config — same
     * $canManageEvent gate as every other Event-scoped organizer mutation.
     */
    public function testOtherOrganizerCannotWriteBzIntegrationConfig(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-bz-forbidden']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];

        $response = $this->request(
            'PATCH',
            '/api/v1/events/' . $eventId,
            ['bzIntegrationEnabled' => true, 'bzOrganizationToken' => 'ORG-HACKED'],
            $this->bearer($this->organizerB['id'], 'organizer', $this->organizerB['email'])
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->json($response)['error']['code']);
    }

    /**
     * E5.6 (issue 16 §2): submitting with an available "pakiet organizatora"
     * credit succeeds and spends exactly one credit — never at Draft creation,
     * only at the Szkic -> Zgloszone transition itself.
     */
    public function testSubmitWithAvailableCreditSucceedsAndDecrementsCreditByOne(): void
    {
        $this->grantOrganizerPackageCredits($this->organizerA['id'], 2);

        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-credit-submit']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];
        // Creating (and freely editing) the Draft never touched the credit.
        self::assertSame(2, $this->organizerPackageCredits($this->organizerA['id']));

        $submitResp = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/submit',
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );

        self::assertSame(200, $submitResp->getStatusCode());
        self::assertSame('submitted', $this->json($submitResp)['data']['publicationStatus']);
        self::assertSame(1, $this->organizerPackageCredits($this->organizerA['id']));
    }

    /**
     * E5.6: with zero available credit, the submit is rejected with a clear 4xx
     * business error, the event stays in Draft, and the (already-zero) credit
     * counter is left untouched — no partial/desynced state.
     */
    public function testSubmitWithNoAvailableCreditIsRejectedAndEventStaysDraft(): void
    {
        // organizerA starts with 0 organizer_package_credits (default) — no grant.
        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-credit-none']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];

        $submitResp = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/submit',
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );

        self::assertSame(409, $submitResp->getStatusCode());
        self::assertSame('event_submit_no_organizer_credit', $this->json($submitResp)['error']['code']);
        self::assertSame(0, $this->organizerPackageCredits($this->organizerA['id']));

        $fetched = $this->json($this->request(
            'GET',
            '/api/v1/events/' . $eventId,
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        ))['data'];
        self::assertSame('draft', $fetched['publicationStatus']);
    }

    /**
     * E5.6: rejection by a moderator refunds exactly one credit back to the
     * organizer — the platform decided the event isn't good enough, so the
     * organizer shouldn't be out a credit for trying.
     */
    public function testRejectingSubmittedEventRefundsCreditByOne(): void
    {
        $this->grantOrganizerPackageCredits($this->organizerA['id'], 1);

        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-credit-refund']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];

        $this->request('POST', '/api/v1/events/' . $eventId . '/submit', [], $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email']));
        self::assertSame(0, $this->organizerPackageCredits($this->organizerA['id']));

        $rejectResp = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/reject',
            ['reason' => 'Brakuje regulaminu'],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );

        self::assertSame(200, $rejectResp->getStatusCode());
        self::assertSame('draft', $this->json($rejectResp)['data']['publicationStatus']);
        self::assertSame(1, $this->organizerPackageCredits($this->organizerA['id']));
    }

    /**
     * E5.6: approval is a one-shot consumption model — the credit was already
     * spent at submit(), and approve() (Submitted -> Published) must not touch
     * it again in either direction.
     */
    public function testApprovingSubmittedEventDoesNotTouchCredit(): void
    {
        $this->grantOrganizerPackageCredits($this->organizerA['id'], 1);

        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-credit-approve']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];

        $this->request('POST', '/api/v1/events/' . $eventId . '/submit', [], $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email']));
        self::assertSame(0, $this->organizerPackageCredits($this->organizerA['id']));

        $approveResp = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/approve',
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );

        self::assertSame(200, $approveResp->getStatusCode());
        self::assertSame('published', $this->json($approveResp)['data']['publicationStatus']);
        // Still 0 — approve() neither spends nor refunds, consumption already
        // happened at submit() and is one-shot.
        self::assertSame(0, $this->organizerPackageCredits($this->organizerA['id']));
    }

    public function testAdminCanRejectEventWithReason(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-reject']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];

        $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/submit',
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );

        $rejectResp = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/reject',
            ['reason' => 'Brakuje regulaminu'],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        $payload = $this->json($rejectResp);

        self::assertSame(200, $rejectResp->getStatusCode());
        self::assertSame('draft', $payload['data']['publicationStatus']);
        self::assertSame('Brakuje regulaminu', $payload['data']['rejectionReason']);
    }

    public function testRejectWithoutReasonReturns422(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-reject-empty']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];

        $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/submit',
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );

        $rejectResp = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/reject',
            ['reason' => ''],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );

        self::assertSame(422, $rejectResp->getStatusCode());
        self::assertSame('validation_error', $this->json($rejectResp)['error']['code']);
    }

    /**
     * E1.1 permission matrix: a soft-deleted event (`deletedAt` set) becomes
     * invisible to everyone except superadmin — even its own owning organizer
     * and a plain admin lose read access (`canViewEvent`/`canManageEvent` in
     * routes.php both special-case `deletedAt !== null`). Nothing in the test
     * suite previously exercised this branch at all.
     */
    public function testDeletedEventIsInvisibleToEveryoneExceptSuperadmin(): void
    {
        $superadmin = $this->users->create([
            'name' => 'Org Superadmin',
            'email' => $this->prefix . '-superadmin@example.test',
            'password' => 'demo1234',
            'role' => 'superadmin',
        ]);

        $createResp = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $this->prefix . '-deleted']),
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $eventId = $this->json($createResp)['data']['id'];

        $deleteResp = $this->request(
            'DELETE',
            '/api/v1/events/' . $eventId,
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(200, $deleteResp->getStatusCode());

        // Owner: could manage it before deletion, cannot even see it exists now
        // (the read endpoint reports a deleted event the same as a nonexistent
        // one, deliberately not leaking that it once existed).
        $ownerResp = $this->request(
            'GET',
            '/api/v1/events/' . $eventId,
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(404, $ownerResp->getStatusCode());
        self::assertSame('not_found', $this->json($ownerResp)['error']['code']);

        // Plain admin: also locked out, deletion is superadmin-only territory.
        $adminResp = $this->request(
            'GET',
            '/api/v1/events/' . $eventId,
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(404, $adminResp->getStatusCode());

        // Superadmin: still sees it, deletedAt included in the payload.
        $superResp = $this->request(
            'GET',
            '/api/v1/events/' . $eventId,
            [],
            $this->bearer($superadmin['id'], 'superadmin', $superadmin['email'])
        );
        $superPayload = $this->json($superResp);
        self::assertSame(200, $superResp->getStatusCode());
        self::assertSame($eventId, $superPayload['data']['id']);
        self::assertNotNull($superPayload['data']['deletedAt']);

        // The owner also loses manage rights: a second delete attempt is forbidden,
        // not the 409 "already gone" business error a still-visible event would get.
        $secondDeleteResp = $this->request(
            'DELETE',
            '/api/v1/events/' . $eventId,
            [],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(403, $secondDeleteResp->getStatusCode());
    }

    /**
     * E1.1 dropped the free-text `status` column entirely (see
     * 031_e11_event_lifecycle.sql), so there is no ENUM to patch in place any more — the
     * schema this test needs only exists once every migration has actually run. Calling
     * the real Migrator (idempotent, no ledger — see its docblock) is the only thing that
     * still makes sense here.
     */
    private function ensureEventStatusSchema(): void
    {
        \ZmierzymyCzas\Database\Migrator::applyAll($this->pdo);
    }

    /**
     * E5.6: there is no public write API for organizer_package_credits other
     * than a real shop purchase (OrderRepository::create(), covered separately
     * in ShopOrderContractTest) — tests here that need a starting credit balance
     * set it directly, the same way this suite already sets `eventLimit` via
     * UserRepository::create()'s options for a different axis.
     */
    private function grantOrganizerPackageCredits(string $userId, int $count): void
    {
        $this->pdo->prepare('UPDATE users SET organizer_package_credits = organizer_package_credits + :count WHERE id = :id')
            ->execute(['count' => $count, 'id' => $userId]);
    }

    private function organizerPackageCredits(string $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT organizer_package_credits FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    private function eventPayload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? $this->prefix . '-default');

        return array_replace_recursive([
            'name' => 'PHPUnit Organizer Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizerA['id'],
            'organizerName' => 'Organizer A',
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

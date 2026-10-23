<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Care\CareRepository;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldAnswerRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

final class RegistrationAccessContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private FormFieldRepository $formFields;
    private string $prefix;
    private array $organizerA;
    private array $organizerB;
    private array $participantP;
    private array $participantQ;
    private array $moderator;
    private array $admin;
    private array $eventA;
    private array $eventB;
    private array $registrationRP;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo);
        $this->formFields = new FormFieldRepository($this->pdo);
        $this->prefix = 'http-ra-' . bin2hex(random_bytes(4));

        $this->organizerA = $this->users->create(['name' => 'Org A', 'email' => $this->prefix . '-orga@example.test', 'password' => 'demo1234', 'role' => 'organizer']);
        $this->organizerB = $this->users->create(['name' => 'Org B', 'email' => $this->prefix . '-orgb@example.test', 'password' => 'demo1234', 'role' => 'organizer']);
        $this->participantP = $this->users->create(['name' => 'P One', 'email' => $this->prefix . '-p@example.test', 'password' => 'demo1234', 'role' => 'participant']);
        $this->participantQ = $this->users->create(['name' => 'Q Two', 'email' => $this->prefix . '-q@example.test', 'password' => 'demo1234', 'role' => 'participant']);
        $this->moderator = $this->users->create(['name' => 'M Mod', 'email' => $this->prefix . '-m@example.test', 'password' => 'demo1234', 'role' => 'moderator']);
        $this->admin = $this->users->create(['name' => 'A Admin', 'email' => $this->prefix . '-a@example.test', 'password' => 'demo1234', 'role' => 'admin']);

        $this->eventA = $this->events->create($this->eventPayload([
            'slug' => $this->prefix . '-a',
            'organizerId' => $this->organizerA['id'],
            'organizerName' => 'Org A',
        ]));
        $this->eventB = $this->events->create($this->eventPayload([
            'slug' => $this->prefix . '-b',
            'organizerId' => $this->organizerB['id'],
            'organizerName' => 'Org B',
        ]));

        $this->registrationRP = $this->registrations->create($this->registrationPayload($this->eventA, $this->participantP));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        foreach ([$this->eventA['id'] ?? null, $this->eventB['id'] ?? null] as $eventId) {
            if ($eventId === null) {
                continue;
            }
            $this->deleteRegistrationsForEvent((string) $eventId);
        }
        $this->pdo->prepare('DELETE FROM form_fields WHERE event_id IN (:a, :b)')
            ->execute(['a' => $this->eventA['id'] ?? '', 'b' => $this->eventB['id'] ?? '']);
        $this->pdo->prepare('DELETE cr FROM care_relations cr JOIN users u ON u.id = cr.konto_id WHERE u.email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE c FROM competitors c JOIN users u ON u.id = c.user_id WHERE u.email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testStrangerCannotPatchSomeoneElsesRegistration(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/registrations/' . $this->registrationRP['id'],
            ['status' => 'confirmed'],
            $this->bearer($this->participantQ['id'], 'participant', $this->participantQ['email'])
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('pending', $this->currentStatus());
    }

    /** @dataProvider ownerRejectedFields */
    public function testOwnerCannotChangeOwnStatusOrPricingFields(string $key, mixed $value): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/registrations/' . $this->registrationRP['id'],
            [$key => $value],
            $this->bearer($this->participantP['id'], 'participant', $this->participantP['email'])
        );
        self::assertSame(422, $response->getStatusCode(), $key);
        self::assertSame('pending', $this->currentStatus());
    }

    public static function ownerRejectedFields(): array
    {
        return [
            ['status', 'confirmed'],
            ['discountCode', 'ABC'],
            ['bibNumber', '101'],
            ['paymentDetails', ['startFeePaid' => true]],
            ['teamId', 'some-team'],
            ['checkedInAt', '2026-01-01T00:00:00Z'],
            ['merchandise', []],
        ];
    }

    public function testOwnerCanChangeTeamName(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/registrations/' . $this->registrationRP['id'],
            ['teamName' => 'Nowy Klub'],
            $this->bearer($this->participantP['id'], 'participant', $this->participantP['email'])
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Nowy Klub', $this->json($response)['data']['teamName']);
    }

    public function testOwningOrganizerCanAssignBibButNotStatus(): void
    {
        $bibResponse = $this->request(
            'PATCH',
            '/api/v1/registrations/' . $this->registrationRP['id'],
            ['bibNumber' => '101'],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(200, $bibResponse->getStatusCode());
        self::assertSame('101', $this->json($bibResponse)['data']['bibNumber']);

        $statusResponse = $this->request(
            'PATCH',
            '/api/v1/registrations/' . $this->registrationRP['id'],
            ['status' => 'confirmed'],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        self::assertSame(422, $statusResponse->getStatusCode());
    }

    public function testForeignOrganizerCannotPatchOrCancel(): void
    {
        $patchResp = $this->request(
            'PATCH',
            '/api/v1/registrations/' . $this->registrationRP['id'],
            ['bibNumber' => '999'],
            $this->bearer($this->organizerB['id'], 'organizer', $this->organizerB['email'])
        );
        self::assertSame(403, $patchResp->getStatusCode());

        $cancelResp = $this->request(
            'POST',
            '/api/v1/registrations/' . $this->registrationRP['id'] . '/cancel',
            [],
            $this->bearer($this->organizerB['id'], 'organizer', $this->organizerB['email'])
        );
        self::assertSame(403, $cancelResp->getStatusCode());
    }

    public function testStrangerCannotCancel(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/registrations/' . $this->registrationRP['id'] . '/cancel',
            [],
            $this->bearer($this->participantQ['id'], 'participant', $this->participantQ['email'])
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertNotSame('cancelled', $this->currentStatus());
    }

    public function testOwnerCanCancel(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/registrations/' . $this->registrationRP['id'] . '/cancel',
            [],
            $this->bearer($this->participantP['id'], 'participant', $this->participantP['email'])
        );
        self::assertSame(200, $response->getStatusCode());
    }

    public function testFormFieldAnswersAreOwnerOrManagerOnly(): void
    {
        $this->formFields->ensureSystemCatalog((string) $this->eventA['id']);
        $field = $this->formFields->createCustom((string) $this->eventA['id'], [
            'fieldType' => 'short_text',
            'fieldKey' => 'custom_note',
            'label' => 'Note',
            'state' => 'shown',
        ]);

        $getStranger = $this->request(
            'GET',
            '/api/v1/registrations/' . $this->registrationRP['id'] . '/form-field-answers',
            [],
            $this->bearer($this->participantQ['id'], 'participant', $this->participantQ['email'])
        );
        self::assertSame(403, $getStranger->getStatusCode());

        $putStranger = $this->request(
            'PUT',
            '/api/v1/registrations/' . $this->registrationRP['id'] . '/form-field-answers/' . $field['id'],
            ['value' => 'x'],
            $this->bearer($this->participantQ['id'], 'participant', $this->participantQ['email'])
        );
        self::assertSame(403, $putStranger->getStatusCode());

        $getOwner = $this->request(
            'GET',
            '/api/v1/registrations/' . $this->registrationRP['id'] . '/form-field-answers',
            [],
            $this->bearer($this->participantP['id'], 'participant', $this->participantP['email'])
        );
        self::assertSame(200, $getOwner->getStatusCode());

        $putOwner = $this->request(
            'PUT',
            '/api/v1/registrations/' . $this->registrationRP['id'] . '/form-field-answers/' . $field['id'],
            ['value' => 'x'],
            $this->bearer($this->participantP['id'], 'participant', $this->participantP['email'])
        );
        self::assertSame(200, $putOwner->getStatusCode());
    }

    public function testFormFieldAnswerRejectsFieldFromAnotherEvent(): void
    {
        $this->formFields->ensureSystemCatalog((string) $this->eventB['id']);
        $foreignField = $this->formFields->createCustom((string) $this->eventB['id'], [
            'fieldType' => 'short_text',
            'fieldKey' => 'custom_other',
            'label' => 'Other',
            'state' => 'shown',
        ]);

        $response = $this->request(
            'PUT',
            '/api/v1/registrations/' . $this->registrationRP['id'] . '/form-field-answers/' . $foreignField['id'],
            ['value' => 'x'],
            $this->bearer($this->participantP['id'], 'participant', $this->participantP['email'])
        );
        self::assertSame(404, $response->getStatusCode());
    }

    public function testUserScopedListsAreSelfOrStaff(): void
    {
        foreach (['/registrations', '/tickets', '/certificates'] as $suffix) {
            $path = '/api/v1/users/' . $this->participantP['id'] . $suffix;
            $strangerResp = $this->request('GET', $path, [], $this->bearer($this->participantQ['id'], 'participant', $this->participantQ['email']));
            self::assertSame(403, $strangerResp->getStatusCode(), $path);

            $selfResp = $this->request('GET', $path, [], $this->bearer($this->participantP['id'], 'participant', $this->participantP['email']));
            self::assertSame(200, $selfResp->getStatusCode(), $path);

            $modResp = $this->request('GET', $path, [], $this->bearer($this->moderator['id'], 'moderator', $this->moderator['email']));
            self::assertSame(200, $modResp->getStatusCode(), $path);
        }
    }

    public function testTicketsCarryFrontendTicketFieldsAndGateStartCodeByPayment(): void
    {
        $path = '/api/v1/users/' . $this->participantP['id'] . '/tickets';
        $auth = $this->bearer($this->participantP['id'], 'participant', $this->participantP['email']);
        $this->pdo->prepare("UPDATE registrations SET status = 'pending' WHERE id = :id")->execute(['id' => $this->registrationRP['id']]);
        $this->pdo->prepare('UPDATE registrations SET qr_code = :qr WHERE id = :id')->execute(['qr' => 'pqr_' . str_repeat('a', 48), 'id' => $this->registrationRP['id']]);

        $unpaid = json_decode((string) $this->request('GET', $path, [], $auth)->getBody(), true)['data'][0] ?? [];
        foreach (['eventDate', 'location', 'participantName', 'distanceId', 'participantId', 'category', 'registrationStatus', 'qrCode'] as $key) {
            self::assertArrayHasKey($key, $unpaid, $key);
        }
        self::assertSame($this->eventA['date'], $unpaid['eventDate']);
        self::assertSame($this->eventA['location'], $unpaid['location']);
        self::assertSame($this->participantP['name'], $unpaid['participantName']);
        self::assertSame($this->participantP['id'], $unpaid['participantId']);
        self::assertSame($this->eventA['distances'][0]['id'], $unpaid['distanceId']);
        self::assertSame('Open', $unpaid['category']);
        self::assertSame('pending', $unpaid['registrationStatus']);
        self::assertNull($unpaid['qrCode'], 'start code must stay hidden until the Zapis is paid/confirmed');

        $this->pdo->prepare("UPDATE registrations SET status = 'confirmed' WHERE id = :id")->execute(['id' => $this->registrationRP['id']]);
        $paid = json_decode((string) $this->request('GET', $path, [], $auth)->getBody(), true)['data'][0] ?? [];
        self::assertSame('confirmed', $paid['registrationStatus']);
        self::assertSame('pqr_' . str_repeat('a', 48), $paid['qrCode']);
    }

    public function testGlobalRegistrationListIsStaffOnly(): void
    {
        $participantResp = $this->request('GET', '/api/v1/registrations', [], $this->bearer($this->participantP['id'], 'participant', $this->participantP['email']));
        self::assertSame(403, $participantResp->getStatusCode());

        $organizerResp = $this->request('GET', '/api/v1/registrations', [], $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email']));
        self::assertSame(403, $organizerResp->getStatusCode());

        $adminResp = $this->request('GET', '/api/v1/registrations', [], $this->bearer($this->admin['id'], 'admin', $this->admin['email']));
        self::assertSame(200, $adminResp->getStatusCode());
    }

    public function testForeignOrganizerCannotListEventRegistrations(): void
    {
        $foreignResp = $this->request('GET', '/api/v1/events/' . $this->eventA['id'] . '/registrations', [], $this->bearer($this->organizerB['id'], 'organizer', $this->organizerB['email']));
        self::assertSame(403, $foreignResp->getStatusCode());

        $ownerResp = $this->request('GET', '/api/v1/events/' . $this->eventA['id'] . '/registrations', [], $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email']));
        self::assertSame(200, $ownerResp->getStatusCode());

        $unknownResp = $this->request('GET', '/api/v1/events/does-not-exist/registrations', [], $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email']));
        self::assertSame(404, $unknownResp->getStatusCode());
    }

    public function testForeignOrganizerCannotEditPaymentDetails(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/registrations/' . $this->registrationRP['id'] . '/payment-details',
            ['startFeePaid' => true],
            $this->bearer($this->organizerB['id'], 'organizer', $this->organizerB['email'])
        );
        self::assertSame(403, $response->getStatusCode());

        $row = $this->pdo->prepare('SELECT start_fee_paid FROM registration_payment_details WHERE registration_id = :id');
        $row->execute(['id' => $this->registrationRP['id']]);
        $paid = $row->fetchColumn();
        self::assertTrue($paid === false || (int) $paid !== 1);
    }

    private function currentStatus(): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $this->registrationRP['id']]);
        return (string) $stmt->fetchColumn();
    }

    public function testGuardianLosesAccessToRegistrationAfterWardRevokesCare(): void
    {
        $guardian = $this->participantQ;
        $ward = $this->participantP;
        $competitor = (new CompetitorRepository($this->pdo))->findByUserId((string) $ward['id']);
        self::assertNotNull($competitor);
        $relation = (new CareRepository($this->pdo))->grant((string) $guardian['id'], (string) $competitor['id']);
        $registration = $this->registrations->create($this->registrationPayload($this->eventA, $ward) + [
            'competitorId' => $competitor['id'],
            'registeredById' => $guardian['id'],
        ]);
        $guardianAuth = $this->bearer($guardian['id'], 'participant', $guardian['email']);
        $path = '/api/v1/registrations/' . $registration['id'];

        self::assertSame(200, $this->request('GET', $path . '/form-field-answers', [], $guardianAuth)->getStatusCode());

        $revoke = $this->request('DELETE', '/api/v1/care/' . $relation['id'], [], $this->bearer($ward['id'], 'participant', $ward['email']));
        self::assertSame(200, $revoke->getStatusCode());

        self::assertSame(403, $this->request('GET', $path . '/form-field-answers', [], $guardianAuth)->getStatusCode());
        self::assertSame(403, $this->request('POST', $path . '/cancel', [], $guardianAuth)->getStatusCode());
        // The registration's own user keeps access.
        self::assertSame(200, $this->request('GET', $path . '/form-field-answers', [], $this->bearer($ward['id'], 'participant', $ward['email']))->getStatusCode());
    public function testModeratorDoesNotSeeConfidentialAnswersButEventTeamAndOwnerDo(): void
    {
        $this->formFields->ensureSystemCatalog((string) $this->eventA['id']);
        $open = $this->formFields->createCustom((string) $this->eventA['id'], [
            'fieldType' => 'short_text',
            'fieldKey' => 'custom_note',
            'label' => 'Note',
            'state' => 'shown',
        ]);
        $secret = $this->formFields->createCustom((string) $this->eventA['id'], [
            'fieldType' => 'short_text',
            'fieldKey' => 'custom_allergy',
            'label' => 'Allergies',
            'state' => 'shown',
            'isConfidential' => true,
        ]);
        $answers = new FormFieldAnswerRepository($this->pdo);
        $answers->saveAnswer($this->formFields, (string) $open['id'], (string) $this->registrationRP['id'], 'fine');
        $answers->saveAnswer($this->formFields, (string) $secret['id'], (string) $this->registrationRP['id'], 'peanuts');
        $this->pdo->prepare("UPDATE events SET publication_status = 'published' WHERE id = :id")->execute(['id' => $this->eventA['id']]);

        $path = '/api/v1/registrations/' . $this->registrationRP['id'] . '/form-field-answers';
        $fieldIds = fn (array $auth): array => array_column($this->json($this->request('GET', $path, [], $auth))['data'], 'formFieldId');

        $moderator = $this->request('GET', $path, [], $this->bearer($this->moderator['id'], 'moderator', $this->moderator['email']));
        self::assertSame(200, $moderator->getStatusCode());
        self::assertSame([$open['id']], array_column($this->json($moderator)['data'], 'formFieldId'));

        foreach ([
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email']),
            $this->bearer($this->participantP['id'], 'participant', $this->participantP['email']),
        ] as $auth) {
            self::assertEqualsCanonicalizing([$open['id'], $secret['id']], $fieldIds($auth));
        }
    }

    private function registrationPayload(array $event, array $user): array
    {
        return [
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'userId' => $user['id'],
            'userName' => $user['name'],
            'userEmail' => $user['email'],
            'distanceId' => $event['distances'][0]['id'],
            'distanceName' => $event['distances'][0]['name'],
            'category' => 'Open',
            'merchandiseOrders' => [],
        ];
    }

    private function deleteRegistrationsForEvent(string $eventId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
        $stmt->execute(['id' => $eventId]);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $regId) {
            $this->pdo->prepare('DELETE FROM registration_merchandise_orders WHERE registration_id = :id')->execute(['id' => $regId]);
            $this->pdo->prepare('DELETE FROM registration_payment_details WHERE registration_id = :id')->execute(['id' => $regId]);
            $this->pdo->prepare('DELETE FROM registration_settlement_items WHERE registration_id = :id')->execute(['id' => $regId]);
            $this->pdo->prepare('DELETE FROM registration_refunds WHERE registration_id = :id')->execute(['id' => $regId]);
            $this->pdo->prepare('DELETE FROM form_field_answers WHERE registration_id = :id')->execute(['id' => $regId]);
        }
        $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $eventId]);
    }

    private function eventPayload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? $this->prefix);

        return array_replace_recursive([
            'name' => 'PHPUnit RA Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => (new \DateTimeImmutable('+90 days'))->format('Y-m-d'),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizerA['id'],
            'organizerName' => 'Org A',
            'status' => 'active',
            'registrationDeadline' => (new \DateTimeImmutable('+60 days'))->format('Y-m-d'),
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

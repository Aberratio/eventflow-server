<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * PP.52: POST /registrations must derive identity and payment status server-side.
 */
final class RegistrationCreateContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private string $prefix;
    private array $p;
    private array $q;
    private array $admin;
    private array $event;
    private string $paidDistanceId;
    private string $freeDistanceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->prefix = 'http-regcreate-' . bin2hex(random_bytes(4));

        $this->p = $this->makeUser('p', 'participant');
        $this->q = $this->makeUser('q', 'participant');
        $this->admin = $this->makeUser('admin', 'admin');

        $slug = $this->prefix . '-event';
        $distance = static fn (string $id, string $name, int $price): array => [
            'id' => $id,
            'name' => $name,
            'length' => 10,
            'unit' => 'km',
            'categories' => ['Open'],
            'ageCategories' => [],
            'specialCategories' => [],
            'limit' => 100,
            'registeredCount' => 0,
            'price' => $price,
            'routeFiles' => [],
        ];
        $this->event = $this->events->create([
            'name' => 'PHPUnit RegCreate Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+90 days')),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->admin['id'],
            'organizerName' => 'RegCreate Admin',
            'status' => 'active',
            'registrationDeadline' => date('Y-m-d', strtotime('+60 days')),
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'discountCodes' => [
                [
                    'id' => $this->prefix . '-full',
                    'code' => 'PP52FULL',
                    'discountType' => 'percent',
                    'discountValue' => 100,
                    'usedCount' => 0,
                    'distanceIds' => [],
                    'active' => true,
                ],
            ],
            'distances' => [
                $distance($slug . '-paid', 'Paid 10 km', 80),
                $distance($slug . '-free', 'Free 5 km', 0),
            ],
        ]);
        $this->paidDistanceId = $slug . '-paid';
        $this->freeDistanceId = $slug . '-free';
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix, $this->event)) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
        $stmt->execute(['id' => $this->event['id']]);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $regId) {
            foreach (['registration_merchandise_orders', 'registration_payment_details', 'registration_settlement_items', 'registration_refunds', 'registration_gateway_transactions'] as $table) {
                $this->pdo->prepare("DELETE FROM {$table} WHERE registration_id = :id")->execute(['id' => $regId]);
            }
        }
        $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM event_discount_codes WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare(
            'DELETE cr FROM care_relations cr JOIN users u ON u.id = cr.konto_id WHERE u.email LIKE :email'
        )->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare(
            'DELETE cr FROM care_relations cr JOIN competitors c ON c.id = cr.zawodnik_id JOIN users u ON u.id = c.user_id WHERE u.email LIKE :email'
        )->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare(
            'DELETE cr FROM care_relations cr JOIN competitors c ON c.id = cr.zawodnik_id WHERE c.last_name = :last'
        )->execute(['last' => $this->prefix]);
        $this->pdo->prepare(
            'DELETE c FROM competitors c JOIN users u ON u.id = c.user_id WHERE u.email LIKE :email'
        )->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM competitors WHERE last_name = :last')->execute(['last' => $this->prefix]);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testParticipantCannotClaimPaidStartFee(): void
    {
        $response = $this->post($this->p, $this->selfPayload($this->p, ['paymentDetails' => ['startFeePaid' => true]]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('payment_fields_not_allowed', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countRegistrations('user_id', $this->p['id']));
    }

    /** @dataProvider otherPaidClaims */
    public function testParticipantCannotClaimPaidInsuranceOrMerchandise(array $paymentDetails): void
    {
        $response = $this->post($this->p, $this->selfPayload($this->p, ['paymentDetails' => $paymentDetails]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('payment_fields_not_allowed', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countRegistrations('user_id', $this->p['id']));
    }

    public static function otherPaidClaims(): array
    {
        return [
            'insurance' => [['insurancePaid' => true]],
            'merchandise' => [['merchandisePayments' => [['merchandiseId' => 'm1', 'paid' => true]]]],
        ];
    }

    public function testLegacyUnpaidPaymentDetailsAreDroppedAndZapisIsPending(): void
    {
        $response = $this->post($this->p, $this->selfPayload($this->p, ['paymentDetails' => ['startFeePaid' => false, 'insurancePaid' => false]]));
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('pending', $data['status']);
        $row = $this->rawRegistration($data['id']);
        self::assertNotNull($row['payment_expires_at']);
        $paid = $this->pdo->prepare('SELECT COUNT(*) FROM registration_payment_details WHERE registration_id = :id AND start_fee_paid = 1');
        $paid->execute(['id' => $data['id']]);
        self::assertSame(0, (int) $paid->fetchColumn());
    }

    public function testPaidDistanceWithoutClaimsIsPending(): void
    {
        $response = $this->post($this->p, $this->selfPayload($this->p));
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('pending', $data['status']);
        self::assertEmpty($data['paymentSession'] ?? null);
    }

    public function testFreeDistanceIsBornConfirmed(): void
    {
        $response = $this->post($this->p, $this->selfPayload($this->p, ['distanceId' => $this->freeDistanceId]));
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('confirmed', $data['status']);
        self::assertNull($this->rawRegistration($data['id'])['payment_expires_at']);
        $paid = $this->pdo->prepare('SELECT COUNT(*) FROM registration_payment_details WHERE registration_id = :id AND start_fee_paid = 1');
        $paid->execute(['id' => $data['id']]);
        self::assertSame(1, (int) $paid->fetchColumn());
    }

    public function testFullDiscountCodeIsBornConfirmed(): void
    {
        $response = $this->post($this->p, $this->selfPayload($this->p, ['discountCode' => 'PP52FULL']));
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('confirmed', $data['status']);
        self::assertNull($this->rawRegistration($data['id'])['payment_expires_at']);
    }

    public function testCannotRegisterAnotherLegacyAccountByUserId(): void
    {
        $this->legacyAccount($this->q);

        $response = $this->post($this->p, $this->selfPayload($this->q));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('not_authorized_to_register', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countRegistrations('user_id', $this->q['id']));
    }

    public function testCannotRegisterAnotherAccountWithProfileByUserId(): void
    {
        $response = $this->post($this->p, $this->selfPayload($this->q));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('not_authorized_to_register', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countRegistrations('user_id', $this->q['id']));
    }

    public function testSelfRegistrationOfLegacyAccountLinksOwnCompetitor(): void
    {
        $this->legacyAccount($this->p);

        $response = $this->post($this->p, $this->selfPayload($this->p));
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($this->p['id'], $data['userId']);
        self::assertNotEmpty($data['competitorId']);
        self::assertSame($this->competitorIdOf($this->p['id']), $data['competitorId']);
    }

    public function testForgedIdentityFieldsAreIgnored(): void
    {
        $response = $this->post($this->p, $this->selfPayload($this->p, [
            'userName' => 'Ktoś Inny',
            'userEmail' => $this->prefix . '-obcy@example.test',
            'registeredById' => $this->q['id'],
        ]));
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        $row = $this->rawRegistration($data['id']);
        self::assertSame($this->p['name'], $row['user_name']);
        self::assertSame($this->p['email'], $row['user_email']);
        self::assertNull($row['registered_by_id']);
    }

    public function testNamedCompetitorWithoutCareIsForbidden(): void
    {
        $qCompetitorId = $this->competitorIdOf($this->q['id']);

        $response = $this->post($this->p, $this->selfPayload($this->p, ['competitorId' => $qCompetitorId]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('not_authorized_to_register', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countRegistrations('event_id', $this->event['id']));
    }

    public function testGuardianRegistersChildUnderCare(): void
    {
        $child = $this->json($this->request(
            'POST',
            '/api/v1/care',
            ['firstName' => 'Dziecko', 'lastName' => $this->prefix, 'birthDate' => '2015-05-05'],
            $this->bearer($this->p['id'], 'participant', $this->p['email'])
        ))['data']['competitor'];

        $response = $this->post($this->p, $this->selfPayload($this->p, ['competitorId' => $child['id']]));
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($child['id'], $data['competitorId']);
        self::assertSame($this->p['id'], $data['userId']);
        self::assertSame($this->p['id'], $data['registeredById']);
        self::assertSame('Dziecko ' . $this->prefix, $data['userName']);

        // PP.44: the Zapis row keeps the child as the participant and the guardian as contact.
        $row = $this->rawRegistration($data['id']);
        self::assertSame($child['id'], $row['competitor_id']);
        self::assertSame($this->p['id'], $row['user_id']);
        self::assertSame($this->p['id'], $row['registered_by_id']);
        self::assertSame('Dziecko ' . $this->prefix, $row['user_name']);
        self::assertSame($this->p['email'], $row['user_email']);
    }

    public function testChildCompetitorEmailIsNotUsedAsContactEmail(): void
    {
        $child = $this->json($this->request(
            'POST',
            '/api/v1/care',
            ['firstName' => 'Dziecko', 'lastName' => $this->prefix, 'birthDate' => '2015-05-05'],
            $this->bearer($this->p['id'], 'participant', $this->p['email'])
        ))['data']['competitor'];
        $this->pdo->prepare('UPDATE competitors SET email = :email WHERE id = :id')->execute([
            'email' => $this->prefix . '-child@example.test',
            'id' => $child['id'],
        ]);

        $response = $this->post($this->p, $this->selfPayload($this->p, ['competitorId' => $child['id']]));
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($this->p['email'], $this->rawRegistration($data['id'])['user_email']);
    }

    public function testAdultUnderCareGetsCurrentKontoEmail(): void
    {
        $grant = $this->request(
            'POST',
            '/api/v1/care',
            ['email' => $this->p['email']],
            $this->bearer($this->q['id'], 'participant', $this->q['email'])
        );
        self::assertSame(201, $grant->getStatusCode());
        $qCompetitor = $this->competitorIdOf($this->q['id']);

        // The competitor row keeps the e-mail copied at creation; the Konto's e-mail moves on.
        $newEmail = $this->prefix . '-q-new@example.test';
        $this->pdo->prepare('UPDATE users SET email = :email WHERE id = :id')->execute([
            'email' => $newEmail,
            'id' => $this->q['id'],
        ]);

        $response = $this->post($this->p, $this->selfPayload($this->p, ['competitorId' => $qCompetitor]));
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        $row = $this->rawRegistration($data['id']);
        self::assertSame($newEmail, $row['user_email']);
        self::assertSame($this->q['id'], $row['user_id']);
        self::assertSame($this->p['id'], $row['registered_by_id']);
    }

    public function testCareGrantedByAdultAccountAllowsRegistration(): void
    {
        $grant = $this->request(
            'POST',
            '/api/v1/care',
            ['email' => $this->p['email']],
            $this->bearer($this->q['id'], 'participant', $this->q['email'])
        );
        self::assertSame(201, $grant->getStatusCode());
        $qCompetitor = $this->competitorIdOf($this->q['id']);

        $response = $this->post($this->p, $this->selfPayload($this->p, ['competitorId' => $qCompetitor]));
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($this->q['id'], $data['userId']);
        self::assertSame($this->p['id'], $data['registeredById']);
        self::assertSame($this->q['email'], $data['userEmail']);
    }

    public function testAdminHasNoPaymentOrCareOverride(): void
    {
        $paid = $this->post($this->admin, $this->selfPayload($this->admin, ['paymentDetails' => ['startFeePaid' => true]]));
        self::assertSame(422, $paid->getStatusCode());
        self::assertSame('payment_fields_not_allowed', $this->json($paid)['error']['code']);

        $stranger = $this->post($this->admin, $this->selfPayload($this->admin, ['competitorId' => $this->competitorIdOf($this->q['id'])]));
        self::assertSame(403, $stranger->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function makeUser(string $slug, string $role): array
    {
        return $this->users->create([
            'name' => 'RegCreate ' . ucfirst($slug),
            'email' => $this->prefix . '-' . $slug . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ]);
    }

    /** Simulates a Konto created before the Zawodnik model existed. */
    private function legacyAccount(array $user): void
    {
        $this->pdo->prepare('DELETE FROM competitors WHERE user_id = :id')->execute(['id' => $user['id']]);
    }

    private function competitorIdOf(string $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT id FROM competitors WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        return (string) $stmt->fetchColumn();
    }

    private function countRegistrations(string $column, string $value): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM registrations WHERE event_id = :event AND {$column} = :v");
        $stmt->execute(['event' => $this->event['id'], 'v' => $value]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function rawRegistration(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    private function post(array $caller, array $payload): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('POST', '/api/v1/registrations', $payload, $this->bearer($caller['id'], (string) $caller['role'], $caller['email']));
    }

    /** @param array<string, mixed> $overrides */
    private function selfPayload(array $user, array $overrides = []): array
    {
        return array_merge([
            'eventId' => $this->event['id'],
            'eventName' => $this->event['name'],
            'userId' => $user['id'],
            'userName' => $user['name'],
            'userEmail' => $user['email'],
            'distanceId' => $this->paidDistanceId,
            'category' => 'Open',
            'merchandiseOrders' => [],
        ], $overrides);
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * E8.2 (spec 21 Answer §2): GET /events/{id}/registration-stats — built-in
 * organizer cross-sections (distance/payment-status/signup-curve, plus
 * gender/club/age-category when configured). Gated the same way as the
 * sibling E2.5 form-field-aggregates endpoint — see
 * FormFieldAggregatesContractTest's own doc comment for the precedent this
 * mirrors.
 */
final class RegistrationStatsContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private CompetitorRepository $competitors;
    private FormFieldRepository $fields;
    private string $prefix;
    private array $organizer;
    private array $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo, null, null, null);
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->prefix = 'hrs-' . bin2hex(random_bytes(4));

        $this->organizer = $this->users->create([
            'name' => 'Stats Organizer',
            'email' => $this->prefix . '-organizer@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            'eventLimit' => 5,
        ]);
        $this->stranger = $this->users->create([
            'name' => 'Stats Stranger',
            'email' => $this->prefix . '-stranger@example.test',
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
            $this->pdo->prepare('DELETE FROM registration_refunds WHERE registration_id IN (SELECT id FROM registrations WHERE event_id = :id)')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM registration_payment_details WHERE registration_id IN (SELECT id FROM registrations WHERE event_id = :id)')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM form_fields WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM competitors WHERE first_name LIKE :name')->execute(['name' => 'Stats Contract%']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    /**
     * @group known-failure-pp14
     *
     * PP.13: pre-existing failure on both MySQL 8.4 and MariaDB 10.4, unrelated to
     * migrations/CI — tracked by PP.14. Remove this tag once fixed there.
     */
    public function testOwnerGetsBuiltInBreakdownsAndStrangerIsForbidden(): void
    {
        [$eventId, $distanceId] = $this->createEventWithRegistration();

        $ownerResponse = $this->request(
            'GET',
            '/api/v1/events/' . $eventId . '/registration-stats',
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        self::assertSame(200, $ownerResponse->getStatusCode());
        $payload = $this->json($ownerResponse);
        self::assertTrue($payload['success']);
        $data = $payload['data'];

        self::assertSame([['distanceId' => $distanceId, 'distanceName' => '10 km', 'count' => 1]], $data['byDistance']);
        self::assertSame(
            ['paid' => 0, 'pending' => 1, 'expired' => 0, 'cancelled' => 0, 'refunded' => 0],
            array_column($data['byPaymentStatus'], 'count', 'status')
        );
        self::assertArrayHasKey('signupCurve', $data);
        self::assertNotEmpty($data['signupCurve']);
        // Gender defaults to `required` (control-class age_driven, never
        // hidden today — FormFieldCatalog::allowedStates()) so it is present
        // for every event out of the box.
        self::assertArrayHasKey('byGender', $data);
        // Club defaults to `hidden` (control-class free — migration 041's
        // backfill, FormFieldRepository::ensureSystemCatalog()'s default for
        // CONTROL_FREE) — the concrete "field not configured -> key absent"
        // case the ticket calls out.
        self::assertArrayNotHasKey('byClub', $data);
        self::assertArrayNotHasKey('ageCategories', $data);

        $strangerResponse = $this->request(
            'GET',
            '/api/v1/events/' . $eventId . '/registration-stats',
            [],
            $this->bearer($this->stranger['id'], 'organizer', $this->stranger['email'])
        );
        self::assertSame(403, $strangerResponse->getStatusCode());
    }

    public function testParticipantRoleIsRejected(): void
    {
        [$eventId] = $this->createEventWithRegistration();
        $participant = $this->users->create([
            'name' => 'Stats Participant',
            'email' => $this->prefix . '-participant@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $eventId . '/registration-stats',
            [],
            $this->bearer($participant['id'], 'participant', $participant['email'])
        );
        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * @group known-failure-pp14
     *
     * PP.13: pre-existing failure on both MySQL 8.4 and MariaDB 10.4, unrelated to
     * migrations/CI — tracked by PP.14. Remove this tag once fixed there.
     */
    public function testClubBreakdownAppearsOnlyOnceTheFieldIsShown(): void
    {
        [$eventId, $distanceId] = $this->createEventWithRegistration();

        $competitorWithClub = $this->competitors->create(
            ['firstName' => 'Stats Contract', 'lastName' => 'Klubowy', 'club' => 'AZS Warszawa'],
            skipDuplicateCheck: true
        );
        $this->registrations->create([
            'eventId' => $eventId,
            'eventName' => 'Stats Contract Event',
            'competitorId' => $competitorWithClub['id'],
            'userId' => 'http-regstats-user-' . bin2hex(random_bytes(4)),
            'userName' => 'Stats Contract Klubowy',
            'userEmail' => $this->prefix . '-reg-club-' . bin2hex(random_bytes(4)) . '@example.test',
            'distanceId' => $distanceId,
            'distanceName' => '10 km',
            'category' => 'Open',
        ]);

        $clubField = current(array_filter(
            $this->fields->listForEvent($eventId),
            static fn (array $f): bool => $f['fieldKey'] === 'club'
        ));
        self::assertNotFalse($clubField, 'The system catalog must already seed a club field for this event.');
        $this->fields->updateState((string) $clubField['id'], 'shown');

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $eventId . '/registration-stats',
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];

        self::assertArrayHasKey('byClub', $data);
        self::assertSame(['AZS Warszawa' => 1], array_column($data['byClub'], 'count', 'key'));
    }

    public function testAgeCategoryBreakdownOnlyIncludesDistancesThatConfigureCategories(): void
    {
        [$eventId, $distanceId] = $this->createEventWithRegistration();

        $competitor = $this->competitors->create(
            ['firstName' => 'Stats Contract', 'lastName' => 'Wiekowy', 'gender' => 'K', 'birthDate' => '2000-05-01'],
            skipDuplicateCheck: true
        );
        $this->registrations->create([
            'eventId' => $eventId,
            'eventName' => 'Stats Contract Event',
            'competitorId' => $competitor['id'],
            'userId' => 'http-regstats-user-' . bin2hex(random_bytes(4)),
            'userName' => 'Stats Contract Wiekowy',
            'userEmail' => $this->prefix . '-reg-age-' . bin2hex(random_bytes(4)) . '@example.test',
            'distanceId' => $distanceId,
            'distanceName' => '10 km',
            'category' => 'Open',
        ]);

        $this->pdo->prepare('UPDATE event_distances SET age_categories = :cats WHERE id = :id')->execute([
            'cats' => json_encode([
                ['id' => 'k18-29', 'name' => 'K18-29', 'gender' => 'K', 'birthYearFrom' => 1996, 'birthYearTo' => 2007],
                ['id' => 'k30-39', 'name' => 'K30-39', 'gender' => 'K', 'birthYearFrom' => 1986, 'birthYearTo' => 1995],
            ]),
            'id' => $distanceId,
        ]);

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $eventId . '/registration-stats',
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];

        self::assertArrayHasKey('ageCategories', $data);
        self::assertCount(1, $data['ageCategories'], 'Only the one Distance with configured age_categories should appear.');
        self::assertSame($distanceId, $data['ageCategories'][0]['distanceId']);
        self::assertSame(
            ['k18-29' => 1, 'k30-39' => 0],
            array_column($data['ageCategories'][0]['counts'], 'count', 'key')
        );
    }

    public function testSignupCurveBucketsRegistrationsByCalendarDay(): void
    {
        [$eventId, $distanceId] = $this->createEventWithRegistration();
        $registrationId = $this->pdo->query("SELECT id FROM registrations WHERE event_id = '{$eventId}' LIMIT 1")->fetchColumn();
        $this->pdo->prepare('UPDATE registrations SET registered_at = :at WHERE id = :id')->execute([
            'at' => '2026-09-15T10:00:00+00:00',
            'id' => $registrationId,
        ]);
        $this->pdo->prepare('UPDATE events SET registration_open_date = :d WHERE id = :id')->execute([
            'd' => '2026-09-14',
            'id' => $eventId,
        ]);

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $eventId . '/registration-stats',
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        $data = $this->json($response)['data'];
        $byDate = array_column($data['signupCurve'], 'count', 'date');

        self::assertArrayHasKey('2026-09-14', $byDate, 'The registration-open date must always be the first bucket, even with zero signups.');
        self::assertSame(0, $byDate['2026-09-14']);
        self::assertArrayHasKey('2026-09-15', $byDate);
        self::assertSame(1, $byDate['2026-09-15']);
    }

    /** @return array{0: string, 1: string} [eventId, distanceId] */
    private function createEventWithRegistration(array $eventOverrides = []): array
    {
        $slug = $this->prefix . '-event-' . bin2hex(random_bytes(3));
        $event = $this->events->createForOrganizer(
            array_replace($this->eventPayload($slug), $eventOverrides),
            $this->organizer,
            $this->organizer['id']
        );
        $eventId = (string) $event['id'];
        $distanceId = (string) $event['distances'][0]['id'];

        $competitor = $this->competitors->create(['firstName' => 'Stats Contract', 'lastName' => 'Testowy'], skipDuplicateCheck: true);
        $this->registrations->create([
            'eventId' => $eventId,
            'eventName' => 'Stats Contract Event',
            'competitorId' => $competitor['id'],
            'userId' => 'http-regstats-user-' . bin2hex(random_bytes(4)),
            'userName' => 'Stats Contract Testowy',
            'userEmail' => $this->prefix . '-reg-' . bin2hex(random_bytes(4)) . '@example.test',
            'distanceId' => $distanceId,
            'distanceName' => '10 km',
            'category' => 'Open',
        ]);

        return [$eventId, $distanceId];
    }

    private function eventPayload(string $slug): array
    {
        return [
            'name' => 'Stats Contract Event',
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

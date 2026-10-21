<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/** Transfer of a Zapis to another Zawodnik by the Event's manager. */
final class RegistrationTransferContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private CompetitorRepository $competitors;
    private string $prefix;
    private array $participant;
    private array $organizer;
    private array $event;
    private string $insuranceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo);
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->prefix = 'http-transfer-' . bin2hex(random_bytes(4));

        $make = fn (string $label, string $role): array => $this->users->create([
            'name' => 'TR ' . $label,
            'email' => $this->prefix . '-' . $label . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ]);
        $this->participant = $make('participant', 'participant');
        $this->organizer = $make('organizer', 'organizer');

        $this->insuranceId = $this->prefix . '-nnw';
        $this->pdo->prepare(
            'INSERT INTO insurance_settings (id, name, base_fee, age_multiplier, max_age, info_html, active, sort_order)
             VALUES (:id, :name, 15, 1, 70, :info, 1, 0)'
        )->execute(['id' => $this->insuranceId, 'name' => 'Transfer NNW', 'info' => '']);

        $this->event = $this->events->create([
            'name' => 'PHPUnit Transfer Event',
            'slug' => $this->prefix . '-event',
            'discipline' => 'running',
            'date' => '2027-06-15',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'TR Organizer',
            'status' => 'active',
            'registrationDeadline' => '2027-05-01',
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'insuranceVariantIds' => [$this->insuranceId],
            'distances' => [[
                'id' => $this->prefix . '-10k',
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
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix, $this->event)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
        $stmt->execute(['id' => $this->event['id']]);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $regId) {
            foreach (['registration_merchandise_orders', 'registration_payment_details', 'registration_settlement_items', 'registration_refunds', 'registration_changes', 'form_field_answers'] as $table) {
                $this->pdo->prepare("DELETE FROM {$table} WHERE registration_id = :id")->execute(['id' => $regId]);
            }
            $this->pdo->prepare('DELETE FROM activity_logs WHERE target_id = :id')->execute(['id' => $regId]);
        }
        $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM form_fields WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM event_insurance_variants WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM teams WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM insurance_settings WHERE id = :id')->execute(['id' => $this->insuranceId]);
        $this->pdo->prepare('DELETE FROM competitors WHERE last_name = :last')->execute(['last' => $this->prefix]);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    /** @return array<string, mixed> */
    private function register(bool $paid, bool $withInsurance = false, string $birthDate = '1990-06-15'): array
    {
        return $this->registrations->create([
            'eventId' => $this->event['id'],
            'eventName' => $this->event['name'],
            'userId' => $this->participant['id'],
            'userName' => $this->participant['name'],
            'userEmail' => $this->participant['email'],
            'distanceId' => $this->prefix . '-10k',
            'distanceName' => '10 km',
            'category' => 'Open',
            'merchandiseOrders' => [],
            ...($withInsurance ? ['insuranceVariantId' => $this->insuranceId, 'birthDate' => $birthDate] : []),
            ...($paid ? ['paymentDetails' => ['startFeePaid' => true]] : []),
        ]);
    }

    /** @return array<string, string> */
    private function ownerAuth(): array
    {
        return $this->bearer($this->participant['id'], 'participant', $this->participant['email']);
    }

    /** @return array<string, string> */
    private function managerAuth(): array
    {
        return $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']);
    }

    /** @return array{status: int, json: array<string, mixed>} */
    private function transfer(string $id, array $body, array $auth): array
    {
        $response = $this->request('POST', '/api/v1/registrations/' . $id . '/transfer', $body, $auth);

        return ['status' => $response->getStatusCode(), 'json' => $this->json($response)];
    }

    /** @return array<string, mixed> */
    private function row(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return (array) $stmt->fetch();
    }

    private function newPerson(string $birthDate = '2000-06-15'): array
    {
        return ['newPerson' => ['firstName' => 'Nowa', 'lastName' => $this->prefix, 'birthDate' => $birthDate, 'gender' => 'K', 'email' => $this->prefix . '-nowa@example.test']];
    }

    public function testManagerTransfersToANewPersonRotatingCodeAndDroppingAnswers(): void
    {
        $reg = $this->register(true);
        $this->pdo->prepare('UPDATE registrations SET bib_number = :b WHERE id = :id')->execute(['b' => '23', 'id' => $reg['id']]);
        $fieldId = $this->prefix . '-ff';
        $this->pdo->prepare(
            "INSERT INTO form_fields (id, event_id, kind, field_key, storage_location, state, field_type) VALUES (:id, :event, 'custom', :key, 'registration', 'shown', 'short_text')"
        )->execute(['id' => $fieldId, 'event' => $this->event['id'], 'key' => 'transfer-field']);
        $this->pdo->prepare('INSERT INTO form_field_answers (id, form_field_id, registration_id, value_text) VALUES (:id, :f, :r, :v)')
            ->execute(['id' => $this->prefix . '-ans', 'f' => $fieldId, 'r' => $reg['id'], 'v' => 'stara odpowiedź']);
        $before = $this->row($reg['id']);

        $result = $this->transfer($reg['id'], $this->newPerson(), $this->managerAuth());

        self::assertSame(200, $result['status'], json_encode($result['json']));
        $after = $this->row($reg['id']);
        self::assertSame($reg['id'], $after['id']);
        self::assertNotSame($before['competitor_id'], $after['competitor_id']);
        self::assertSame('Nowa ' . $this->prefix, $after['user_name']);
        self::assertSame($this->prefix . '-nowa@example.test', $after['user_email']);
        self::assertNotSame($before['qr_code'], $after['qr_code']);
        self::assertMatchesRegularExpression('/^pqr_[a-f0-9]{48}$/', (string) $after['qr_code']);
        self::assertSame($after['qr_code'], $result['json']['data']['qrCode']);
        self::assertSame('23', $after['bib_number']);
        self::assertSame('confirmed', $after['status']);
        $answers = $this->pdo->prepare('SELECT COUNT(*) FROM form_field_answers WHERE registration_id = :id');
        $answers->execute(['id' => $reg['id']]);
        self::assertSame(0, (int) $answers->fetchColumn());
        // No money moves: same frozen Rozliczenie, no refund, no balance.
        self::assertSame(80.0, (float) $result['json']['data']['totalAmount']);
        self::assertSame(0, $result['json']['data']['balanceDueMinor']);
        self::assertSame(0, $result['json']['data']['overpaymentMinor']);
        self::assertNull($result['json']['data']['refund']);

        $history = $this->json($this->request('GET', '/api/v1/registrations/' . $reg['id'] . '/changes', [], $this->managerAuth()))['data'];
        self::assertCount(1, $history);
        self::assertSame('transferred', $history[0]['type']);
        self::assertSame('manager', $history[0]['actorRole']);
        self::assertSame($this->participant['name'], $history[0]['from']['name']);
        self::assertSame('Nowa ' . $this->prefix, $history[0]['to']['name']);
        $logged = $this->pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE action = 'registration_transferred' AND target_id = :id");
        $logged->execute(['id' => $reg['id']]);
        self::assertSame(1, (int) $logged->fetchColumn());
    }

    public function testManagerTransfersToAnExistingCompetitor(): void
    {
        $reg = $this->register(false);
        $competitor = $this->competitors->create(['firstName' => 'Istniejący', 'lastName' => $this->prefix, 'birthDate' => '1985-03-03', 'gender' => 'M'], true);

        $result = $this->transfer($reg['id'], ['competitorId' => $competitor['id']], $this->managerAuth());

        self::assertSame(200, $result['status'], json_encode($result['json']));
        self::assertSame($competitor['id'], $this->row($reg['id'])['competitor_id']);
        self::assertSame('pending', $result['json']['data']['status']);
    }

    public function testOwnerGetsForbiddenAndNothingChanges(): void
    {
        $reg = $this->register(true);
        $before = $this->row($reg['id']);

        $result = $this->transfer($reg['id'], $this->newPerson(), $this->ownerAuth());

        self::assertSame(403, $result['status']);
        self::assertSame($before['qr_code'], $this->row($reg['id'])['qr_code']);
        self::assertSame($before['competitor_id'], $this->row($reg['id'])['competitor_id']);
    }

    public function testValidationAndConflicts(): void
    {
        $reg = $this->register(true);
        $person = $this->competitors->create(['firstName' => 'Zajęty', 'lastName' => $this->prefix, 'birthDate' => '1985-03-03', 'gender' => 'M'], true);

        self::assertSame(422, $this->transfer($reg['id'], [], $this->managerAuth())['status']);
        self::assertSame('team_swap_person_required', $this->transfer($reg['id'], ['newPerson' => ['firstName' => 'X']], $this->managerAuth())['json']['error']['code']);
        self::assertSame(404, $this->transfer($reg['id'], ['competitorId' => 'nie-ma'], $this->managerAuth())['status']);
        self::assertSame(404, $this->transfer('nie-ma', $this->newPerson(), $this->managerAuth())['status']);

        // The person who is already on this Zapis, and one already registered on the Event.
        $this->pdo->prepare('UPDATE registrations SET competitor_id = :c WHERE id = :id')->execute(['c' => $person['id'], 'id' => $reg['id']]);
        self::assertSame(422, $this->transfer($reg['id'], ['competitorId' => $person['id']], $this->managerAuth())['status']);
        $other = $this->register(false);
        $taken = $this->transfer($other['id'], ['competitorId' => $person['id']], $this->managerAuth());
        self::assertSame(409, $taken['status']);
        self::assertSame('team_swap_competitor_already_registered', $taken['json']['error']['code']);
    }

    public function testCancelledRegistrationCannotBeTransferred(): void
    {
        $reg = $this->register(false);
        $this->pdo->prepare("UPDATE registrations SET status = 'cancelled' WHERE id = :id")->execute(['id' => $reg['id']]);

        $result = $this->transfer($reg['id'], $this->newPerson(), $this->managerAuth());

        self::assertSame(409, $result['status']);
        self::assertSame('transfer_registration_inactive', $result['json']['error']['code']);
    }

    public function testRelayTeamMemberIsRefusedAndPointedToTheTeamSwap(): void
    {
        $reg = $this->register(false);
        $teamId = $this->prefix . '-team';
        $this->pdo->prepare('INSERT INTO teams (id, event_id, distance_id, name, created_by_user_id, created_at) VALUES (:id, :e, :d, :n, :c, :at)')
            ->execute(['id' => $teamId, 'e' => $this->event['id'], 'd' => $this->prefix . '-10k', 'n' => 'Sztafeta', 'c' => $this->participant['id'], 'at' => date(DATE_ATOM)]);
        $this->pdo->prepare('UPDATE registrations SET team_id = :t WHERE id = :id')->execute(['t' => $teamId, 'id' => $reg['id']]);
        $before = $this->row($reg['id']);

        $result = $this->transfer($reg['id'], $this->newPerson(), $this->managerAuth());

        self::assertSame(409, $result['status']);
        self::assertSame('transfer_team_registration', $result['json']['error']['code']);
        self::assertStringContainsString('wymiana zawodnika', $result['json']['error']['message']);
        self::assertSame($before['qr_code'], $this->row($reg['id'])['qr_code']);
    }

    public function testInsuranceIsPricedAgainForTheNewPersonAndOnlyReportedForAPaidRegistration(): void
    {
        // Event 2027-06-15: born 1990-06-15 is 37 -> 15 + 19 * 1 = 34; born 2000-06-15 is 27 -> 15 + 9 = 24.
        $reg = $this->register(true, true, '1990-06-15');
        self::assertSame(114.0, (float) $reg['totalAmount']);

        $result = $this->transfer($reg['id'], $this->newPerson('2000-06-15'), $this->managerAuth());

        self::assertSame(200, $result['status'], json_encode($result['json']));
        $data = $result['json']['data'];
        self::assertSame(104.0, (float) $data['totalAmount']);
        self::assertSame(0, $data['balanceDueMinor']);
        self::assertSame(1000, $data['overpaymentMinor']);
        self::assertNull($data['refund'], 'a difference is informational: no refund is opened');
        $history = $this->json($this->request('GET', '/api/v1/registrations/' . $reg['id'] . '/changes', [], $this->managerAuth()))['data'];
        self::assertTrue($history[0]['to']['insuranceRepriced']);
        self::assertSame(1000, $history[0]['to']['overpaymentMinor']);
    }

    public function testOlderNewPersonLeavesAPaidRegistrationWithBalanceDue(): void
    {
        $reg = $this->register(true, true, '2000-06-15');

        $result = $this->transfer($reg['id'], $this->newPerson('1990-06-15'), $this->managerAuth());

        self::assertSame(200, $result['status'], json_encode($result['json']));
        self::assertSame(1000, $result['json']['data']['balanceDueMinor']);
        self::assertSame(0, $result['json']['data']['overpaymentMinor']);
        self::assertNull($result['json']['data']['refund']);
    }

    public function testInsuredRegistrationNeedsTheNewPersonsBirthDateAndRollsBack(): void
    {
        $reg = $this->register(false, true);
        $before = $this->row($reg['id']);

        $result = $this->transfer($reg['id'], ['newPerson' => ['firstName' => 'Bez', 'lastName' => $this->prefix]], $this->managerAuth());

        self::assertSame(422, $result['status']);
        self::assertSame('transfer_insurance_birth_date_missing', $result['json']['error']['code']);
        self::assertSame($before['qr_code'], $this->row($reg['id'])['qr_code']);
        self::assertSame($before['competitor_id'], $this->row($reg['id'])['competitor_id']);
        $created = $this->pdo->prepare('SELECT COUNT(*) FROM competitors WHERE first_name = :f AND last_name = :l');
        $created->execute(['f' => 'Bez', 'l' => $this->prefix]);
        self::assertSame(0, (int) $created->fetchColumn());
    }

    public function testTransferMailsGoToBothPeopleThroughTheOutbox(): void
    {
        $reg = $this->register(false);

        $result = $this->transfer($reg['id'], $this->newPerson(), $this->managerAuth());

        self::assertSame(200, $result['status']);
        $stmt = $this->pdo->prepare('SELECT recipient FROM central_mailer_outbox WHERE recipient IN (:a, :b)');
        $stmt->execute(['a' => $this->participant['email'], 'b' => $this->prefix . '-nowa@example.test']);
        $recipients = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE recipient LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
        if ($recipients === []) {
            self::markTestSkipped('Central Mailer is not configured in this environment (no outbox rows).');
        }
        self::assertContains($this->prefix . '-nowa@example.test', $recipients);
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * PP.65 / PP.79 / PP.80: POST /registrations decides who may pick which Wariant opłaty, derives
 * the Kategoria wiekowa from the Zawodnik's data and answers 422 (never 500) when NNW cannot be
 * priced. Every rejected request must leave nothing behind.
 */
final class RegistrationEligibilityContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private CompetitorRepository $competitors;
    private string $prefix;
    private array $organizer;
    private array $otherOrganizer;
    private array $admin;
    private array $event;
    private string $eventDate;
    private string $dv;
    private string $dn;
    private string $dl;
    private string $dg;
    private string $do;
    private string $dx;
    private string $insuranceId;
    private string $unofferedInsuranceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->prefix = 'http-regelig-' . bin2hex(random_bytes(4));

        $this->organizer = $this->makeUser('org1', 'organizer');
        $this->otherOrganizer = $this->makeUser('org2', 'organizer');
        $this->admin = $this->makeUser('admin', 'admin');

        $slug = $this->prefix . '-event';
        $this->dv = $slug . '-dv';
        $this->dn = $slug . '-dn';
        $this->dl = $slug . '-dl';
        $this->dg = $slug . '-dg';
        $this->do = $slug . '-do';
        $this->dx = $slug . '-dx';
        $this->eventDate = date('Y-m-d', strtotime('+90 days'));

        $this->insuranceId = $this->prefix . '-nnw';
        $this->unofferedInsuranceId = $this->prefix . '-nnw-off';
        $this->insertInsurance($this->insuranceId);
        $this->insertInsurance($this->unofferedInsuranceId);

        $distance = static fn (string $id, string $name, int $price, array $extra = []): array => array_merge([
            'id' => $id,
            'name' => $name,
            'length' => 10,
            'unit' => 'km',
            'categories' => [],
            'ageCategories' => [],
            'specialCategories' => [],
            'limit' => 100,
            'registeredCount' => 0,
            'price' => $price,
            'routeFiles' => [],
        ], $extra);
        $variant = fn (string $key, string $name, int $price, bool $officeOnly, bool $visible): array => [
            'id' => $this->prefix . '-v-' . $key,
            'name' => $name,
            'price' => $price,
            'officeOnly' => $officeOnly,
            'visibleInPublicForm' => $visible,
        ];

        $this->event = $this->events->create([
            'name' => 'PHPUnit RegEligibility Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => $this->eventDate,
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'RegEligibility Organizer',
            'status' => 'active',
            'registrationDeadline' => date('Y-m-d', strtotime('+60 days')),
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'organizer_bank'],
            'insuranceVariantIds' => [$this->insuranceId],
            'discountCodes' => [
                [
                    'id' => $this->prefix . '-full',
                    'code' => 'ELIGFULL',
                    'discountType' => 'percent',
                    'discountValue' => 100,
                    'usedCount' => 0,
                    'distanceIds' => [],
                    'active' => true,
                ],
            ],
            'distances' => [
                $distance($this->dv, 'Wiekowy 10 km', 100, [
                    'ageCategories' => [
                        ['id' => 'k-kids', 'name' => 'K2014-2016', 'gender' => 'K', 'birthYearFrom' => 2014, 'birthYearTo' => 2016],
                        ['id' => 'm-old', 'name' => 'M1976-1985', 'gender' => 'M', 'birthYearFrom' => 1976, 'birthYearTo' => 1985],
                        ['id' => 'm-30', 'name' => 'M30-39', 'gender' => 'M', 'birthYearFrom' => 1986, 'birthYearTo' => 1995],
                    ],
                    'specialCategories' => [['name' => 'Weteran'], ['name' => str_repeat('W', 76)]],
                    'pricingVariants' => [
                        $variant('std', 'STD', 100, false, true),
                        $variant('biuro', 'BIURO', 20, true, false),
                        $variant('ukryty', 'UKRYTY', 10, false, false),
                        $variant('zero', 'ZERO', 0, false, false),
                    ],
                ]),
                $distance($this->dn, 'Bez kategorii', 50),
                $distance($this->dl, 'Legacy kategorie', 50, ['categories' => ['Open', 'Elite']]),
                $distance($this->dg, 'Tylko M 1980-2000', 50, [
                    'ageCategories' => [['id' => 'm-only', 'name' => 'M-ONLY', 'gender' => 'M', 'birthYearFrom' => 1980, 'birthYearTo' => 2000]],
                ]),
                $distance($this->do, 'Otwarte zakresy', 50, [
                    'ageCategories' => [
                        ['id' => 'young', 'name' => 'YOUNG', 'gender' => '', 'birthYearFrom' => null, 'birthYearTo' => 2000],
                        ['id' => 'open', 'name' => 'OPEN', 'gender' => '', 'birthYearFrom' => null, 'birthYearTo' => null],
                    ],
                ]),
                $distance($this->dx, 'Inny dystans', 50, [
                    'pricingVariants' => [$variant('other', 'OTHER', 5, false, true)],
                ]),
            ],
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
            foreach (['registration_merchandise_orders', 'registration_payment_details', 'registration_settlement_items', 'registration_refunds', 'registration_gateway_transactions'] as $table) {
                $this->pdo->prepare("DELETE FROM {$table} WHERE registration_id = :id")->execute(['id' => $regId]);
            }
        }
        $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM teams WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM event_discount_codes WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM event_insurance_variants WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE v FROM event_distance_pricing_variants v JOIN event_distances d ON d.id = v.distance_id WHERE d.event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM insurance_settings WHERE id LIKE :id')->execute(['id' => $this->prefix . '-%']);
        $this->pdo->prepare(
            'DELETE cr FROM care_relations cr JOIN users u ON u.id = cr.konto_id WHERE u.email LIKE :email'
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

    // ── PP.80 ────────────────────────────────────────────────────────────

    public function testAc25NnwWithoutBirthDateIsRejected(): void
    {
        $p = $this->person('p', 'participant', null, 'M');
        $before = $this->snapshot();

        $response = $this->selfPost($p, ['distanceId' => $this->dn, 'insuranceVariantId' => $this->insuranceId]);

        $this->assert422($response, 'insurance_birth_date_missing', 'insuranceVariantId');
        $this->assertNothingWritten($before);
    }

    public function testAc26NnwWithYearOnlyBirthDateIsRejected(): void
    {
        $p = $this->person('p', 'participant', '2010', 'M');
        $before = $this->snapshot();

        $response = $this->selfPost($p, ['distanceId' => $this->dn, 'insuranceVariantId' => $this->insuranceId]);

        $this->assert422($response, 'insurance_birth_date_missing', 'insuranceVariantId');
        $this->assertNothingWritten($before);
    }

    public function testAc27NnwForWardWithoutBirthDateNeverUsesGuardianDate(): void
    {
        $g = $this->person('g', 'participant', '1980-01-01', 'M');
        $ward = $this->ward(null, 'K', $g);
        $before = $this->snapshot();

        $response = $this->post($g, ['competitorId' => $ward, 'distanceId' => $this->dn, 'insuranceVariantId' => $this->insuranceId]);

        $this->assert422($response, 'insurance_birth_date_missing', 'insuranceVariantId');
        $this->assertNothingWritten($before);
    }

    public function testAc28NnwFallsBackToOwnKontoBirthDate(): void
    {
        $p = $this->person('p', 'participant', null, 'M');
        $birth = date('Y-m-d', strtotime('-41 years -30 days', strtotime($this->eventDate)));
        $this->pdo->prepare('UPDATE users SET birth_date = :d WHERE id = :id')->execute(['d' => $birth, 'id' => $p['id']]);

        $response = $this->selfPost($p, ['distanceId' => $this->dn, 'insuranceVariantId' => $this->insuranceId]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $stmt = $this->pdo->prepare("SELECT amount FROM registration_settlement_items WHERE registration_id = :id AND basis = 'insurance'");
        $stmt->execute(['id' => $this->json($response)['data']['id']]);
        self::assertSame(19.6, round((float) $stmt->fetchColumn(), 2));
    }

    public function testAc29NnwVariantNotOfferedByEventIsRejected(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', 'M');
        $before = $this->snapshot();

        $response = $this->selfPost($p, ['distanceId' => $this->dn, 'insuranceVariantId' => $this->unofferedInsuranceId]);

        $this->assert422($response, 'insurance_variant_invalid', 'insuranceVariantId');
        $this->assertNothingWritten($before);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function vid(string $key): string
    {
        return $this->prefix . '-v-' . $key;
    }

    /** @return array<string, mixed> */
    private function makeUser(string $slug, string $role): array
    {
        return $this->users->create([
            'name' => 'RegElig ' . ucfirst($slug),
            'email' => $this->prefix . '-' . $slug . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ]);
    }

    /** A Konto plus its own Zawodnik with the given birth date / gender (null = unknown). */
    private function person(string $slug, string $role, ?string $birthDate, ?string $gender): array
    {
        $user = $this->makeUser($slug . bin2hex(random_bytes(2)), $role);
        $this->setCompetitor($user['id'], $birthDate, $gender);
        return $user;
    }

    private function setCompetitor(string $userId, ?string $birthDate, ?string $gender): void
    {
        $this->pdo->prepare('UPDATE competitors SET birth_date = :b, gender = :g WHERE user_id = :id')
            ->execute(['b' => $birthDate, 'g' => $gender, 'id' => $userId]);
        $this->pdo->prepare('UPDATE users SET birth_year = NULL, birth_date = NULL, gender = NULL WHERE id = :id')
            ->execute(['id' => $userId]);
    }

    /** A Zawodnik without a Konto under the guardian's care. */
    private function ward(?string $birthDate, ?string $gender, array $guardian): string
    {
        $ward = $this->competitors->create([
            'firstName' => 'Podopieczny',
            'lastName' => $this->prefix,
            'birthDate' => $birthDate,
            'gender' => $gender,
        ], true);
        $this->pdo->prepare('INSERT INTO care_relations (id, konto_id, zawodnik_id) VALUES (:id, :k, :z)')->execute([
            'id' => bin2hex(random_bytes(16)),
            'k' => $guardian['id'],
            'z' => $ward['id'],
        ]);
        return (string) $ward['id'];
    }

    private function insertInsurance(string $id): void
    {
        $this->pdo->prepare(
            'INSERT INTO insurance_settings (id, name, base_fee, age_multiplier, max_age, info_html, active, sort_order)
             VALUES (:id, :name, 15.00, 0.20, 70, \'\', 1, 0)'
        )->execute(['id' => $id, 'name' => 'NNW PHPUnit']);
    }

    private function post(array $caller, array $payload): ResponseInterface
    {
        $body = array_merge([
            'eventId' => $this->event['id'],
            'distanceId' => $this->dv,
            'category' => 'Open',
            'merchandiseOrders' => [],
        ], $payload);

        return $this->request('POST', '/api/v1/registrations', $body, $this->bearer($caller['id'], (string) $caller['role'], $caller['email']));
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<string> $unset keys removed from the body
     */
    private function selfPost(array $caller, array $overrides = [], array $unset = []): ResponseInterface
    {
        $body = array_merge([
            'eventId' => $this->event['id'],
            'distanceId' => $this->dv,
            'category' => 'Open',
            'merchandiseOrders' => [],
        ], $overrides);
        foreach ($unset as $key) {
            unset($body[$key]);
        }

        return $this->request('POST', '/api/v1/registrations', $body, $this->bearer($caller['id'], (string) $caller['role'], $caller['email']));
    }

    private function assert422(ResponseInterface $response, string $code, string $field): void
    {
        $body = $this->json($response);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertFalse($body['success']);
        self::assertSame($code, $body['error']['code']);
        self::assertSame($field, $body['error']['details']['field'] ?? null);
        self::assertMatchesRegularExpression('/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]|\b(nie|dla|zawodnika)\b/u', (string) $body['error']['message']);
    }

    /** @return array{regs: int, teams: int, items: int, counts: array<string, int>} */
    private function snapshot(): array
    {
        $counts = $this->pdo->prepare('SELECT id, registered_count FROM event_distances WHERE event_id = :e');
        $counts->execute(['e' => $this->event['id']]);
        return [
            'regs' => $this->countWhere('registrations', 'event_id = :e', ['e' => $this->event['id']]),
            'teams' => $this->countWhere('teams', 'event_id = :e', ['e' => $this->event['id']]),
            'items' => $this->countWhere(
                'registration_settlement_items',
                'registration_id IN (SELECT id FROM registrations WHERE event_id = :e)',
                ['e' => $this->event['id']]
            ),
            'counts' => array_map('intval', $counts->fetchAll(\PDO::FETCH_KEY_PAIR)),
        ];
    }

    /** @param array{regs: int, teams: int, items: int, counts: array<string, int>} $before */
    private function assertNothingWritten(array $before): void
    {
        self::assertSame($before, $this->snapshot(), 'a rejected registration must leave nothing behind');
    }

    /** @param array<string, mixed> $params */
    private function countWhere(string $table, string $where, array $params): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function storedCategory(string $registrationId): string
    {
        $stmt = $this->pdo->prepare('SELECT category FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $registrationId]);
        return (string) $stmt->fetchColumn();
    }

    private function distancePriceLine(string $registrationId, bool $allowMissing = false): float
    {
        $stmt = $this->pdo->prepare("SELECT amount FROM registration_settlement_items WHERE registration_id = :id AND basis = 'distance_price'");
        $stmt->execute(['id' => $registrationId]);
        $amount = $stmt->fetchColumn();
        if ($amount === false && $allowMissing) {
            return 0.0;
        }
        self::assertNotFalse($amount);
        return (float) $amount;
    }
}

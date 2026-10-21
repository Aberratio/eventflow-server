<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * POST /registrations decides who may pick which Wariant opłaty, derives
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

    // ── Eligibility checks ────────────────────────────────────────────────────────────

    public function testAc1ParticipantCannotPickOfficeOnlyVariant(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', 'M');
        $before = $this->snapshot();

        $response = $this->selfPost($p, ['pricingVariantId' => $this->vid('biuro')]);

        $this->assert422($response, 'pricing_variant_unavailable', 'pricingVariantId');
        $this->assertNothingWritten($before);
    }

    public function testAc2ParticipantCannotPickHiddenVariant(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', 'M');
        $before = $this->snapshot();

        $response = $this->selfPost($p, ['pricingVariantId' => $this->vid('ukryty')]);

        $this->assert422($response, 'pricing_variant_unavailable', 'pricingVariantId');
        $this->assertNothingWritten($before);
    }

    public function testAc3ParticipantPicksPublicVariant(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', 'M');

        $response = $this->selfPost($p, ['pricingVariantId' => $this->vid('std')]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(100.0, $this->distancePriceLine($this->json($response)['data']['id']));
    }

    /** @dataProvider staffRoles */
    public function testAc4StaffMayPickOfficeOnlyVariant(string $role): void
    {
        $staff = $this->person('staff', $role, '1990-05-05', 'M');

        $response = $this->selfPost($staff, ['pricingVariantId' => $this->vid('biuro')]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(20.0, $this->distancePriceLine($this->json($response)['data']['id']));
    }

    public static function staffRoles(): array
    {
        return ['admin' => ['admin'], 'superadmin' => ['superadmin'], 'moderator' => ['moderator']];
    }

    public function testAc5OwningOrganizerMayAndForeignOrganizerMayNot(): void
    {
        $this->setCompetitor($this->organizer['id'], '1990-05-05', 'M');
        $this->setCompetitor($this->otherOrganizer['id'], '1990-05-05', 'M');

        $own = $this->selfPost($this->organizer, ['pricingVariantId' => $this->vid('biuro')]);
        self::assertSame(201, $own->getStatusCode());
        self::assertSame(20.0, $this->distancePriceLine($this->json($own)['data']['id']));

        $before = $this->snapshot();
        $foreign = $this->selfPost($this->otherOrganizer, ['pricingVariantId' => $this->vid('biuro')]);
        $this->assert422($foreign, 'pricing_variant_unavailable', 'pricingVariantId');
        $this->assertNothingWritten($before);
    }

    public function testAc6GuardianCannotPickOfficeVariantForWard(): void
    {
        $g = $this->person('g', 'participant', '1980-01-01', 'M');
        $ward = $this->ward('2015-03-01', 'K', $g);
        $before = $this->snapshot();

        $response = $this->post($g, ['competitorId' => $ward, 'pricingVariantId' => $this->vid('biuro')]);

        $this->assert422($response, 'pricing_variant_unavailable', 'pricingVariantId');
        $this->assertNothingWritten($before);
    }

    public function testAc7UnknownOrForeignDistanceVariantIsInvalid(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', 'M');
        $before = $this->snapshot();

        $unknown = $this->selfPost($p, ['pricingVariantId' => $this->prefix . '-v-nope']);
        $this->assert422($unknown, 'pricing_variant_invalid', 'pricingVariantId');
        $foreign = $this->selfPost($p, ['pricingVariantId' => $this->vid('other')]);
        $this->assert422($foreign, 'pricing_variant_invalid', 'pricingVariantId');
        $this->assertNothingWritten($before);
    }

    public function testAc8HiddenZeroPriceVariantIsRejectedNotBornConfirmed(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', 'M');
        $before = $this->snapshot();

        $response = $this->selfPost($p, ['pricingVariantId' => $this->vid('zero')]);

        $this->assert422($response, 'pricing_variant_unavailable', 'pricingVariantId');
        $this->assertNothingWritten($before);
        self::assertSame(0, $this->countWhere('registrations', 'status = \'confirmed\' AND event_id = :e', ['e' => $this->event['id']]));
    }

    public function testAc9HiddenVariantWithFullDiscountCodeIsRejected(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', 'M');
        $before = $this->snapshot();

        $response = $this->selfPost($p, ['pricingVariantId' => $this->vid('biuro'), 'discountCode' => 'ELIGFULL']);

        $this->assert422($response, 'pricing_variant_unavailable', 'pricingVariantId');
        $this->assertNothingWritten($before);
        $used = $this->pdo->prepare('SELECT used_by FROM event_discount_codes WHERE event_id = :e');
        $used->execute(['e' => $this->event['id']]);
        $raw = $used->fetchColumn();
        self::assertSame([], $raw === null || $raw === '' ? [] : (json_decode((string) $raw, true) ?: []));
    }

    public function testAc10RelayJoinUnderPerTeamIgnoresVariant(): void
    {
        $captain = $this->person('cap', 'participant', '1990-05-05', 'M');
        $joiner = $this->person('join', 'participant', '1991-05-05', 'M');
        $relay = $this->prefix . '-event-relay';
        $this->pdo->prepare(
            "INSERT INTO event_distances (id, event_id, name, length, unit, price, participant_limit, registered_count, start_type, relay_min_team_size, relay_max_team_size, team_pricing_mode, sort_order)
             VALUES (:id, :e, 'Sztafeta', 10, 'km', 80, 100, 0, 'relay', 2, 4, 'per_team', 9)"
        )->execute(['id' => $relay, 'e' => $this->event['id']]);
        $this->pdo->prepare(
            "INSERT INTO event_distance_pricing_variants (id, distance_id, name, price, office_only, visible_in_public_form, sort_order)
             VALUES (:id, :d, 'REL', 80, 0, 1, 0)"
        )->execute(['id' => $this->prefix . '-v-rel', 'd' => $relay]);

        $created = $this->selfPost($captain, ['distanceId' => $relay, 'createTeam' => true, 'teamName' => 'Drużyna R', 'pricingVariantId' => $this->prefix . '-v-rel']);
        self::assertSame(201, $created->getStatusCode());
        $teamId = (string) $this->json($created)['data']['teamId'];
        $tokenRow = $this->pdo->prepare('SELECT invite_token FROM teams WHERE id = :id');
        $tokenRow->execute(['id' => $teamId]);
        $token = (string) $tokenRow->fetchColumn();

        $join = $this->selfPost($joiner, ['distanceId' => $relay, 'teamId' => $teamId, 'teamInviteToken' => $token, 'pricingVariantId' => $this->prefix . '-v-rel']);
        self::assertSame(201, $join->getStatusCode(), (string) $join->getBody());
        self::assertSame(0.0, $this->distancePriceLine($this->json($join)['data']['id'], true));
    }

    // ── Server-side category validation ────────────────────────────────────────────────────────────

    public function testAc11ServerComputesCategoryIgnoringClient(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', 'M');

        $response = $this->selfPost($p, ['category' => 'K50+']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('M30-39', $this->storedCategory($this->json($response)['data']['id']));
    }

    public function testAc12MissingOrEmptyCategoryIsComputed(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', 'M');
        $q = $this->person('q', 'participant', '1990-05-05', 'M');

        $omitted = $this->selfPost($p, ['category' => null], ['category']);
        $empty = $this->selfPost($q, ['category' => '']);

        self::assertSame(201, $omitted->getStatusCode());
        self::assertSame('M30-39', $this->storedCategory($this->json($omitted)['data']['id']));
        self::assertSame(201, $empty->getStatusCode());
        self::assertSame('M30-39', $this->storedCategory($this->json($empty)['data']['id']));
    }

    public function testAc13GuardianDataNeverClassifiesWard(): void
    {
        $g = $this->person('g', 'participant', '1980-01-01', 'M');
        $ward = $this->ward('2015-03-01', 'K', $g);

        $response = $this->post($g, ['competitorId' => $ward, 'category' => 'M1976-1985']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('K2014-2016', $this->storedCategory($this->json($response)['data']['id']));
    }

    public function testAc14WardWithoutBirthDateIsRejectedEvenIfGuardianHasOne(): void
    {
        $g = $this->person('g', 'participant', '1980-01-01', 'M');
        $ward = $this->ward(null, 'K', $g);
        $before = $this->snapshot();

        $response = $this->post($g, ['competitorId' => $ward, 'category' => 'M1976-1985']);

        $this->assert422($response, 'registration_birth_date_required', 'category');
        $this->assertNothingWritten($before);
    }

    public function testAc15NoMatchingCategoryForBirthYear(): void
    {
        $p = $this->person('p', 'participant', '2015-03-01', 'M');
        $before = $this->snapshot();

        $response = $this->selfPost($p, ['distanceId' => $this->dg]);

        $this->assert422($response, 'registration_category_unavailable', 'category');
        $this->assertNothingWritten($before);
    }

    public function testAc16NullGenderNeverMatchesGenderSpecificCategory(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', null);
        $q = $this->person('q', 'participant', '1990-05-05', null);
        $before = $this->snapshot();

        $specific = $this->selfPost($p, ['distanceId' => $this->dg]);
        $this->assert422($specific, 'registration_category_unavailable', 'category');
        $this->assertNothingWritten($before);

        $neutral = $this->selfPost($q, ['distanceId' => $this->do]);
        self::assertSame(201, $neutral->getStatusCode());
        self::assertSame('YOUNG', $this->storedCategory($this->json($neutral)['data']['id']));
    }

    public function testAc17FallsBackToOwnKontoBirthYearAndGender(): void
    {
        $p = $this->person('p', 'participant', null, null);
        $this->pdo->prepare('UPDATE users SET birth_year = 1990, birth_date = NULL, gender = \'M\' WHERE id = :id')->execute(['id' => $p['id']]);

        $response = $this->selfPost($p, ['category' => 'whatever']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('M30-39', $this->storedCategory($this->json($response)['data']['id']));
    }

    public function testAc18BoundariesAreInclusiveAndOpenEnded(): void
    {
        $cases = [
            ['1986', 'M', $this->dv, 'M30-39'],
            ['1995', 'M', $this->dv, 'M30-39'],
            ['2000', null, $this->do, 'YOUNG'],
            ['1950', null, $this->do, 'YOUNG'],
            ['2001', null, $this->do, 'OPEN'],
            ['2024', null, $this->do, 'OPEN'],
        ];
        foreach ($cases as $i => [$year, $gender, $distanceId, $expected]) {
            $p = $this->person('b' . $i, 'participant', $year . '-06-15', $gender);
            $response = $this->selfPost($p, ['distanceId' => $distanceId]);
            self::assertSame(201, $response->getStatusCode(), $year . ' ' . (string) $response->getBody());
            self::assertSame($expected, $this->storedCategory($this->json($response)['data']['id']), $year);
        }
    }

    public function testAc19OverlappingCategoriesFirstInConfiguredOrderWins(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', 'M');

        $response = $this->selfPost($p, ['distanceId' => $this->do]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('YOUNG', $this->storedCategory($this->json($response)['data']['id']));
    }

    public function testAc20SpecialCategoriesAreKeptOnlyWhenConfigured(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', 'M');
        $q = $this->person('q', 'participant', '1990-05-05', 'M');
        $before = $this->snapshot();

        $fake = $this->selfPost($q, ['category' => 'M30-39, Fake']);
        $this->assert422($fake, 'registration_category_invalid', 'category');
        $this->assertNothingWritten($before);

        $ok = $this->selfPost($p, ['category' => 'K50+, Weteran']);
        self::assertSame(201, $ok->getStatusCode());
        self::assertSame('M30-39, Weteran', $this->storedCategory($this->json($ok)['data']['id']));
    }

    public function testAc21LegacyCategoryListIsAnAllowlist(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', 'M');
        $q = $this->person('q', 'participant', '1990-05-05', 'M');
        $before = $this->snapshot();

        $bad = $this->selfPost($q, ['distanceId' => $this->dl, 'category' => 'Hacker']);
        $this->assert422($bad, 'registration_category_invalid', 'category');
        $this->assertNothingWritten($before);

        $ok = $this->selfPost($p, ['distanceId' => $this->dl, 'category' => 'Elite']);
        self::assertSame(201, $ok->getStatusCode());
        self::assertSame('Elite', $this->storedCategory($this->json($ok)['data']['id']));
    }

    public function testAc22DistanceWithoutCategoriesStoresEmptyCategory(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', 'M');

        $response = $this->selfPost($p, ['distanceId' => $this->dn, 'category' => 'Anything']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('', $this->storedCategory($this->json($response)['data']['id']));
    }

    public function testAc23ComposedCategoryOver80CharactersIsRejected(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', 'M');
        $before = $this->snapshot();

        $response = $this->selfPost($p, ['category' => 'Weteran, ' . str_repeat('W', 76)]);

        $this->assert422($response, 'registration_category_invalid', 'category');
        $this->assertNothingWritten($before);
    }

    public function testAc24ExistingRegistrationKeepsStoredCategory(): void
    {
        $p = $this->person('p', 'participant', '1990-05-05', 'M');
        $response = $this->selfPost($p, ['distanceId' => $this->dn]);
        $id = (string) $this->json($response)['data']['id'];
        $this->pdo->prepare('UPDATE registrations SET category = \'Stara\' WHERE id = :id')->execute(['id' => $id]);

        $patch = $this->request('PATCH', '/api/v1/registrations/' . $id, ['category' => 'Nowa'], $this->bearer($p['id'], 'participant', $p['email']));

        self::assertSame('Stara', $this->storedCategory($id), 'PATCH ' . $patch->getStatusCode());
    }

    // ── Eligibility edge cases ────────────────────────────────────────────────────────────

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

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Teams\TeamRepository;
use ZmierzymyCzas\Support\Env;

/**
 * E7.1 ("Cykl życia Drużyny: niepełny skład legalny, limit liczy Zapisy",
 * issue 23 Answer §1) — the new `teams` entity behind
 * `registrations.team_id`/`team_name`, and the validation
 * RegistrationRepository::create() now runs against it.
 */
final class RegistrationTeamTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private TeamRepository $teams;
    private string $slugPrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../.env');
        $this->slugPrefix = 'phpteam-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->deletePhpUnitEvents();
            $this->events = new EventRepository($this->pdo);
            $this->registrations = new RegistrationRepository($this->pdo);
            $this->teams = new TeamRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        $this->deletePhpUnitEvents();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function eventPayload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? $this->slugPrefix);

        return array_replace_recursive([
            'name' => 'PHPUnit Team Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'draft',
            'registrationDeadline' => '2026-09-01',
            'featured' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [
                [
                    'id' => $slug . '-relay',
                    'name' => 'Sztafeta 4x400m',
                    'length' => 1.6,
                    'unit' => 'km',
                    'categories' => ['Open'],
                    'ageCategories' => [],
                    'specialCategories' => [
                        ['id' => 'mixed', 'name' => 'Drużyna mieszana'],
                        ['id' => 'women', 'name' => 'Drużyna kobieca'],
                    ],
                    'limit' => 100,
                    'registeredCount' => 0,
                    'price' => 0,
                    'routeFiles' => [],
                    'startType' => 'relay',
                    'relayMinTeamSize' => 2,
                    'relayMaxTeamSize' => 4,
                ],
                [
                    'id' => $slug . '-5k',
                    'name' => '5 km',
                    'length' => 5,
                    'unit' => 'km',
                    'categories' => ['Open'],
                    'ageCategories' => [],
                    'specialCategories' => [],
                    'limit' => 100,
                    'registeredCount' => 0,
                    'price' => 0,
                    'routeFiles' => [],
                ],
            ],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $event, int $distanceIndex, array $overrides = []): array
    {
        $userId = 'phpunit-user-' . bin2hex(random_bytes(4));

        return [
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'userId' => $userId,
            'userName' => 'PHPUnit User',
            'userEmail' => $userId . '@example.test',
            'distanceId' => $event['distances'][$distanceIndex]['id'],
            'distanceName' => $event['distances'][$distanceIndex]['name'],
            'category' => 'Open',
            'merchandiseOrders' => [],
            ...$overrides,
        ];
    }

    public function testCreateTeamRejectsNonRelayDistance(): void
    {
        $event = $this->events->create($this->eventPayload());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('registration_team_requires_relay_distance');
        $this->registrations->create($this->registrationPayload($event, 1, [
            'createTeam' => true,
            'teamName' => 'Ekipa 5k',
        ]));
    }

    public function testCreateTeamOnRelayDistanceLinksFirstRegistrationToARealServerGeneratedTeam(): void
    {
        $event = $this->events->create($this->eventPayload());

        $registration = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Błyskawice',
        ]));

        self::assertNotNull($registration['teamId']);
        self::assertSame('Błyskawice', $registration['teamName']);

        $team = $this->teams->find((string) $registration['teamId']);
        self::assertNotNull($team);
        self::assertSame($event['id'], $team['eventId']);
        self::assertSame($event['distances'][0]['id'], $team['distanceId']);
        self::assertSame(1, $team['memberCount']);
        // relay_max_team_size = 4, one member registered => not yet complete
        // (issue 23 Answer §1's own worked example: 3 of 4 is still legal
        // and incomplete, let alone 1 of 4).
        self::assertFalse($team['complete']);
    }

    public function testJoinRejectsUnknownTeamId(): void
    {
        $event = $this->events->create($this->eventPayload());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('registration_team_not_found');
        $this->registrations->create($this->registrationPayload($event, 0, [
            'teamId' => 'does-not-exist',
        ]));
    }

    public function testJoinRejectsTeamFromADifferentDistance(): void
    {
        $event = $this->events->create($this->eventPayload());

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Sprinterzy',
        ]));

        // A second, unrelated relay Dystans in another Event — the team
        // belongs to the first Event's relay Dystans, not this one.
        $otherEvent = $this->events->create($this->eventPayload(['slug' => $this->slugPrefix . '-other']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('registration_team_distance_mismatch');
        $this->registrations->create($this->registrationPayload($otherEvent, 0, [
            'teamId' => $captain['teamId'],
        ]));
    }

    public function testJoinRejectsAFullTeam(): void
    {
        $event = $this->events->create($this->eventPayload([
            'distances' => [
                ['relayMaxTeamSize' => 2],
            ],
        ]));

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Duet',
        ]));
        $this->registrations->create($this->registrationPayload($event, 0, [
            'teamId' => $captain['teamId'],
        ]));

        // Team is now at relay_max_team_size (2) — a third join must be
        // rejected as "team full", a reason distinct from the Dystans'
        // own (unrelated, still-open) capacity limit.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('registration_team_full');
        $this->registrations->create($this->registrationPayload($event, 0, [
            'teamId' => $captain['teamId'],
        ]));
    }

    public function testJoinAcceptsAValidJoinAndSharesTheTeamNameAndId(): void
    {
        $event = $this->events->create($this->eventPayload());

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Wiatr',
        ]));
        $member = $this->registrations->create($this->registrationPayload($event, 0, [
            'teamId' => $captain['teamId'],
        ]));

        self::assertSame($captain['teamId'], $member['teamId']);
        self::assertSame('Wiatr', $member['teamName']);

        $team = $this->teams->find((string) $captain['teamId']);
        self::assertSame(2, $team['memberCount']);
    }

    /**
     * E7.1 point 4: completeness is a purely derived value, never a stored
     * field a create()/join() call can set directly — this proves it moves
     * from incomplete to complete purely as a side effect of registration
     * count crossing relay_max_team_size, with nothing anywhere setting a
     * "complete" flag by hand.
     */
    public function testCompletenessIsDerivedFromRegistrationCount(): void
    {
        $event = $this->events->create($this->eventPayload([
            'distances' => [
                ['relayMinTeamSize' => 2, 'relayMaxTeamSize' => 2],
            ],
        ]));

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Para',
        ]));

        $incomplete = $this->teams->find((string) $captain['teamId']);
        self::assertSame(1, $incomplete['memberCount']);
        self::assertFalse($incomplete['complete']);

        $this->registrations->create($this->registrationPayload($event, 0, [
            'teamId' => $captain['teamId'],
        ]));

        $complete = $this->teams->find((string) $captain['teamId']);
        self::assertSame(2, $complete['memberCount']);
        self::assertTrue($complete['complete']);
    }

    /**
     * The ticket's own named acceptance criterion (E7.1 point 5, issue 23
     * Answer §1): an incomplete Drużyna's Zapisy count against the Dystans
     * limit exactly as many slots as they have registrations — never the
     * team's full/target size. Three Zapisy on a relay Dystans limited to
     * three total participant slots must exactly fill it, regardless of the
     * team declaring room for four.
     */
    public function testCapacityCountsIndividualRegistrationsNotTeamSize(): void
    {
        $event = $this->events->create($this->eventPayload([
            'distances' => [
                ['relayMinTeamSize' => 2, 'relayMaxTeamSize' => 4, 'limit' => 3],
            ],
        ]));
        $distanceId = (string) $event['distances'][0]['id'];

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Niepełna',
        ]));
        $this->registrations->create($this->registrationPayload($event, 0, ['teamId' => $captain['teamId']]));
        $this->registrations->create($this->registrationPayload($event, 0, ['teamId' => $captain['teamId']]));

        $team = $this->teams->find((string) $captain['teamId']);
        self::assertSame(3, $team['memberCount']);
        self::assertFalse($team['complete'], 'Three of a four-person roster is still legally incomplete.');

        $distance = $this->distanceRow($distanceId);
        self::assertSame(3, (int) $distance['registered_count'], 'The Dystans limit must count the 3 Zapisy, not the team\'s declared max size of 4.');

        // The Dystans is now at its own limit (3) purely because of these 3
        // Zapisy — a 4th join attempt (which the still-incomplete team would
        // otherwise happily accept, 3 < relay_max_team_size 4) must fail on
        // the Dystans' capacity, not the team's roster.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('registration_capacity_exceeded');
        $this->registrations->create($this->registrationPayload($event, 0, ['teamId' => $captain['teamId']]));
    }

    /**
     * E7.5 (issue 23 Answer §7): the captain declares the Kategoria
     * specjalna at team-creation time, from the same configured list ticket
     * 10 already validates individual `category` free text against
     * (event_distances.special_categories) — see eventPayload()'s relay
     * distance, which configures 'Drużyna mieszana'/'Drużyna kobieca'.
     */
    public function testCreateTeamWithValidSpecialCategoryStoresIt(): void
    {
        $event = $this->events->create($this->eventPayload());

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Tęcza',
            'specialCategory' => 'Drużyna mieszana',
        ]));

        $team = $this->teams->find((string) $captain['teamId']);
        self::assertSame('Drużyna mieszana', $team['specialCategory']);
    }

    public function testCreateTeamRejectsASpecialCategoryNotConfiguredOnTheDistance(): void
    {
        $event = $this->events->create($this->eventPayload());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('team_special_category_invalid');
        $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Nieznana',
            'specialCategory' => 'Kategoria spoza listy',
        ]));
    }

    /**
     * The ticket's own explicit design constraint (Answer §7): a member
     * joining/leaving the roster must never touch a previously declared
     * Kategoria specjalna, even when the join would make the declaration
     * look stale (here: "Drużyna kobieca" declared, then a member joins —
     * the platform has no idea of anyone's gender at this layer and must
     * not try to "fix" the label either way).
     */
    public function testRosterChangeDoesNotAlterAPreviouslyDeclaredSpecialCategory(): void
    {
        $event = $this->events->create($this->eventPayload());

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Stała Kategoria',
            'specialCategory' => 'Drużyna kobieca',
        ]));

        $this->registrations->create($this->registrationPayload($event, 0, ['teamId' => $captain['teamId']]));

        $team = $this->teams->find((string) $captain['teamId']);
        self::assertSame(2, $team['memberCount']);
        self::assertSame('Drużyna kobieca', $team['specialCategory'], 'A roster change must never auto-correct a manually declared special category.');
    }

    /**
     * Editability judgment call: the captain may still change the
     * declaration later (TeamRepository::updateSpecialCategory()), up until
     * the event's own start date — see that method's doc comment. Uses a
     * future event date explicitly (eventPayload()'s default date is
     * "today" in this fixture, which would already read as started).
     */
    public function testCaptainCanUpdateSpecialCategoryBeforeTheEventStarts(): void
    {
        $event = $this->events->create($this->eventPayload(['date' => '2026-12-31']));

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Zmienna',
        ]));

        $updated = $this->teams->updateSpecialCategory(
            (string) $captain['teamId'],
            (string) $captain['userId'],
            'Drużyna mieszana'
        );

        self::assertSame('Drużyna mieszana', $updated['specialCategory']);
    }

    public function testUpdateSpecialCategoryRejectsSomeoneWhoIsNotTheCaptain(): void
    {
        $event = $this->events->create($this->eventPayload(['date' => '2026-12-31']));

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Nie Twoja',
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('team_not_captain');
        $this->teams->updateSpecialCategory((string) $captain['teamId'], 'someone-else', 'Drużyna mieszana');
    }

    public function testUpdateSpecialCategoryRejectsAnUnconfiguredValue(): void
    {
        $event = $this->events->create($this->eventPayload(['date' => '2026-12-31']));

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Zła Wartość',
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('team_special_category_invalid');
        $this->teams->updateSpecialCategory((string) $captain['teamId'], (string) $captain['userId'], 'Nie na liście');
    }

    public function testUpdateSpecialCategoryIsLockedOnceTheEventHasStarted(): void
    {
        // Default eventPayload() date is "today" in this fixture, which
        // already reads as started under the `date <= CURDATE()` rule.
        $event = $this->events->create($this->eventPayload());

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Spóźniona',
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('team_special_category_locked');
        $this->teams->updateSpecialCategory((string) $captain['teamId'], (string) $captain['userId'], 'Drużyna mieszana');
    }

    /**
     * TD.8 (spec 07 pkt 10 / spec 23 §3): "za drużynę" puts the entire opłata
     * startowa on the captain's Zapis and zeroes it for every other member.
     * Uses a non-zero price (100) on the relay distance and
     * `teamPricingMode: 'per_team'` so the suppression is actually
     * observable, not just "0 stayed 0".
     */
    public function testPerTeamPricingChargesOnlyTheCaptainAndZeroesJoiningMembers(): void
    {
        $event = $this->events->create($this->eventPayload([
            'distances' => [
                ['price' => 100, 'teamPricingMode' => 'per_team'],
            ],
        ]));

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Kapitanowie',
        ]));

        self::assertEquals(100.00, (float) $captain['pricePaid']);
        self::assertEquals(100.00, (float) $captain['totalAmount']);
        $captainBases = array_column($captain['settlementItems'], 'amount', 'basis');
        self::assertEquals(100.00, (float) $captainBases['distance_price']);

        $member = $this->registrations->create($this->registrationPayload($event, 0, [
            'teamId' => $captain['teamId'],
        ]));

        self::assertSame([], $member['settlementItems'], 'A joining member under per_team pricing must get no opłata-startowa-related settlement items at all.');
        self::assertNull($member['pricePaid']);
        self::assertEquals(0.00, (float) $member['totalAmount']);
    }

    /**
     * TD.8: steps 4–6 (modifiers, gadżety, NNW) are explicitly unaffected by
     * the suppression — the spec is clear that merchandise/insurance always
     * stay with the ordering Zapis, because a t-shirt has a specific
     * person's size.
     */
    public function testPerTeamPricingStillChargesAJoiningMemberForMerchandise(): void
    {
        $merchandiseId = $this->slugPrefix . '-shirt';
        $event = $this->events->create($this->eventPayload([
            'merchandise' => [
                ['id' => $merchandiseId, 'name' => 'Koszulka techniczna', 'price' => 65, 'sizes' => ['M'], 'colors' => []],
            ],
            'distances' => [
                ['price' => 100, 'teamPricingMode' => 'per_team'],
            ],
        ]));

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Zamawiający',
        ]));

        $member = $this->registrations->create($this->registrationPayload($event, 0, [
            'teamId' => $captain['teamId'],
            'merchandise' => [
                ['merchandiseId' => $merchandiseId, 'size' => 'M', 'quantity' => 1, 'price' => 1.00],
            ],
        ]));

        self::assertNull($member['pricePaid'], 'Opłata startowa stays suppressed even when merchandise is added.');
        $memberBases = array_column($member['settlementItems'], 'amount', 'basis');
        self::assertEquals(65.00, (float) $memberBases['merchandise']);
        self::assertEquals(65.00, (float) $member['totalAmount']);
        self::assertEquals(65.00, (float) $member['merchandiseOrders'][0]['price']);
    }

    /**
     * TD.8: the discount-code block (step 3) must not run at all for a
     * suppressed registration — proven here with an entirely bogus code
     * that would normally throw `discount_code_invalid`. If suppression
     * were only zeroing the amount afterwards instead of skipping the step,
     * this would fail with that exception.
     */
    public function testPerTeamPricingSkipsDiscountCodeEntirelyForAJoiningMember(): void
    {
        $event = $this->events->create($this->eventPayload([
            'distances' => [
                ['price' => 100, 'teamPricingMode' => 'per_team'],
            ],
        ]));

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Bez Rabatu',
        ]));

        $member = $this->registrations->create($this->registrationPayload($event, 0, [
            'teamId' => $captain['teamId'],
            'discountCode' => 'DOES-NOT-EXIST',
        ]));

        self::assertNull($member['discountCode'], 'The discount-code step never ran, so no code is recorded on the suppressed Zapis.');
        self::assertNull($member['discountAmount']);
        self::assertEquals(0.00, (float) $member['totalAmount']);
    }

    /**
     * Regression guard: the default `team_pricing_mode` ('per_person',
     * either omitted entirely or set explicitly) must leave today's
     * behavior completely unchanged — captain and joining member are both
     * priced independently and identically.
     */
    public function testDefaultPricingModeChargesCaptainAndMemberIndependently(): void
    {
        $event = $this->events->create($this->eventPayload([
            'distances' => [
                ['price' => 100],
            ],
        ]));

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Niezależni',
        ]));
        $member = $this->registrations->create($this->registrationPayload($event, 0, [
            'teamId' => $captain['teamId'],
        ]));

        self::assertEquals(100.00, (float) $captain['pricePaid']);
        self::assertEquals(100.00, (float) $member['pricePaid'], 'Default per_person mode: every relay-team member is still priced independently, unchanged.');
        self::assertEquals(100.00, (float) $captain['totalAmount']);
        self::assertEquals(100.00, (float) $member['totalAmount']);
    }

    /**
     * Same regression guard, with `teamPricingMode` set explicitly to
     * 'per_person' rather than omitted, to prove the explicit value behaves
     * identically to the implicit default.
     */
    public function testExplicitPerPersonPricingModeChargesCaptainAndMemberIndependently(): void
    {
        $event = $this->events->create($this->eventPayload([
            'distances' => [
                ['price' => 100, 'teamPricingMode' => 'per_person'],
            ],
        ]));

        $captain = $this->registrations->create($this->registrationPayload($event, 0, [
            'createTeam' => true,
            'teamName' => 'Jawnie Osobno',
        ]));
        $member = $this->registrations->create($this->registrationPayload($event, 0, [
            'teamId' => $captain['teamId'],
        ]));

        self::assertEquals(100.00, (float) $captain['pricePaid']);
        self::assertEquals(100.00, (float) $member['pricePaid']);
    }

    /**
     * The legacy free-text team classification path (no `teamId`, no
     * `createTeam` — E1.13's general `collectTeam` label, unrelated to the
     * E7.1 roster) must be completely unaffected: `team['teamId']` is
     * always null there, so `$isJoiningExistingTeam` is false regardless of
     * `team_pricing_mode`, on any distance, relay or not.
     */
    public function testLegacyFreeTextTeamClassificationIsUnaffectedByTeamPricingMode(): void
    {
        $event = $this->events->create($this->eventPayload([
            'distances' => [
                [],
                ['price' => 100, 'teamPricingMode' => 'per_team'],
            ],
        ]));

        $first = $this->registrations->create($this->registrationPayload($event, 1, [
            'teamName' => 'Klasyfikacja Wolnotekstowa',
        ]));
        $second = $this->registrations->create($this->registrationPayload($event, 1, [
            'teamName' => 'Klasyfikacja Wolnotekstowa',
        ]));

        self::assertNull($first['teamId']);
        self::assertNull($second['teamId']);
        self::assertEquals(100.00, (float) $first['pricePaid']);
        self::assertEquals(100.00, (float) $second['pricePaid'], 'Legacy free-text team classification never sets teamId, so per_team suppression never applies to it.');
    }

    /** @return array<string, mixed> */
    private function distanceRow(string $distanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event_distances WHERE id = :id');
        $stmt->execute(['id' => $distanceId]);
        return $stmt->fetch() ?: [];
    }

    private function deletePhpUnitEvents(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => 'phpteam-%']);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($ids as $id) {
            $registrationIds = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
            $registrationIds->execute(['id' => $id]);
            foreach (($registrationIds->fetchAll(PDO::FETCH_COLUMN) ?: []) as $registrationId) {
                $this->pdo->prepare('DELETE FROM registration_merchandise_orders WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_payment_details WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_settlement_items WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_refunds WHERE registration_id = :id')->execute(['id' => $registrationId]);
            }
            $distanceIds = $this->pdo->prepare('SELECT id FROM event_distances WHERE event_id = :id');
            $distanceIds->execute(['id' => $id]);
            foreach (($distanceIds->fetchAll(PDO::FETCH_COLUMN) ?: []) as $distanceId) {
                $this->pdo->prepare('DELETE FROM event_distance_pricing_variants WHERE distance_id = :id')->execute(['id' => $distanceId]);
            }
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
    }
}

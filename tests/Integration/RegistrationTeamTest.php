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

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;

final class RegistrationRepositoryTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private string $slugPrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->slugPrefix = 'phpreg-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->deletePhpUnitEvents();
            $this->events = new EventRepository($this->pdo);
            $this->registrations = new RegistrationRepository($this->pdo);
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

    public function testCreateAppliesFixedDecimalDiscountAndTracksUsage(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-fixed',
            'discountCodes' => [
                [
                    'id' => 'phpunit-fixed-code',
                    'code' => 'SAVE2550',
                    'discountType' => 'fixed',
                    'discountValue' => 25.50,
                    'usedCount' => 0,
                    'distanceIds' => [],
                    'active' => true,
                ],
            ],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, 'SAVE2550'));

        self::assertSame('SAVE2550', $registration['discountCode']);
        self::assertEquals(25.50, (float) $registration['discountAmount']);
        self::assertEquals(54.50, (float) $registration['pricePaid']);

        $code = $this->rawDiscountCode('phpunit-fixed-code');
        self::assertSame(1, (int) $code['used_count']);
        $usedBy = json_decode((string) $code['used_by'], true);
        self::assertSame($registration['id'], $usedBy[0]['registrationId']);
        self::assertSame('10 km', $usedBy[0]['distanceName']);
    }

    public function testFixedDiscountHigherThanDistancePriceCreatesZeroStartFee(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-free',
            'discountCodes' => [
                [
                    'id' => 'phpunit-free-code',
                    'code' => 'FREE200',
                    'discountType' => 'fixed',
                    'discountValue' => 200,
                    'usedCount' => 0,
                    'distanceIds' => [],
                    'active' => true,
                ],
            ],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, 'FREE200'));

        self::assertEquals(80.00, (float) $registration['discountAmount']);
        self::assertEquals(0.00, (float) $registration['pricePaid']);
    }

    public function testRejectsDiscountForDifferentDistance(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-distance',
            'discountCodes' => [
                [
                    'id' => 'phpunit-distance-code',
                    'code' => 'ONLY5K',
                    'discountType' => 'fixed',
                    'discountValue' => 10,
                    'usedCount' => 0,
                    'distanceIds' => [$this->slugPrefix . '-distance-5k'],
                    'active' => true,
                ],
            ],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('discount_code_distance_mismatch');

        $this->registrations->create($this->registrationPayload($event, 'ONLY5K'));
    }

    public function testRejectsDiscountAfterMaxUses(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-limit',
            'discountCodes' => [
                [
                    'id' => 'phpunit-limit-code',
                    'code' => 'ONCE',
                    'discountType' => 'percent',
                    'discountValue' => 50,
                    'maxUses' => 1,
                    'usedCount' => 0,
                    'distanceIds' => [],
                    'active' => true,
                ],
            ],
        ]));

        $this->registrations->create($this->registrationPayload($event, 'ONCE', 'user-one'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('discount_code_used_up');

        $this->registrations->create($this->registrationPayload($event, 'ONCE', 'user-two'));
    }

    public function testTicketsByUserDerivesActiveStatusFromRegistrationBeforeCheckIn(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-ticket-active']));
        $registration = $this->registrations->create($this->registrationPayload($event, '', 'phpunit-ticket-user'));

        $tickets = $this->registrations->ticketsByUser('phpunit-ticket-user');

        self::assertCount(1, $tickets);
        $ticket = $tickets[0];
        self::assertSame($registration['id'], $ticket['id']);
        self::assertSame($registration['id'], $ticket['registrationId']);
        self::assertSame('active', $ticket['status']);
        self::assertNull($ticket['checkedInAt']);
    }

    public function testCheckInRecordsTimestampAndTicketBecomesUsed(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-ticket-used']));
        $registration = $this->registrations->create($this->registrationPayload($event, '', 'phpunit-ticket-checkin-user'));

        self::assertNull($registration['checkedInAt']);

        $updated = $this->registrations->checkIn($registration['id']);

        self::assertNotNull($updated['checkedInAt']);

        $tickets = $this->registrations->ticketsByUser('phpunit-ticket-checkin-user');
        self::assertSame('used', $tickets[0]['status']);
        self::assertSame($updated['checkedInAt'], $tickets[0]['checkedInAt']);
    }

    public function testTicketForCancelledRegistrationIsCancelledEvenIfCheckedIn(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-ticket-cancel']));
        $registration = $this->registrations->create($this->registrationPayload($event, '', 'phpunit-ticket-cancel-user'));
        $this->registrations->checkIn($registration['id']);
        $this->registrations->cancel($registration['id']);

        $tickets = $this->registrations->ticketsByUser('phpunit-ticket-cancel-user');

        self::assertSame('cancelled', $tickets[0]['status']);
    }

    /**
     * E1.11: every Zapis gets a `pqr_`-prefixed, 48-hex-char start code automatically —
     * no separate "generate codes" step, no null. Persisted (not just computed at read
     * time, unlike E1.8's transferTitle) — find() must return the same code create() did.
     */
    public function testCreateGeneratesPersistedStartCode(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-startcode']));
        $registration = $this->registrations->create($this->registrationPayload($event, '', 'phpunit-startcode-user'));

        self::assertMatchesRegularExpression('/^pqr_[a-f0-9]{48}$/', (string) $registration['qrCode']);

        $found = $this->registrations->find($registration['id']);
        self::assertSame($registration['qrCode'], $found['qrCode']);
    }

    /** Two Zapisy never collide on the same start code — each gets its own random token. */
    public function testCreateGeneratesDistinctStartCodesPerRegistration(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-startcode-distinct']));
        $first = $this->registrations->create($this->registrationPayload($event, '', 'phpunit-startcode-user-1'));
        $second = $this->registrations->create($this->registrationPayload($event, '', 'phpunit-startcode-user-2'));

        self::assertNotSame($first['qrCode'], $second['qrCode']);
    }

    /**
     * E1.11: distance change (E1.8/E1.2 territory — same Zapis, just a different Dystans)
     * must NOT rotate the start code — only a reassignment of the Zapis to a different
     * person would (no such mechanism exists in this codebase yet, so nothing to test for
     * rotation here — see the ticket's own note). `update()` doesn't expose a distance
     * change today, so this asserts the weaker but still meaningful guarantee: an
     * unrelated status update on the same Zapis leaves the start code untouched.
     */
    public function testUnrelatedUpdateDoesNotRotateStartCode(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-startcode-stable']));
        $registration = $this->registrations->create($this->registrationPayload($event, '', 'phpunit-startcode-stable-user'));

        $updated = $this->registrations->update($registration['id'], ['bibNumber' => '101']);

        self::assertSame($registration['qrCode'], $updated['qrCode']);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? $this->slugPrefix);
        return array_replace_recursive([
            'name' => 'PHPUnit Registration Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-06-15',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'draft',
            'registrationDeadline' => '2026-06-01',
            'featured' => false,
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
                    'price' => 50,
                    'routeFiles' => [],
                ],
            ],
        ], $overrides);
    }

    /**
     * E0.3: a Próg (price tier) carries at most two independent, optional
     * limits — endDate and participantLimit — and expires on whichever is
     * hit first ("co nastąpi wcześniej"), with no XOR mode flag choosing
     * between them. These cover RegistrationRepository::currentDistancePrice
     * for: only end-date set, only participant-limit set, both set with the
     * date hit first, both set with the count hit first, and neither set.
     */
    public function testTierWithOnlyEndDateAppliesWhileNotYetExpired(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-tier-date-active',
            'distances' => [
                [
                    'price' => 80,
                    'registeredCount' => 0,
                    'priceTiers' => [
                        ['endDate' => '2999-01-01', 'price' => 60],
                    ],
                ],
            ],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, ''));

        self::assertEquals(60.00, (float) $registration['pricePaid']);
    }

    public function testTierWithOnlyEndDateExpiresAfterDatePasses(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-tier-date-expired',
            'distances' => [
                [
                    'price' => 80,
                    'registeredCount' => 0,
                    'priceTiers' => [
                        ['endDate' => '2000-01-01', 'price' => 60],
                    ],
                ],
            ],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, ''));

        self::assertEquals(80.00, (float) $registration['pricePaid']);
    }

    public function testTierWithOnlyParticipantLimitAppliesUnderLimit(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-tier-count-active',
            'distances' => [
                [
                    'price' => 80,
                    'registeredCount' => 4,
                    'priceTiers' => [
                        ['participantLimit' => 5, 'price' => 60],
                    ],
                ],
            ],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, ''));

        self::assertEquals(60.00, (float) $registration['pricePaid']);
    }

    public function testTierWithOnlyParticipantLimitExpiresAtLimit(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-tier-count-expired',
            'distances' => [
                [
                    'price' => 80,
                    'registeredCount' => 5,
                    'priceTiers' => [
                        ['participantLimit' => 5, 'price' => 60],
                    ],
                ],
            ],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, ''));

        self::assertEquals(80.00, (float) $registration['pricePaid']);
    }

    public function testTierWithBothLimitsExpiresWhenDateIsHitFirst(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-both-datefirst',
            'distances' => [
                [
                    'price' => 80,
                    'registeredCount' => 1,
                    'priceTiers' => [
                        ['endDate' => '2000-01-01', 'participantLimit' => 5, 'price' => 60],
                    ],
                ],
            ],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, ''));

        self::assertEquals(80.00, (float) $registration['pricePaid']);
    }

    public function testTierWithBothLimitsExpiresWhenCountIsHitFirst(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-both-cntfirst',
            'distances' => [
                [
                    'price' => 80,
                    'registeredCount' => 5,
                    'priceTiers' => [
                        ['endDate' => '2999-01-01', 'participantLimit' => 5, 'price' => 60],
                    ],
                ],
            ],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, ''));

        self::assertEquals(80.00, (float) $registration['pricePaid']);
    }

    public function testTierWithBothLimitsAppliesWhenNeitherIsHit(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-tier-both-active',
            'distances' => [
                [
                    'price' => 80,
                    'registeredCount' => 1,
                    'priceTiers' => [
                        ['endDate' => '2999-01-01', 'participantLimit' => 5, 'price' => 60],
                    ],
                ],
            ],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, ''));

        self::assertEquals(60.00, (float) $registration['pricePaid']);
    }

    public function testTierWithNeitherLimitSetNeverExpires(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-tier-neither',
            'distances' => [
                [
                    'price' => 80,
                    // E1.3: this fixture's registeredCount (999) is deliberately far
                    // above payload()'s default distance limit (100) to prove the
                    // *tier* has no limit — that would now collide with the new
                    // capacity check (a real distance limit of 100) if left in
                    // place, so this test explicitly opts the distance itself out
                    // of a capacity limit; capacity behavior has its own tests below.
                    'limit' => null,
                    'registeredCount' => 999,
                    'priceTiers' => [
                        ['price' => 60],
                    ],
                ],
            ],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, ''));

        self::assertEquals(60.00, (float) $registration['pricePaid']);
    }

    /**
     * E1.3: the materialized `event_distances.registered_count` column advances
     * transactionally on create() — this is the write side of the same counter
     * the price-tier tests above already exercise on the read side (a seeded
     * starting value). Two Zapisy on the same Dystans must leave the counter at
     * exactly 2, and — since it now genuinely reflects reality — the third Zapis
     * must see itself as the 3rd registration for its own tier resolution.
     */
    public function testCreateIncrementsMaterializedRegisteredCount(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-counter-increments',
            'distances' => [
                ['id' => 'd-counter-incr-1', 'price' => 80, 'limit' => 10, 'registeredCount' => 0],
                ['id' => 'd-counter-incr-2'],
            ],
        ]));

        $this->registrations->create($this->registrationPayload($event, '', 'phpunit-counter-user-1'));
        self::assertSame(1, $this->rawRegisteredCount($event['distances'][0]['id']));

        $this->registrations->create($this->registrationPayload($event, '', 'phpunit-counter-user-2'));
        self::assertSame(2, $this->rawRegisteredCount($event['distances'][0]['id']));
    }

    /**
     * E1.3: `registered_count` feeds price-tier resolution (rozstrzygnięcie 4 in
     * 07-model-cenowy-zapisu.md — the tier's participantLimit counts the same
     * population as the seat limit) directly from actual create() calls, not a
     * value manually seeded into the fixture the way the tier tests above do.
     * With `participantLimit: 1` on the tier, the first Zapis must still get the
     * discounted tier price (0 prior registrations < 1) and the second must fall
     * back to the base price (1 prior registration >= 1) — proving the counter
     * this ticket maintains, not a live COUNT query, is what tier resolution
     * actually reads (RegistrationRepository::pricingForRegistration reads the
     * locked row's column, never re-counts).
     */
    public function testPriceTierThresholdSelectionUsesMaterializedCounterAcrossRealCreates(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-tier-uses-counter',
            'distances' => [
                [
                    'id' => 'd-tier-counter-1',
                    'price' => 80,
                    'registeredCount' => 0,
                    'priceTiers' => [
                        ['participantLimit' => 1, 'price' => 60],
                    ],
                ],
                ['id' => 'd-tier-counter-2'],
            ],
        ]));

        $first = $this->registrations->create($this->registrationPayload($event, '', 'phpunit-tier-counter-1'));
        self::assertEquals(60.00, (float) $first['pricePaid']);

        $second = $this->registrations->create($this->registrationPayload($event, '', 'phpunit-tier-counter-2'));
        self::assertEquals(80.00, (float) $second['pricePaid']);
    }

    /**
     * E1.3: the seat-capacity limit (rozstrzygnięcie: 07-model-cenowy-zapisu.md,
     * "limit miejsc na Dystansie") is enforced transactionally on create() — once
     * `registered_count` reaches `participant_limit`, the next Zapis must be
     * rejected with `registration_capacity_exceeded` rather than oversold, and
     * the counter must not have advanced past the limit.
     */
    public function testCreateRejectsRegistrationOnceDistanceIsAtCapacity(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-capacity-full',
            'distances' => [
                ['id' => 'd-capacity-full-1', 'price' => 80, 'limit' => 1, 'registeredCount' => 0],
                ['id' => 'd-capacity-full-2'],
            ],
        ]));
        $distanceId = (string) $event['distances'][0]['id'];

        $this->registrations->create($this->registrationPayload($event, '', 'phpunit-capacity-user-1'));
        self::assertSame(1, $this->rawRegisteredCount($distanceId));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('registration_capacity_exceeded');

        try {
            $this->registrations->create($this->registrationPayload($event, '', 'phpunit-capacity-user-2'));
        } finally {
            // The rejected attempt must not have moved the counter at all — the
            // whole create() (including the increment) rolls back together.
            self::assertSame(1, $this->rawRegisteredCount($distanceId));
        }
    }

    /**
     * E1.3's core concern (rozstrzygnięcie: issue 21, "opóźnienie przy równoczesnych
     * zapisach realnie sprzedaje więcej miejsc niż istnieje"): two overlapping
     * transactions for the same Dystans must not both read the pre-increment
     * count and both succeed. Simulated here with a second, independent PDO
     * connection: connection A locks the Dystans row (the same `SELECT ... FOR
     * UPDATE` create() takes) and holds it open, uninterrupted, while connection
     * B — under a short `innodb_lock_wait_timeout` — attempts a real create() on
     * the same distance. B must block on A's lock rather than racing past it, so
     * it must fail with a lock-wait timeout, never with a successful, silently
     * oversold registration.
     */
    public function testConcurrentCreatesForSameDistanceSerializeOnTheRowLock(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-concurrency',
            'distances' => [
                ['id' => 'd-concurrency-1', 'price' => 80, 'limit' => 5, 'registeredCount' => 0],
                ['id' => 'd-concurrency-2'],
            ],
        ]));
        $distanceId = (string) $event['distances'][0]['id'];

        $connectionA = Connection::fromEnv();
        $connectionA->beginTransaction();
        $connectionA->prepare('SELECT registered_count FROM event_distances WHERE id = :id FOR UPDATE')
            ->execute(['id' => $distanceId]);

        $connectionB = Connection::fromEnv();
        $connectionB->exec('SET innodb_lock_wait_timeout = 1');
        $registrationsB = new RegistrationRepository($connectionB);

        try {
            $registrationsB->create($this->registrationPayload($event, '', 'phpunit-concurrency-user-b'));
            self::fail('Expected connection B to block on connection A\'s row lock and time out.');
        } catch (\PDOException $e) {
            self::assertStringContainsStringIgnoringCase('lock wait timeout', $e->getMessage());
        } finally {
            $connectionA->rollBack();
        }

        // Connection A never committed an increment, and B's blocked attempt
        // never got past the lock to run its own — the counter is untouched.
        self::assertSame(0, $this->rawRegisteredCount($distanceId));
    }

    private function rawRegisteredCount(string $distanceId): int
    {
        $stmt = $this->pdo->prepare('SELECT registered_count FROM event_distances WHERE id = :id');
        $stmt->execute(['id' => $distanceId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{status: string, paymentExpiresAt: ?string} */
    private function rawRegistration(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT status, payment_expires_at AS paymentExpiresAt FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return ['status' => (string) $row['status'], 'paymentExpiresAt' => $row['paymentExpiresAt']];
    }

    /** Simulates time having passed, the same way the tier-expiry tests above use a past `endDate`. */
    private function backdatePaymentExpiry(string $id, string $when): void
    {
        $this->pdo->prepare('UPDATE registrations SET payment_expires_at = :when WHERE id = :id')
            ->execute(['when' => $when, 'id' => $id]);
    }

    /**
     * E1.4: a brand-new pending Zapis freezes its payment-window deadline at
     * creation, from the event's configured gateway window (docs/20 pkt 1 default
     * 30-60 min — this repo's pragmatic default is 45, from EventRepository).
     * Asserted with a tolerance window rather than an exact timestamp since both
     * "now" calls (test and repository) can't literally coincide.
     */
    public function testCreateFreezesPaymentExpiryUsingEventsGatewayWindowDefault(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-exp-def',
            'paymentConfig' => ['type' => 'admin_gateway'],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, ''));

        self::assertSame('pending', $registration['status']);
        $expiresAt = new \DateTimeImmutable((string) $this->rawRegistration($registration['id'])['paymentExpiresAt']);
        $expectedAt = new \DateTimeImmutable('+45 minutes');
        self::assertEqualsWithDelta($expectedAt->getTimestamp(), $expiresAt->getTimestamp(), 30);
    }

    /**
     * "Organizator może zmienić obie wartości domyślne per Wydarzenie" — a Zapis on
     * an event with a custom gatewayWindowMinutes freezes against *that* value, not
     * the hardcoded default.
     */
    public function testCreateFreezesPaymentExpiryUsingOrganizerConfiguredGatewayWindow(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-exp-cst',
            'paymentConfig' => ['type' => 'admin_gateway', 'gatewayWindowMinutes' => 10, 'transferWindowDays' => 5],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, ''));

        $expiresAt = new \DateTimeImmutable((string) $this->rawRegistration($registration['id'])['paymentExpiresAt']);
        $expectedAt = new \DateTimeImmutable('+10 minutes');
        self::assertEqualsWithDelta($expectedAt->getTimestamp(), $expiresAt->getTimestamp(), 30);
    }

    /**
     * A Zapis created already paid (startFeePaid true, born 'confirmed' — the
     * existing create() behavior from before this ticket) has nothing to expire:
     * it never gets a payment-window deadline at all, so the lazy check can never
     * touch it.
     */
    public function testConfirmedRegistrationAtCreationGetsNoPaymentExpiry(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-exp-cfm',
        ]));

        $payload = $this->registrationPayload($event, '');
        $payload['paymentDetails'] = ['startFeePaid' => true];
        $registration = $this->registrations->create($payload);

        self::assertSame('confirmed', $registration['status']);
        self::assertNull($this->rawRegistration($registration['id'])['paymentExpiresAt']);
    }

    /**
     * The core of E1.4: once a pending Zapis's frozen deadline has passed, the next
     * read (find(), same as any other read path) lazily flips it to `expired` and
     * releases the Dystans slot the same way a manual cancel does (E1.3) — the
     * ticket's "wygaśnięcie natychmiast odblokowuje miejsce" guarantee.
     */
    public function testLazyReadExpiresPastDeadlineRegistrationAndReleasesSlot(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-expiry-lazy',
            'distances' => [
                ['id' => 'd-expiry-lazy-1', 'price' => 80, 'limit' => 10, 'registeredCount' => 0],
                ['id' => 'd-expiry-lazy-2'],
            ],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, ''));
        self::assertSame(1, $this->rawRegisteredCount('d-expiry-lazy-1'));

        $this->backdatePaymentExpiry($registration['id'], (new \DateTimeImmutable('-1 minute'))->format(DATE_ATOM));

        $reloaded = $this->registrations->find($registration['id']);
        self::assertSame('expired', $reloaded['status']);
        self::assertSame(0, $this->rawRegisteredCount('d-expiry-lazy-1'));
    }

    /**
     * Running the lazy check more than once (e.g. two read paths hit back to back,
     * or the same registration already flipped by an earlier read) must not
     * decrement the counter a second time — expireRegistration()'s re-check under
     * the row lock (status still 'pending') is what guards this.
     */
    public function testExpiryDoesNotDoubleDecrementOnRepeatedLazyChecks(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-expiry-no-double-decrement',
            'distances' => [
                ['id' => 'd-expiry-double-1', 'price' => 80, 'limit' => 10, 'registeredCount' => 0],
                ['id' => 'd-expiry-double-2'],
            ],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, ''));
        $this->backdatePaymentExpiry($registration['id'], (new \DateTimeImmutable('-1 minute'))->format(DATE_ATOM));

        // First read expires it and decrements once.
        $this->registrations->find($registration['id']);
        self::assertSame(0, $this->rawRegisteredCount('d-expiry-double-1'));
        self::assertSame('expired', $this->rawRegistration($registration['id'])['status']);

        // Further reads (list/byEvent/find again) must all be no-ops for this row.
        $this->registrations->find($registration['id']);
        $this->registrations->byEvent((string) $event['id']);
        $this->registrations->list(['eventId' => $event['id']]);
        self::assertSame(0, $this->rawRegisteredCount('d-expiry-double-1'));
    }

    /**
     * The expiry check runs inside create() itself (not just on reads) so a slot an
     * expired Zapis is still silently holding is freed before the *next*
     * registrant's capacity check — otherwise "Zapis wygasa i zwalnia slot" would
     * only be true after someone happened to read the list first.
     */
    public function testCreateExpiresStaleRegistrationBeforeCheckingCapacityForNewOne(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-expiry-frees-capacity-on-create',
            'distances' => [
                ['id' => 'd-expiry-capacity-1', 'price' => 80, 'limit' => 1, 'registeredCount' => 0],
                ['id' => 'd-expiry-capacity-2'],
            ],
        ]));

        $first = $this->registrations->create($this->registrationPayload($event, '', 'phpunit-expiry-capacity-1'));
        $this->backdatePaymentExpiry($first['id'], (new \DateTimeImmutable('-1 minute'))->format(DATE_ATOM));

        // Distance is at its limit of 1, but the sole occupant's window has passed —
        // a naive capacity check would reject this, but create() must lazily expire
        // the stale Zapis first and let the new one through.
        $second = $this->registrations->create($this->registrationPayload($event, '', 'phpunit-expiry-capacity-2'));

        self::assertSame('pending', $second['status']);
        self::assertSame('expired', $this->rawRegistration($first['id'])['status']);
        self::assertSame(1, $this->rawRegisteredCount('d-expiry-capacity-1'));
    }

    /**
     * E1.2: Wariant opłaty carries its own base price and its own Próg — separate
     * from the legacy Dystans price/tiers path exercised by the tests above. The
     * pricing variant's tier is displayed as a line relative to its own base
     * (rozstrzygnięcie: spec worked example), not merged into a single number.
     */
    public function testPricingVariantWithOwnTierAndDiscountCodeComputesOplataStartowa(): void
    {
        $variantId = $this->slugPrefix . '-variant-standard';
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-variant-tier-discount',
            'discountCodes' => [
                [
                    'id' => 'phpunit-szutry25-' . $this->slugPrefix,
                    'code' => 'SZUTRY25',
                    'discountType' => 'percent',
                    'discountValue' => 14,
                    'usedCount' => 0,
                    'distanceIds' => [],
                    'active' => true,
                ],
            ],
            'distances' => [
                [
                    // Short, non-slug-derived id: event_distances.id is VARCHAR(36)
                    // and this test's slug is already long enough that the default
                    // payload()-generated id (slug + '-10k') would overflow it.
                    'id' => 'd-variant-tier-1',
                    // Legacy price/tiers deliberately left at the default (80, no
                    // tiers) to prove the Wariant path is what actually gets used
                    // once pricingVariantId is given — not a fallback to these.
                    'pricingVariants' => [
                        [
                            'id' => $variantId,
                            'name' => 'STANDARDOWA',
                            'price' => 110,
                            'priceTiers' => [
                                ['name' => 'Early bird', 'endDate' => '2999-01-01', 'price' => 50],
                            ],
                        ],
                    ],
                ],
                // payload()'s default has a second (5 km) distance merged in by index;
                // it needs its own short id for the same VARCHAR(36) reason as above.
                ['id' => 'd-variant-tier-2'],
            ],
        ]));

        $reloaded = $this->events->find($event['id']);
        self::assertSame($variantId, $reloaded['distances'][0]['pricingVariants'][0]['id']);
        self::assertSame(50.0, (float) $reloaded['distances'][0]['pricingVariants'][0]['priceTiers'][0]['price']);

        $registration = $this->registrations->create([
            ...$this->registrationPayload($event, 'SZUTRY25'),
            'pricingVariantId' => $variantId,
        ]);

        // 110,00 (Wariant) − 60,00 (próg → 50,00) − 7,00 (14% kodu od 50,00) = 43,00
        self::assertEquals(43.00, (float) $registration['pricePaid']);
        self::assertEquals(7.00, (float) $registration['discountAmount']);

        $bases = array_column($registration['settlementItems'], 'amount', 'basis');
        self::assertEquals(110.00, (float) $bases['distance_price']);
        self::assertEquals(-60.00, (float) $bases['price_tier']);
        self::assertEquals(-7.00, (float) $bases['discount_code']);
    }

    public function testUnknownPricingVariantIdIsRejected(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-variant-invalid']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pricing_variant_invalid');

        $this->registrations->create([
            ...$this->registrationPayload($event, ''),
            'pricingVariantId' => 'does-not-exist',
        ]);
    }

    /**
     * E1.2 krok 5: cena × ilość liczy się zawsze z katalogu (`event_merchandise`),
     * niezależnie od tego, co (jeśli cokolwiek) klient wyśle w polu 'price' —
     * "klient nigdy nie przysyła kwot".
     */
    public function testMerchandiseSettlementPriceComesFromCatalogNotClient(): void
    {
        $merchandiseId = $this->slugPrefix . '-shirt';
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-merchandise',
            'merchandise' => [
                ['id' => $merchandiseId, 'name' => 'Koszulka techniczna', 'price' => 65, 'sizes' => ['M'], 'colors' => []],
            ],
        ]));

        $registration = $this->registrations->create([
            ...$this->registrationPayload($event, ''),
            'merchandise' => [
                ['merchandiseId' => $merchandiseId, 'size' => 'M', 'quantity' => 1, 'price' => 1.00],
            ],
        ]);

        $bases = array_column($registration['settlementItems'], 'amount', 'basis');
        self::assertEquals(65.00, (float) $bases['merchandise']);
        self::assertEquals(80.00 + 65.00, (float) $registration['totalAmount']);
        self::assertEquals(65.00, (float) $registration['merchandiseOrders'][0]['price']);
    }

    public function testUnknownMerchandiseIdIsRejected(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-merchandise-invalid',
            // Short ids: this slug is long enough that the default payload()
            // distance ids (slug + '-10k'/'-5k') would overflow VARCHAR(36).
            'distances' => [['id' => 'd-merch-invalid-1'], ['id' => 'd-merch-invalid-2']],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('merchandise_invalid');

        $this->registrations->create([
            ...$this->registrationPayload($event, ''),
            'merchandise' => [['merchandiseId' => 'does-not-exist', 'quantity' => 1]],
        ]);
    }

    /**
     * E1.2 krok 6: NNW liczone z katalogu (insurance_settings) tylko dla wariantów,
     * które organizator faktycznie oferuje na tym wydarzeniu (event_insurance_variants),
     * z wiekiem liczonym na dzień wydarzenia (nie dziś, nie dzień zapisu).
     */
    public function testInsuranceAmountUsesAgeAsOfEventDateFromCatalogFormula(): void
    {
        $insuranceId = $this->insuranceVariantId('phpunit-nnw-' . $this->slugPrefix, 15.00, 0.20, 70);
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-insurance',
            'date' => '2026-06-15',
            'insuranceVariantIds' => [$insuranceId],
        ]));

        $reloaded = $this->events->find($event['id']);
        self::assertSame($insuranceId, $reloaded['insuranceVariants'][0]['id']);

        $registration = $this->registrations->create([
            ...$this->registrationPayload($event, ''),
            'insuranceVariantId' => $insuranceId,
            // Zawodnik urodzony 1984-12-01: na dzień wydarzenia (2026-06-15) ma 41 lat
            // (urodziny w grudniu jeszcze nie minęły w tym roku kalendarzowym).
            'birthDate' => '1984-12-01',
        ]);

        // 15,00 + max(0, 41-18) * 0,20 = 15,00 + 4,60 = 19,60
        self::assertEquals(19.60, (float) $registration['insurancePaid']);
        self::assertEquals(80.00 + 19.60, (float) $registration['totalAmount']);
    }

    public function testInsuranceVariantNotOfferedByEventIsRejected(): void
    {
        $insuranceId = $this->insuranceVariantId('phpunit-nnw-uo-' . $this->slugPrefix, 15.00, 0.20, 70);
        // Deliberately not linked via insuranceVariantIds on this event.
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-insurance-unoffered',
            'distances' => [['id' => 'd-insurance-uo-1'], ['id' => 'd-insurance-uo-2']],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('insurance_variant_invalid');

        $this->registrations->create([
            ...$this->registrationPayload($event, ''),
            'insuranceVariantId' => $insuranceId,
            'birthDate' => '1984-12-01',
        ]);
    }

    /**
     * Reproduces the spec's worked numeric example end to end (07-model-cenowy-zapisu.md):
     * Wariant 110,00 → próg Early bird 50,00 (−60,00) → kod SZUTRY25 14% (−7,00) =
     * opłata startowa 43,00; + Modyfikator "Udział w losowaniu" −5,00 (step 4 has no
     * source yet — ticket 10/E1.13 — so this line is inserted directly to prove the
     * stage's numbers flow through hydrate() once it exists); + Gadżety Koszulka M
     * 65,00; + NNW Podstawowe wiek 41 → 19,60. Do zapłaty: 122,60.
     */
    public function testWorkedExampleFromSpecMatchesTotalAmount(): void
    {
        $variantId = $this->slugPrefix . '-worked-variant';
        $merchandiseId = $this->slugPrefix . '-worked-shirt';
        $insuranceId = $this->insuranceVariantId('phpunit-nnw-worked-' . $this->slugPrefix, 15.00, 0.20, 70);

        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-worked-example',
            'date' => '2026-06-15',
            'insuranceVariantIds' => [$insuranceId],
            'discountCodes' => [
                [
                    'id' => 'phpunit-w-szutry-' . $this->slugPrefix,
                    'code' => 'SZUTRY25',
                    'discountType' => 'percent',
                    'discountValue' => 14,
                    'usedCount' => 0,
                    'distanceIds' => [],
                    'active' => true,
                ],
            ],
            'merchandise' => [
                ['id' => $merchandiseId, 'name' => 'Koszulka techniczna', 'price' => 65, 'sizes' => ['M'], 'colors' => []],
            ],
            'distances' => [
                [
                    'id' => 'd-worked-example',
                    'pricingVariants' => [
                        [
                            'id' => $variantId,
                            'name' => 'STANDARDOWA',
                            'price' => 110,
                            'priceTiers' => [
                                ['name' => 'Early bird', 'endDate' => '2999-01-01', 'price' => 50],
                            ],
                        ],
                    ],
                ],
            ],
        ]));

        $registration = $this->registrations->create([
            ...$this->registrationPayload($event, 'SZUTRY25'),
            'pricingVariantId' => $variantId,
            'merchandise' => [['merchandiseId' => $merchandiseId, 'size' => 'M', 'quantity' => 1]],
            'insuranceVariantId' => $insuranceId,
            'birthDate' => '1984-12-01',
        ]);

        self::assertEquals(43.00, (float) $registration['pricePaid']);
        self::assertEquals(7.00, (float) $registration['discountAmount']);
        self::assertEquals(19.60, (float) $registration['insurancePaid']);
        // Bez Modyfikatorów (krok 4 zawsze pusty do ticket 10): 43,00 + 65,00 + 19,60 = 127,60
        self::assertEquals(127.60, (float) $registration['totalAmount']);

        // Krok 4 (Modyfikatory) nie ma dziś źródła danych (ticket 10 / E1.13), ale
        // jest realnym, zawsze-liczonym etapem: wstrzykujemy tu ręcznie dokładnie
        // taką pozycję, jaką ten krok kiedyś wyprodukuje, żeby dowieść, że
        // hydrate()'s DO ZAPŁATY policzy ją tak samo jak każdą inną pozycję Rozliczenia.
        $this->pdo->prepare(
            'INSERT INTO registration_settlement_items (id, registration_id, name, basis, amount, frozen_at, sort_order)
             VALUES (:id, :registrationId, :name, :basis, :amount, :frozenAt, :sortOrder)'
        )->execute([
            'id' => 'phpunit-modifier-' . $this->slugPrefix,
            'registrationId' => $registration['id'],
            'name' => 'Udział w losowaniu',
            'basis' => 'modifier',
            'amount' => -5.00,
            'frozenAt' => date(DATE_ATOM),
            'sortOrder' => 2,
        ]);

        $withModifier = $this->registrations->find($registration['id']);
        self::assertEquals(43.00, (float) $withModifier['pricePaid'], 'modifier must not affect opłata startowa');
        self::assertEquals(122.60, (float) $withModifier['totalAmount']);
    }

    /**
     * E1.5: żądanie zwrotu jest tylko dozwolone na opłaconym (`confirmed`) Zapisie
     * — próba na `pending` musi zostać odrzucona, nie cichy no-op.
     */
    public function testRequestRefundRejectsUnpaidRegistration(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-refund-unpaid']));
        $registration = $this->registrations->create($this->registrationPayload($event, ''));

        self::assertSame('pending', $registration['status']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('refund_requires_paid_registration');
        $this->registrations->requestRefund($registration['id'], 'phpunit-user');
    }

    /**
     * Happy path: opłacona -> żądanie zwrotu -> zatwierdzenie ("do zwrotu") ->
     * wykonanie (trasa B, ręcznie) -> zwrócona. A full refund (amount == frozen
     * totalAmount) also moves the Zapis itself to `cancelled` and releases its
     * Dystans slot — rozstrzygnięcie 7 (registrations.status must stay consistent
     * with the refund state machine).
     */
    public function testFullRefundLifecycleCancelsRegistrationAndReleasesSlot(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-refund-lifecycle',
            'distances' => [
                ['id' => 'd-refund-lifecycle-1', 'price' => 80, 'limit' => 10, 'registeredCount' => 0],
                ['id' => 'd-refund-lifecycle-2'],
            ],
            'paymentConfig' => ['type' => 'organizer_bank'],
        ]));
        $payload = $this->registrationPayload($event, '');
        $payload['distanceId'] = 'd-refund-lifecycle-1';
        $payload['paymentDetails'] = ['startFeePaid' => true];
        $registration = $this->registrations->create($payload);

        self::assertSame('confirmed', $registration['status']);
        self::assertSame(1, $this->rawRegisteredCount('d-refund-lifecycle-1'));

        $requested = $this->registrations->requestRefund($registration['id'], 'phpunit-user', 'nie mogę przyjechać');
        self::assertSame('requested', $requested['refund']['status']);
        self::assertSame('phpunit-user', $requested['refund']['requestedBy']);

        $approved = $this->registrations->approveRefund($registration['id'], 'phpunit-organizer');
        self::assertSame('approved', $approved['refund']['status']);
        self::assertEquals(80.00, (float) $approved['refund']['amount']);
        self::assertSame('manual', $approved['refund']['route']);

        $refunded = $this->registrations->markRefunded($registration['id'], 'phpunit-organizer');
        self::assertSame('refunded', $refunded['refund']['status']);
        self::assertSame('cancelled', $refunded['status']);
        self::assertSame(0, $this->rawRegisteredCount('d-refund-lifecycle-1'));
    }

    /**
     * Idempotencja (rozstrzygnięcie 6): a second requestRefund()/approveRefund()/
     * markRefunded() call for a cycle already at (or past) that stage must not
     * error and must not double-transition anything.
     */
    public function testRefundTransitionsAreIdempotent(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-refund-idempotent']));
        $payload = $this->registrationPayload($event, '');
        $payload['paymentDetails'] = ['startFeePaid' => true];
        $registration = $this->registrations->create($payload);

        $this->registrations->requestRefund($registration['id'], 'phpunit-user');
        $again = $this->registrations->requestRefund($registration['id'], 'phpunit-user');
        self::assertSame('requested', $again['refund']['status']);

        $this->registrations->approveRefund($registration['id'], 'phpunit-organizer');
        $approvedAgain = $this->registrations->approveRefund($registration['id'], 'phpunit-organizer-2');
        self::assertSame('approved', $approvedAgain['refund']['status']);
        // The second approve() call must not have overwritten who approved it first.
        self::assertSame('phpunit-organizer', $approvedAgain['refund']['approvedBy']);

        $this->registrations->markRefunded($registration['id'], 'phpunit-organizer');
        $refundedAgain = $this->registrations->markRefunded($registration['id'], 'phpunit-organizer-2');
        self::assertSame('refunded', $refundedAgain['refund']['status']);
        self::assertSame('phpunit-organizer', $refundedAgain['refund']['completedBy']);
    }

    /**
     * approveRefund() clamps a caller-supplied amount at the frozen Rozliczenie
     * total (rozstrzygnięcie 3: saldo dodatnie z Rozliczenia is the ceiling, even
     * for a deliberately partial refund) — and a partial refund must leave the
     * Zapis itself `confirmed` (the registrant is still attending), not cancel it.
     */
    public function testPartialRefundAmountIsClampedAndDoesNotCancelRegistration(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-refund-partial']));
        $payload = $this->registrationPayload($event, '');
        $payload['paymentDetails'] = ['startFeePaid' => true];
        $registration = $this->registrations->create($payload);
        self::assertEquals(80.00, (float) $registration['totalAmount']);

        $this->registrations->requestRefund($registration['id'], 'phpunit-user');
        $approved = $this->registrations->approveRefund($registration['id'], 'phpunit-organizer', 200.0);
        self::assertEquals(80.00, (float) $approved['refund']['amount'], 'amount must be clamped to the settlement total');

        $partial = $this->registrations->approveRefund($registration['id'], 'phpunit-organizer');
        // Already approved — idempotent, keeps the clamped amount from the first call.
        self::assertEquals(80.00, (float) $partial['refund']['amount']);

        $refunded = $this->registrations->markRefunded($registration['id'], 'phpunit-organizer');
        self::assertSame('cancelled', $refunded['status'], 'the full settlement amount was refunded, so this is a full refund');
    }

    /**
     * Zawodnik never triggers a refund directly (rozstrzygnięcie 1) — this is
     * enforced at the HTTP layer (RegistrationContractTest), but the repository
     * method itself has no such gate; approveRefund()/markRefunded() require an
     * existing `requested`/`approved` cycle, so calling markRefunded() with nothing
     * requested/approved yet must fail loudly rather than silently completing.
     */
    public function testMarkRefundedWithoutAnApprovedCycleFails(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-refund-no-cycle']));
        $payload = $this->registrationPayload($event, '');
        $payload['paymentDetails'] = ['startFeePaid' => true];
        $registration = $this->registrations->create($payload);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('refund_request_not_found');
        $this->registrations->markRefunded($registration['id'], 'phpunit-organizer');
    }

    /**
     * Odwołanie Wydarzenia (rozstrzygnięcie 2): a bulk operation, not N individual
     * requests — every `confirmed` Zapis of the Wydarzenie moves straight to
     * `approved` ("do zwrotu") in one call, `pending` ones are left untouched (they
     * never paid anything to refund), and calling it twice does not double-process
     * an already-mid-cycle Zapis.
     */
    public function testBulkApproveRefundsForEventOnlyTouchesConfirmedRegistrationsOnce(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-refund-bulk',
            'distances' => [
                ['id' => 'd-refund-bulk-1', 'price' => 80, 'limit' => 10, 'registeredCount' => 0],
                ['id' => 'd-refund-bulk-2'],
            ],
        ]));

        $paidPayload = $this->registrationPayload($event, '', 'phpunit-bulk-paid');
        $paidPayload['distanceId'] = 'd-refund-bulk-1';
        $paidPayload['paymentDetails'] = ['startFeePaid' => true];
        $paid = $this->registrations->create($paidPayload);

        $unpaidPayload = $this->registrationPayload($event, '', 'phpunit-bulk-unpaid');
        $unpaidPayload['distanceId'] = 'd-refund-bulk-1';
        $unpaid = $this->registrations->create($unpaidPayload);

        $count = $this->registrations->bulkApproveRefundsForEvent((string) $event['id'], 'phpunit-admin');
        self::assertSame(1, $count);

        $paidReloaded = $this->registrations->find($paid['id']);
        self::assertSame('approved', $paidReloaded['refund']['status']);
        self::assertSame('event_cancelled', $paidReloaded['refund']['reason']);

        $unpaidReloaded = $this->registrations->find($unpaid['id']);
        self::assertNull($unpaidReloaded['refund']);

        // A second bulk trigger (e.g. a defensive re-call) must not re-process the
        // already-mid-cycle paid registration.
        $secondCount = $this->registrations->bulkApproveRefundsForEvent((string) $event['id'], 'phpunit-admin-2');
        self::assertSame(0, $secondCount);
        self::assertSame('phpunit-admin', $this->registrations->find($paid['id'])['refund']['approvedBy']);
    }

    /**
     * "Ponowienie" (spec: jednoklikowe ponowienie płatności): a `wygasła` Zapis
     * reopens as a fresh `pending` with a brand-new payment-window deadline, and
     * re-occupies the Dystans slot it had released on expiry.
     */
    public function testRetryPaymentReopensExpiredRegistrationWithFreshWindow(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-retry',
            'distances' => [
                ['id' => 'd-retry-1', 'price' => 80, 'limit' => 10, 'registeredCount' => 0],
                ['id' => 'd-retry-2'],
            ],
        ]));
        $payload = $this->registrationPayload($event, '');
        $payload['distanceId'] = 'd-retry-1';
        $registration = $this->registrations->create($payload);
        $this->backdatePaymentExpiry($registration['id'], (new \DateTimeImmutable('-1 minute'))->format(DATE_ATOM));

        $expired = $this->registrations->find($registration['id']);
        self::assertSame('expired', $expired['status']);
        self::assertSame(0, $this->rawRegisteredCount('d-retry-1'));

        $retried = $this->registrations->retryPayment($registration['id']);
        self::assertSame('pending', $retried['status']);
        self::assertSame(1, $this->rawRegisteredCount('d-retry-1'));

        $newExpiresAt = new \DateTimeImmutable((string) $this->rawRegistration($registration['id'])['paymentExpiresAt']);
        $expectedAt = new \DateTimeImmutable('+45 minutes');
        self::assertEqualsWithDelta($expectedAt->getTimestamp(), $newExpiresAt->getTimestamp(), 30);
    }

    /** Retrying a Zapis that never expired (still pending/confirmed) must be rejected. */
    public function testRetryPaymentRejectsNonExpiredRegistration(): void
    {
        $event = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-retry-not-expired']));
        $registration = $this->registrations->create($this->registrationPayload($event, ''));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('retry_requires_expired_registration');
        $this->registrations->retryPayment($registration['id']);
    }

    private function insuranceVariantId(string $id, float $baseFee, float $ageMultiplier, int $maxAge): string
    {
        $this->pdo->prepare(
            'INSERT INTO insurance_settings (id, name, base_fee, age_multiplier, max_age, info_html, active, sort_order)
             VALUES (:id, :name, :baseFee, :ageMultiplier, :maxAge, :infoHtml, 1, 0)'
        )->execute([
            'id' => $id,
            'name' => 'Podstawowe (PHPUnit)',
            'baseFee' => $baseFee,
            'ageMultiplier' => $ageMultiplier,
            'maxAge' => $maxAge,
            'infoHtml' => '',
        ]);

        return $id;
    }

    /** @param array<string, mixed> $event */
    private function registrationPayload(array $event, string $code, string $userId = 'phpunit-user'): array
    {
        return [
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'userId' => $userId,
            'userName' => 'PHPUnit User',
            'userEmail' => $userId . '@example.test',
            'distanceId' => $event['distances'][0]['id'],
            'distanceName' => $event['distances'][0]['name'],
            'category' => 'Open',
            'discountCode' => $code,
            'merchandiseOrders' => [],
        ];
    }

    private function rawDiscountCode(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event_discount_codes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: [];
    }

    private function deletePhpUnitEvents(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => 'phpreg-%']);
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
            $this->pdo->prepare('DELETE FROM event_discount_codes WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_merchandise WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_insurance_variants WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare("DELETE FROM insurance_settings WHERE id LIKE 'phpunit-nnw-%'")->execute();
    }
}

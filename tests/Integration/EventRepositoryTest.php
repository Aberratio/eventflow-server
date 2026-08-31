<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Support\Env;

final class EventRepositoryTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private string $slugPrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->slugPrefix = 'phpunit-event-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->ensureEventStatusWorkflowSchema();
            $this->deletePhpUnitEvents('phpunit-event-%');
            $this->events = new EventRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        $this->deletePhpUnitEvents('phpunit-event-%');
    }

    /**
     * E1.1 dropped the free-text `status` column entirely (see
     * 031_e11_event_lifecycle.sql), so there is no ENUM to patch in place any more — the
     * schema this test needs only exists once every migration has actually run. Calling
     * the real Migrator (idempotent, no ledger — see its docblock) is the only thing that
     * still makes sense here.
     */
    private function ensureEventStatusWorkflowSchema(): void
    {
        \ZmierzymyCzas\Database\Migrator::applyAll($this->pdo);
    }

    private function deletePhpUnitEvents(string $slugLike): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => $slugLike]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($ids as $id) {
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_payment_methods WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_merchandise WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_discount_codes WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_gallery_albums WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
    }

    public function testCreatePersistsEventAndDistancesInDatabase(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-create',
            'name' => 'PHPUnit Run Create',
            'description' => '<p><strong>Opis dla uczestnika</strong></p>',
            'organizersHtml' => '<p>Organizator <a href="https://example.test">Test</a></p>',
            'rulesHtml' => '<ul><li>Regulamin testowy</li></ul>',
            'requireFinalAcceptance' => true,
            'finalAcceptanceHtml' => '<p>Akceptuje warunki testowe</p>',
            'paymentConfig' => [
                'type' => 'organizer_bank',
                'bankAccountNumber' => 'PL00111122223333444455556666',
                'bankAccountHolder' => 'PHPUnit Organizer',
                'transferTitle' => 'Start {eventName} {participantName}',
            ],
            'registrationEmbed' => [
                'enabled' => true,
                'startDate' => '2026-03-01',
                'endDate' => '2026-04-01',
            ],
            'registrationFormConfig' => [
                'collectPhone' => true,
                'collectClub' => true,
                'collectBirthDate' => true,
                'collectTeam' => false,
                'collectInsurance' => false,
                'collectMerchandise' => false,
                'customFields' => [],
            ],
            'displayOptions' => [
                'defaultSort' => 'distance',
                'uppercaseNames' => true,
                'hideUnpaid' => true,
            ],
        ]));

        self::assertNotEmpty($event['id']);
        self::assertSame('PHPUnit Run Create', $event['name']);
        self::assertSame('<p><strong>Opis dla uczestnika</strong></p>', $event['description']);
        self::assertTrue($event['requireFinalAcceptance']);
        self::assertSame('organizer_bank', $event['paymentConfig']['type']);
        self::assertSame('distance', $event['displayOptions']['defaultSort']);
        self::assertCount(2, $event['distances']);
        self::assertSame('10 km', $event['distances'][0]['name']);
        self::assertSame(['Open', 'K30'], $event['distances'][0]['categories']);

        $raw = $this->rawEvent((string) $event['id']);
        self::assertSame('PHPUnit Run Create', $raw['name']);
        self::assertSame('<p><strong>Opis dla uczestnika</strong></p>', $raw['description']);
        self::assertSame(1, (int) $raw['require_final_acceptance']);
        self::assertSame('organizer_bank', json_decode((string) $raw['payment_config'], true)['type']);
        self::assertSame('distance', json_decode((string) $raw['display_options'], true)['defaultSort']);

        $distances = $this->rawDistances((string) $event['id']);
        self::assertCount(2, $distances);
        self::assertSame('10 km', $distances[0]['name']);
        self::assertSame(250, (int) $distances[0]['participant_limit']);
        self::assertSame(['Open', 'K30'], json_decode((string) $distances[0]['categories'], true));
        self::assertSame('https://example.test/10k.gpx', $distances[0]['route_gpx_url']);
    }

    public function testUpdatePersistsEditedEventAndReplacesDistances(): void
    {
        $created = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-update',
            'name' => 'PHPUnit Before Edit',
        ]));

        $updated = $this->events->update((string) $created['id'], $this->payload([
            'slug' => $this->slugPrefix . '-updated',
            'name' => 'PHPUnit After Edit',
            'city' => 'Krakow',
            'description' => '<p>Opis po edycji z <em>formatowaniem</em></p>',
            'featured' => true,
            'distances' => [
                [
                    'id' => 'phpunit-updated-half',
                    'name' => 'Polmaraton',
                    'length' => 21.1,
                    'unit' => 'km',
                    'categories' => ['Open'],
                    'ageCategories' => [],
                    'specialCategories' => [],
                    'limit' => 120,
                    'registeredCount' => 0,
                    'price' => 99,
                    'routeMapUrl' => 'https://example.test/half-map.png',
                    'routeElevationProfile' => null,
                    'routeGpxUrl' => null,
                    'routeFiles' => [],
                ],
            ],
        ]));

        self::assertNotNull($updated);
        self::assertSame('PHPUnit After Edit', $updated['name']);
        self::assertSame('Krakow', $updated['city']);
        self::assertSame('<p>Opis po edycji z <em>formatowaniem</em></p>', $updated['description']);
        self::assertTrue($updated['featured']);
        self::assertCount(1, $updated['distances']);
        self::assertSame('Polmaraton', $updated['distances'][0]['name']);

        $raw = $this->rawEvent((string) $created['id']);
        self::assertSame('PHPUnit After Edit', $raw['name']);
        self::assertSame('Krakow', $raw['city']);
        self::assertSame(1, (int) $raw['featured']);

        $distances = $this->rawDistances((string) $created['id']);
        self::assertCount(1, $distances);
        self::assertSame('phpunit-updated-half', $distances[0]['id']);
        self::assertSame('Polmaraton', $distances[0]['name']);
        self::assertSame(120, (int) $distances[0]['participant_limit']);
    }

    public function testCreatePersistsDiscountCodesForEvent(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-discounts',
            'discountCodes' => [
                [
                    'id' => 'phpunit-fixed-decimal',
                    'code' => 'SAVE25',
                    'discountType' => 'fixed',
                    'discountValue' => 25.50,
                    'maxUses' => 3,
                    'usedCount' => 0,
                    'distanceIds' => [$this->slugPrefix . '-discounts-10k'],
                    'active' => true,
                ],
                [
                    'id' => 'phpunit-percent',
                    'code' => 'TEN',
                    'discountType' => 'percent',
                    'discountValue' => 10,
                    'usedCount' => 0,
                    'distanceIds' => [],
                    'active' => false,
                ],
            ],
        ]));

        self::assertCount(2, $event['discountCodes']);
        self::assertSame('SAVE25', $event['discountCodes'][0]['code']);
        self::assertSame('fixed', $event['discountCodes'][0]['discountType']);
        self::assertEquals(25.50, (float) $event['discountCodes'][0]['discountValue']);
        self::assertSame([$this->slugPrefix . '-discounts-10k'], $event['discountCodes'][0]['distanceIds']);

        $rawCodes = $this->rawDiscountCodes((string) $event['id']);
        self::assertCount(2, $rawCodes);
        self::assertSame('SAVE25', $rawCodes[0]['code']);
        self::assertSame('25.50', (string) $rawCodes[0]['discount_value']);
        self::assertSame([$this->slugPrefix . '-discounts-10k'], json_decode((string) $rawCodes[0]['distance_ids'], true));
        self::assertSame(1, (int) $rawCodes[0]['active']);
    }

    public function testPublicListShowsOnlyParticipantVisibleEvents(): void
    {
        $visible = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-visible',
            'name' => 'PHPUnit Visible For Participants',
            'status' => 'active',
            'isPrivate' => false,
        ]));
        $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-draft',
            'name' => 'PHPUnit Draft Hidden',
            'status' => 'draft',
            'isPrivate' => false,
        ]));
        $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-private',
            'name' => 'PHPUnit Private Hidden',
            'status' => 'active',
            'isPrivate' => true,
        ]));

        $public = $this->events->list(['search' => 'PHPUnit']);
        $names = array_column($public['data'], 'name');

        self::assertContains('PHPUnit Visible For Participants', $names);
        self::assertNotContains('PHPUnit Draft Hidden', $names);
        self::assertNotContains('PHPUnit Private Hidden', $names);

        $publicEvent = $this->events->findBySlug((string) $visible['slug']);
        self::assertSame('PHPUnit Visible For Participants', $publicEvent['name']);
        self::assertSame('published', $publicEvent['publicationStatus']);
        self::assertFalse($publicEvent['isPrivate']);
    }

    public function testModerationWorkflowApprovesDirectlyToPublished(): void
    {
        $created = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-workflow',
            'name' => 'PHPUnit Moderation Workflow',
            'status' => 'draft',
            'distances' => $this->minimalDistances($this->slugPrefix . '-workflow'),
        ]));

        $submitted = $this->events->submit((string) $created['id']);
        self::assertNotNull($submitted);
        self::assertSame('submitted', $submitted['publicationStatus']);
        self::assertNotEmpty($submitted['submittedAt']);

        $publicBeforeApproval = $this->events->list(['search' => 'PHPUnit Moderation Workflow']);
        self::assertSame([], array_column($publicBeforeApproval['data'], 'name'));

        // E1.1: approve() lands directly on `published` — there is no separate
        // "approved, not yet published" resting state to publish from afterwards.
        $approved = $this->events->approve((string) $created['id'], 'phpunit-admin');
        self::assertNotNull($approved);
        self::assertSame('published', $approved['publicationStatus']);
        self::assertSame('phpunit-admin', $approved['approvedBy']);
        self::assertNotEmpty($approved['approvedAt']);
        self::assertSame('phpunit-admin', $approved['publishedBy']);
        self::assertNotEmpty($approved['publishedAt']);

        $publicAfterApproval = $this->events->list(['search' => 'PHPUnit Moderation Workflow']);
        self::assertContains('PHPUnit Moderation Workflow', array_column($publicAfterApproval['data'], 'name'));
    }

    public function testRejectReturnsSubmittedEventToDraftWithReason(): void
    {
        $created = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-reject',
            'name' => 'PHPUnit Reject Workflow',
            'distances' => $this->minimalDistances($this->slugPrefix . '-reject'),
        ]));

        $submitted = $this->events->submit((string) $created['id']);
        self::assertSame('submitted', $submitted['publicationStatus']);

        $rejected = $this->events->reject((string) $created['id'], 'Brakuje regulaminu');
        self::assertNotNull($rejected);
        self::assertSame('draft', $rejected['publicationStatus']);
        self::assertSame('Brakuje regulaminu', $rejected['rejectionReason']);
    }

    public function testManualStatusChangeCannotBypassModerationWorkflow(): void
    {
        $created = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-manual-active',
            'name' => 'PHPUnit Manual Active Forbidden',
            'distances' => $this->minimalDistances($this->slugPrefix . '-manual-active'),
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_status_transition');

        $this->events->updateStatus((string) $created['id'], 'published', null);
    }

    public function testCancelOnlyWorksFromPublished(): void
    {
        $draft = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-cancel-draft',
            'name' => 'PHPUnit Cancel From Draft Forbidden',
            'distances' => $this->minimalDistances($this->slugPrefix . '-cancel-draft'),
        ]));
        self::assertNull($this->events->cancel((string) $draft['id']));

        $published = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-cancel-published',
            'name' => 'PHPUnit Cancel From Published',
            'status' => 'active',
            'distances' => $this->minimalDistances($this->slugPrefix . '-cancel-published'),
        ]));
        $cancelled = $this->events->cancel((string) $published['id']);
        self::assertNotNull($cancelled);
        self::assertSame('cancelled', $cancelled['publicationStatus']);

        // Terminal: cancelling again does nothing.
        self::assertNull($this->events->cancel((string) $published['id']));
    }

    public function testVisibilityOnlyWorksFromPublished(): void
    {
        $draft = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-visibility-draft',
            'name' => 'PHPUnit Visibility From Draft Forbidden',
            'distances' => $this->minimalDistances($this->slugPrefix . '-visibility-draft'),
        ]));
        self::assertNull($this->events->setVisibility((string) $draft['id'], 'private'));

        $published = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-visibility-published',
            'name' => 'PHPUnit Visibility From Published',
            'status' => 'active',
            'distances' => $this->minimalDistances($this->slugPrefix . '-visibility-published'),
        ]));

        $private = $this->events->setVisibility((string) $published['id'], 'private');
        self::assertNotNull($private);
        self::assertSame('private', $private['visibility']);
        self::assertTrue($private['isPrivate']);

        // `visibility` and the underlying `is_private` column stay in sync in both
        // directions.
        $public = $this->events->setVisibility((string) $published['id'], 'public');
        self::assertNotNull($public);
        self::assertSame('public', $public['visibility']);
        self::assertFalse($public['isPrivate']);

        // Anything other than the literal 'private' normalizes to 'public' — mirrors the
        // ternary in EventRepository::setVisibility().
        $garbage = $this->events->setVisibility((string) $published['id'], 'not-a-real-value');
        self::assertNotNull($garbage);
        self::assertSame('public', $garbage['visibility']);
    }

    public function testIsTestOnlySettableWhileDraftAndClearedIrreversiblyOnSubmit(): void
    {
        $created = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-is-test',
            'name' => 'PHPUnit Is Test Flag',
            'distances' => $this->minimalDistances($this->slugPrefix . '-is-test'),
        ]));

        $withTest = $this->events->setIsTest((string) $created['id'], true);
        self::assertNotNull($withTest);
        self::assertTrue($withTest['isTest']);

        $submitted = $this->events->submit((string) $created['id']);
        self::assertNotNull($submitted);
        self::assertFalse($submitted['isTest']);

        // Rejected back to draft — is_test stays cleared, and it's irreversible: setIsTest
        // no longer applies once the event has been through Submitted, since Draft here
        // means "rejected", not "never submitted".
        $rejected = $this->events->reject((string) $created['id'], 'Wymaga poprawek');
        self::assertSame('draft', $rejected['publicationStatus']);
        self::assertFalse($rejected['isTest']);

        self::assertNull($this->events->setIsTest((string) $created['id'], true));
    }

    /**
     * E1.4: an event created without ever touching paymentConfig's window fields
     * still gets the two documented defaults back on read — "bramka: domyślnie
     * 30-60 minut" (45), "przelew: domyślnie 3-5 dni roboczych" (3) — so a caller
     * (RegistrationRepository::create(), or the organizer's settings tab) never has
     * to special-case "not configured yet".
     */
    public function testPaymentWindowDefaultsApplyWhenNotConfigured(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-pay-def',
            'paymentConfig' => ['type' => 'admin_gateway'],
        ]));

        self::assertSame(45, $event['paymentConfig']['gatewayWindowMinutes']);
        self::assertSame(3, $event['paymentConfig']['transferWindowDays']);
    }

    /**
     * E1.4: "Organizator może zmienić obie wartości domyślne per Wydarzenie" — both
     * windows are real, independently organizer-editable fields, not fixed
     * constants merely displayed to the organizer.
     */
    public function testPaymentWindowOverridesPersistPerEvent(): void
    {
        $created = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-pay-ovr',
            'paymentConfig' => ['type' => 'admin_gateway'],
        ]));

        $updated = $this->events->update((string) $created['id'], [
            'paymentConfig' => [
                'type' => 'admin_gateway',
                'gatewayWindowMinutes' => 60,
                'transferWindowDays' => 5,
            ],
        ]);

        self::assertSame(60, $updated['paymentConfig']['gatewayWindowMinutes']);
        self::assertSame(5, $updated['paymentConfig']['transferWindowDays']);

        // Persisted, not just echoed back from the request — a fresh read agrees.
        $reloaded = $this->events->find((string) $created['id']);
        self::assertSame(60, $reloaded['paymentConfig']['gatewayWindowMinutes']);
        self::assertSame(5, $reloaded['paymentConfig']['transferWindowDays']);
    }

    /**
     * A non-positive override (0, negative, or a garbage value client never should
     * send but nothing stops it from sending) falls back to the default rather than
     * freezing every future Zapis instantly or never — see
     * EventRepository::withPaymentWindowDefaults().
     */
    public function testPaymentWindowNonPositiveOverrideFallsBackToDefault(): void
    {
        $created = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-pay-inv',
            'paymentConfig' => ['type' => 'admin_gateway', 'gatewayWindowMinutes' => 0, 'transferWindowDays' => -1],
        ]));

        self::assertSame(45, $created['paymentConfig']['gatewayWindowMinutes']);
        self::assertSame(3, $created['paymentConfig']['transferWindowDays']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? $this->slugPrefix);
        $payload = array_replace_recursive([
            'name' => 'PHPUnit Event',
            'slug' => $slug,
            'discipline' => 'running',
            // Must stay in the future relative to "today" for any environment this runs
            // in — EventRepository::lazilyFinishPastEvents() (E1.1) auto-flips a published
            // event whose date has passed to `finished` on every read, and a stale date
            // here would silently break every test that expects `published` to stick.
            'date' => date('Y-m-d', strtotime('+18 months')),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'organizerContact' => [
                'email' => 'phpunit@example.test',
                'phone' => '500600700',
                'website' => 'https://example.test',
            ],
            'status' => 'draft',
            'registrationDeadline' => '2026-06-01',
            'featured' => false,
            'isVirtual' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'organizersHtml' => '<p>PHPUnit Organizer</p>',
            'rulesHtml' => '<p>Regulamin</p>',
            'finalAcceptanceHtml' => '',
            'requireFinalAcceptance' => false,
            'isPrivate' => false,
            'registrationEmbed' => ['enabled' => false],
            'registrationFormConfig' => [
                'collectPhone' => true,
                'collectClub' => true,
                'collectBirthDate' => true,
                'collectTeam' => true,
                'collectInsurance' => false,
                'collectMerchandise' => false,
                'customFields' => [],
            ],
            'displayOptions' => [
                'defaultSort' => 'name',
                'uppercaseNames' => false,
                'hideUnpaid' => false,
            ],
            'distances' => [
                [
                    'id' => $slug . '-10k',
                    'name' => '10 km',
                    'length' => 10,
                    'unit' => 'km',
                    'categories' => ['Open', 'K30'],
                    'ageCategories' => [
                        [
                            'id' => 'age-open',
                            'name' => 'Open',
                            'gender' => 'M',
                            'birthYearFrom' => 1900,
                            'birthYearTo' => 2026,
                        ],
                    ],
                    'specialCategories' => [],
                    'limit' => 250,
                    'registeredCount' => 0,
                    'price' => 80,
                    'priceTiers' => [],
                    'vipPrices' => [],
                    'routeMapUrl' => 'https://example.test/10k-map.png',
                    'routeElevationProfile' => 'https://example.test/10k-profile.png',
                    'routeGpxUrl' => 'https://example.test/10k.gpx',
                    'routeFiles' => [
                        [
                            'id' => $slug . '-route-file-map',
                            'name' => 'Mapa PDF',
                            'type' => 'map',
                            'url' => 'https://example.test/map.pdf',
                        ],
                    ],
                ],
                [
                    'id' => $slug . '-5k',
                    'name' => '5 km',
                    'length' => 5,
                    'unit' => 'km',
                    'categories' => ['Open'],
                    'ageCategories' => [],
                    'specialCategories' => [],
                    'limit' => null,
                    'registeredCount' => 0,
                    'price' => 50,
                    'routeFiles' => [],
                ],
            ],
        ], $overrides);

        if (array_key_exists('distances', $overrides)) {
            $payload['distances'] = $overrides['distances'];
        }

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    private function minimalDistances(string $prefix): array
    {
        return [
            [
                'id' => $prefix . '-distance-' . bin2hex(random_bytes(4)),
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
        ];
    }

    /** @return array<string, mixed> */
    private function rawEvent(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM events WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: [];
    }

    /** @return array<int, array<string, mixed>> */
    private function rawDistances(string $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event_distances WHERE event_id = :id ORDER BY sort_order ASC');
        $stmt->execute(['id' => $eventId]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    private function rawDiscountCodes(string $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event_discount_codes WHERE event_id = :id ORDER BY sort_order ASC');
        $stmt->execute(['id' => $eventId]);
        return $stmt->fetchAll();
    }
}

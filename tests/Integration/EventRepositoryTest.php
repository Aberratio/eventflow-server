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
        $this->slugPrefix = 'pet-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->ensureEventStatusWorkflowSchema();
            $this->deletePhpUnitEvents('pet-%');
            $this->ensurePhpUnitOrganizerHasCredits();
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

        $this->deletePhpUnitEvents('pet-%');
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

    /**
     * E5.6: this file's default event payload() uses the literal organizerId
     * 'phpunit-organizer' (shared with several other integration test files as
     * a placeholder — never a real `users` row anywhere else). submit() now
     * needs a real users row with available organizer_package_credits to
     * spend, so this test file — the only one that actually calls
     * submit()/reject() against that placeholder id — upserts one here, reset
     * to a generous balance before every test so run order/history never
     * matters. No FK from events.organizer_id to users.id exists, so creating
     * this row is purely additive and doesn't affect any other suite.
     */
    private function ensurePhpUnitOrganizerHasCredits(): void
    {
        $this->pdo->prepare(
            "INSERT INTO users (id, name, email, password_hash, role, status, organizer_package_credits)
             VALUES ('phpunit-organizer', 'PHPUnit Organizer', 'phpunit-organizer@example.test', :hash, 'organizer', 'active', 100)
             ON DUPLICATE KEY UPDATE organizer_package_credits = 100"
        )->execute(['hash' => password_hash('demo1234', PASSWORD_DEFAULT)]);
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

    /**
     * E6.4 (issue 15 §3): "Wydarzenie zewnętrzne" — the barebones reference
     * Event a Moderator/Admin creates for a Zawodnik's manual challenge-entry
     * proof. Pins the minimal shape the spec asks for: `isExternal = true`,
     * created `published` (visible/selectable immediately, no moderation
     * workflow to run it through; like every Published event it reads back
     * `finished` once its date has passed, issue 08), `registrationStatus = 'none'`,
     * and — the "no Dystansow" half of "stan bez Dystansow i zapisow" —
     * zero `distances` rows, since createExternal() never accepts a
     * `distances` payload at all.
     */
    public function testCreateExternalPersistsBarebonesEventWithNoDistances(): void
    {
        $event = $this->events->createExternal([
            'name' => $this->slugPrefix . ' Polmaraton Korony 2015',
            'date' => '2015-09-06',
            'location' => 'Rynek Glowny',
            'city' => 'Krakow',
        ], 'phpunit-moderator');

        self::assertNotEmpty($event['id']);
        self::assertTrue($event['isExternal']);
        // Issue 08: Published -> Finished is automatic after the event date, also for external events.
        self::assertSame('finished', $event['publicationStatus']);
        self::assertSame('none', $event['registrationStatus']);
        self::assertSame([], $event['distances']);

        // Created as published (no moderation) while the date is still ahead.
        $upcoming = $this->events->createExternal([
            'name' => $this->slugPrefix . ' Polmaraton Korony przyszly',
            'date' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'location' => 'Rynek Glowny',
            'city' => 'Krakow',
        ], 'phpunit-moderator');
        self::assertSame('published', $upcoming['publicationStatus']);
        self::assertSame('none', $upcoming['registrationStatus']);
        self::assertSame([], $upcoming['distances']);

        $reread = $this->events->find((string) $event['id']);
        self::assertNotNull($reread);
        self::assertTrue($reread['isExternal']);

        // Sanity: an ordinary Event created through create()/createForOrganizer()
        // is NOT external by default.
        $ordinary = $this->events->create($this->payload(['slug' => $this->slugPrefix . '-not-external']));
        self::assertFalse($ordinary['isExternal']);
    }

    public function testCreateExternalRejectsMissingName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->events->createExternal(['date' => '2015-09-06', 'location' => 'X', 'city' => 'Y'], 'phpunit-moderator');
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

    /**
     * E5.6 (issue 16 §2): submit() spends exactly one "pakiet organizatora"
     * credit, atomically with the Draft -> Submitted transition.
     */
    public function testSubmitConsumesExactlyOneOrganizerPackageCredit(): void
    {
        $this->setOrganizerPackageCredits('phpunit-organizer', 3);

        $created = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-cr-consume',
            'name' => 'PHPUnit Credit Consume',
            'distances' => $this->minimalDistances($this->slugPrefix . '-cr-consume'),
        ]));
        // Draft creation itself never touches the credit.
        self::assertSame(3, $this->organizerPackageCredits('phpunit-organizer'));

        $submitted = $this->events->submit((string) $created['id']);
        self::assertNotNull($submitted);
        self::assertSame(2, $this->organizerPackageCredits('phpunit-organizer'));
    }

    /**
     * E5.6: no available credit -> submit() throws a specific business
     * exception, the event is left untouched in Draft (no partial status
     * change), and the (already zero) credit counter isn't perturbed.
     */
    public function testSubmitWithoutAvailableCreditThrowsAndLeavesEventInDraft(): void
    {
        $this->setOrganizerPackageCredits('phpunit-organizer', 0);

        $created = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-credit-none',
            'name' => 'PHPUnit Credit None',
            'distances' => $this->minimalDistances($this->slugPrefix . '-credit-none'),
        ]));

        try {
            $this->events->submit((string) $created['id']);
            self::fail('Expected event_submit_no_organizer_credit to be thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('event_submit_no_organizer_credit', $e->getMessage());
        }

        $stillDraft = $this->events->find((string) $created['id']);
        self::assertSame('draft', $stillDraft['publicationStatus']);
        self::assertSame(0, $this->organizerPackageCredits('phpunit-organizer'));
    }

    /**
     * E5.6: reject() refunds exactly one credit, atomically with the
     * Submitted -> Draft transition; approve() must never touch the credit in
     * either direction (already spent at submit(), one-shot consumption).
     */
    public function testRejectRefundsOneCreditAndApproveLeavesItUntouched(): void
    {
        $this->setOrganizerPackageCredits('phpunit-organizer', 2);

        $rejectedCandidate = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-cr-reject',
            'name' => 'PHPUnit Credit Reject',
            'distances' => $this->minimalDistances($this->slugPrefix . '-cr-reject'),
        ]));
        $this->events->submit((string) $rejectedCandidate['id']);
        self::assertSame(1, $this->organizerPackageCredits('phpunit-organizer'));

        $rejected = $this->events->reject((string) $rejectedCandidate['id'], 'Brakuje regulaminu');
        self::assertNotNull($rejected);
        self::assertSame(2, $this->organizerPackageCredits('phpunit-organizer'));

        $approvedCandidate = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-cr-approve',
            'name' => 'PHPUnit Credit Approve',
            'distances' => $this->minimalDistances($this->slugPrefix . '-cr-approve'),
        ]));
        $this->events->submit((string) $approvedCandidate['id']);
        self::assertSame(1, $this->organizerPackageCredits('phpunit-organizer'));

        $approved = $this->events->approve((string) $approvedCandidate['id'], 'phpunit-admin');
        self::assertNotNull($approved);
        self::assertSame('published', $approved['publicationStatus']);
        // Still 1 — approve() neither spends nor refunds.
        self::assertSame(1, $this->organizerPackageCredits('phpunit-organizer'));
    }

    /**
     * E5.6: mirrors OrderRepositoryTest::testConcurrentCreatesForSameProductSerializeOnTheRowLock()
     * (E5.2) and RegistrationRepositoryTest's E1.3 row-lock concurrency test —
     * with a single available credit, two simultaneous submit() calls for two
     * different Draft events owned by the same organizer must not both
     * succeed. The affected-rows-guarded UPDATE in
     * consumeOrganizerPackageCredit() has no explicit SELECT ... FOR UPDATE,
     * so this proves the UPDATE's own row lock is enough on its own: the
     * second connection blocks until the first commits/rolls back, then reads
     * the now-zero balance and loses, rather than both reading "1 available"
     * concurrently and double-spending the single credit.
     */
    public function testConcurrentSubmitsForSameOrganizerWithOneCreditOnlyOneSucceeds(): void
    {
        $this->setOrganizerPackageCredits('phpunit-organizer', 1);

        $eventA = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-race-a',
            'name' => 'PHPUnit Credit Race A',
            'distances' => $this->minimalDistances($this->slugPrefix . '-race-a'),
        ]));
        $eventB = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-race-b',
            'name' => 'PHPUnit Credit Race B',
            'distances' => $this->minimalDistances($this->slugPrefix . '-race-b'),
        ]));

        $connectionA = Connection::fromEnv();
        $eventsA = new EventRepository($connectionA);

        // Start connection A's submit transaction and hold it open past its
        // UPDATE (still uncommitted) by driving the transaction manually here
        // instead of through submit() directly, so connection B's competing
        // UPDATE is guaranteed to still be blocked when we issue it below.
        $connectionA->beginTransaction();
        $connectionA->prepare(
            'UPDATE users SET organizer_package_credits = organizer_package_credits - 1
             WHERE id = :organizerId AND organizer_package_credits > 0'
        )->execute(['organizerId' => 'phpunit-organizer']);

        $connectionB = Connection::fromEnv();
        $connectionB->exec('SET innodb_lock_wait_timeout = 1');
        $eventsB = new EventRepository($connectionB);

        try {
            $eventsB->submit((string) $eventB['id']);
            self::fail('Expected connection B to block on connection A\'s row lock and time out.');
        } catch (\PDOException $e) {
            self::assertStringContainsStringIgnoringCase('lock wait timeout', $e->getMessage());
        } finally {
            // Connection B's own submit() opened its own transaction before
            // blocking on the UPDATE. Whether that transaction is left
            // dangling after the timeout, or submit() has already rolled it
            // back itself on the PDOException, is not guaranteed the same
            // way on every engine/driver combination, so guard the rollback
            // instead of assuming it's still open — an unconditional
            // rollBack() throws "There is no active transaction" when
            // submit() already cleaned up after itself.
            if ($connectionB->inTransaction()) {
                $connectionB->rollBack();
            }
            $connectionA->commit();
        }

        // Connection A's spend committed; only one credit existed, so it's
        // gone now — a completely fresh attempt (via the main test
        // connection) must fail with the business error rather than
        // double-spending, and the event stays in Draft.
        self::assertSame(0, $this->organizerPackageCredits('phpunit-organizer'));
        try {
            $this->events->submit((string) $eventB['id']);
            self::fail('Expected event_submit_no_organizer_credit to be thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('event_submit_no_organizer_credit', $e->getMessage());
        }
        self::assertSame('draft', $this->events->find((string) $eventB['id'])['publicationStatus']);
        self::assertSame(0, $this->organizerPackageCredits('phpunit-organizer'));
    }

    private function setOrganizerPackageCredits(string $userId, int $count): void
    {
        $this->pdo->prepare('UPDATE users SET organizer_package_credits = :count WHERE id = :id')
            ->execute(['count' => $count, 'id' => $userId]);
    }

    private function organizerPackageCredits(string $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT organizer_package_credits FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function testManualStatusChangeCannotBypassModerationWorkflow(): void
    {
        $created = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-man-active',
            'name' => 'PHPUnit Manual Active Forbidden',
            'distances' => $this->minimalDistances($this->slugPrefix . '-man-active'),
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
            'slug' => $this->slugPrefix . '-cancel-pub',
            'name' => 'PHPUnit Cancel From Published',
            'status' => 'active',
            'distances' => $this->minimalDistances($this->slugPrefix . '-cancel-pub'),
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
            'slug' => $this->slugPrefix . '-vis-draft',
            'name' => 'PHPUnit Visibility From Draft Forbidden',
            'distances' => $this->minimalDistances($this->slugPrefix . '-vis-draft'),
        ]));
        self::assertNull($this->events->setVisibility((string) $draft['id'], 'private'));

        $published = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-vis-pub',
            'name' => 'PHPUnit Visibility From Published',
            'status' => 'active',
            'distances' => $this->minimalDistances($this->slugPrefix . '-vis-pub'),
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
     * E3.2: the pasted BZ organization token round-trips through create+update+read
     * exactly like the other flat per-Event columns (visibility/is_test) — no JSON
     * encode/decode step to lose or reshape it. Defaults to disabled/no-token for an
     * event that never touched this feature.
     */
    public function testBzIntegrationConfigDefaultsAndRoundTripsThroughCreateAndUpdate(): void
    {
        $created = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-bz-default',
        ]));
        self::assertFalse($created['bzIntegrationEnabled']);
        self::assertNull($created['bzOrganizationToken']);

        $enabled = $this->events->update((string) $created['id'], [
            'bzIntegrationEnabled' => true,
            'bzOrganizationToken' => 'ORG-ABC-123',
        ]);
        self::assertTrue($enabled['bzIntegrationEnabled']);
        self::assertSame('ORG-ABC-123', $enabled['bzOrganizationToken']);

        $reloaded = $this->events->find((string) $created['id']);
        self::assertTrue($reloaded['bzIntegrationEnabled']);
        self::assertSame('ORG-ABC-123', $reloaded['bzOrganizationToken']);
    }

    /**
     * E3.2: disabling never wipes the stored token at the repository level either —
     * the "preserve on disable" contract lives in the partial-update semantics of
     * eventData() (only columns present in the update payload are written), which
     * this exercises directly against the repository, not just through the HTTP
     * layer's validation in routes.php.
     */
    public function testBzIntegrationTokenSurvivesDisableWhenOmittedFromUpdate(): void
    {
        $created = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-bz-preserve',
            'bzIntegrationEnabled' => true,
            'bzOrganizationToken' => 'ORG-KEEP-ME',
        ]));
        self::assertTrue($created['bzIntegrationEnabled']);
        self::assertSame('ORG-KEEP-ME', $created['bzOrganizationToken']);

        $disabled = $this->events->update((string) $created['id'], [
            'bzIntegrationEnabled' => false,
        ]);
        self::assertFalse($disabled['bzIntegrationEnabled']);
        self::assertSame('ORG-KEEP-ME', $disabled['bzOrganizationToken']);

        $reEnabled = $this->events->update((string) $created['id'], [
            'bzIntegrationEnabled' => true,
        ]);
        self::assertTrue($reEnabled['bzIntegrationEnabled']);
        self::assertSame('ORG-KEEP-ME', $reEnabled['bzOrganizationToken']);
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
                'id' => $prefix . '-d-' . bin2hex(random_bytes(4)),
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

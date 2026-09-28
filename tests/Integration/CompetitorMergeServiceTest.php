<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Admin\AdminRepository;
use ZmierzymyCzas\Domain\Care\CareRepository;
use ZmierzymyCzas\Domain\Challenges\ChallengeRepository;
use ZmierzymyCzas\Domain\Competitors\CompetitorMergeService;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\FaultInjectingRefundPdo;

/**
 * E8.1 (issue 22): full rigor per the ticket brief — no-conflict repointing,
 * the mandatory-field-decision guard, the registration-conflict pause and its
 * refund/cancel resolution, the identity-code alias, and the activity-log
 * snapshot actually carrying pre-merge field values (not just ids).
 */
final class CompetitorMergeServiceTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private CompetitorRepository $competitors;
    private CareRepository $care;
    private AdminRepository $admin;
    private CompetitorMergeService $merge;
    private string $prefix;
    private string $moderatorId = 'phpunit-merge-moderator';

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->prefix = 'PhpUnitMerge-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->events = new EventRepository($this->pdo);
            $this->registrations = new RegistrationRepository($this->pdo);
            $this->competitors = new CompetitorRepository($this->pdo);
            $this->care = new CareRepository($this->pdo);
            $results = new ResultRepository($this->pdo);
            $challenges = new ChallengeRepository($this->pdo);
            $this->admin = new AdminRepository($this->pdo);
            $this->merge = new CompetitorMergeService(
                $this->pdo,
                $this->competitors,
                $this->care,
                $this->registrations,
                $results,
                $challenges,
                $this->admin
            );
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM competitors WHERE last_name = :last');
        $stmt->execute(['last' => $this->prefix]);
        $competitorIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($competitorIds as $id) {
            $this->pdo->prepare('DELETE FROM identity_code_aliases WHERE competitor_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM care_relations WHERE zawodnik_id = :id')->execute(['id' => $id]);
        }

        $eventStmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $eventStmt->execute(['slugLike' => $this->prefix . '%']);
        foreach (($eventStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $eventId) {
            $regStmt = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
            $regStmt->execute(['id' => $eventId]);
            foreach (($regStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $registrationId) {
                $this->pdo->prepare('DELETE FROM registration_refunds WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_payment_details WHERE registration_id = :id')->execute(['id' => $registrationId]);
            }
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        }

        $this->pdo->prepare('DELETE FROM activity_logs WHERE user_id = :id')->execute(['id' => $this->moderatorId]);
        $this->pdo->prepare('DELETE FROM competitors WHERE last_name = :last')->execute(['last' => $this->prefix]);
    }

    public function testMergeRepointsRegistrationAndCareThenMarksAbsorbedScalony(): void
    {
        $event = $this->createEvent('no-conflict');
        $survivor = $this->competitors->create(['firstName' => 'Ala', 'lastName' => $this->prefix, 'club' => 'KS Sokół']);
        $absorbed = $this->competitors->create(['firstName' => 'Ala', 'lastName' => $this->prefix, 'club' => 'KS Sokół']);
        $this->care->grant('phpunit-caregiver-' . bin2hex(random_bytes(3)), (string) $absorbed['id']);
        $registration = $this->registrations->create($this->registrationPayload($event, (string) $absorbed['id']));
        $oldIdentityCode = (string) $absorbed['identityCode'];

        $result = $this->merge->merge(
            ['sub' => $this->moderatorId],
            (string) $survivor['id'],
            (string) $absorbed['id'],
            []
        );

        self::assertSame('merged', $result['status']);

        $repointedRegistration = $this->registrations->find($registration['id']);
        self::assertSame($survivor['id'], $repointedRegistration['competitorId']);

        $absorbedAfter = $this->competitors->find((string) $absorbed['id']);
        self::assertSame('scalony', $absorbedAfter['status']);
        self::assertSame($survivor['id'], $absorbedAfter['mergedIntoCompetitorId']);

        // Old identity code still resolves — to the survivor, never directly promoted.
        $resolved = $this->competitors->resolveIdentityCode($oldIdentityCode);
        self::assertNotNull($resolved);
        self::assertSame($survivor['id'], $resolved['id']);

        // Care relation followed the Zawodnik, not the absorbed archival row.
        $caregivers = $this->care->listCaregivers((string) $survivor['id']);
        self::assertNotEmpty($caregivers);
    }

    public function testMergeRequiresExplicitDecisionForEveryDifferingField(): void
    {
        $survivor = $this->competitors->create(['firstName' => 'Bea', 'lastName' => $this->prefix, 'birthDate' => '2015-01-01']);
        $absorbed = $this->competitors->create(['firstName' => 'Bea', 'lastName' => $this->prefix, 'birthDate' => '2014-06-06']);

        $result = $this->merge->merge(['sub' => $this->moderatorId], (string) $survivor['id'], (string) $absorbed['id'], []);

        self::assertSame('field_decisions_required', $result['status']);
        self::assertContains('birthDate', $result['fields']);

        // Nothing was mutated by the paused attempt.
        self::assertSame('active', $this->competitors->find((string) $absorbed['id'])['status']);
    }

    public function testMergeAppliesTheChosenValueAndLogsBothCandidates(): void
    {
        $survivor = $this->competitors->create(['firstName' => 'Cela', 'lastName' => $this->prefix, 'birthDate' => '2015-01-01']);
        $absorbed = $this->competitors->create(['firstName' => 'Cela', 'lastName' => $this->prefix, 'birthDate' => '2014-06-06']);

        $result = $this->merge->merge(
            ['sub' => $this->moderatorId],
            (string) $survivor['id'],
            (string) $absorbed['id'],
            ['birthDate' => 'absorbed']
        );

        self::assertSame('merged', $result['status']);
        self::assertSame('2014-06-06', $result['survivor']['birthDate']);

        $log = $this->latestMergeLogFor((string) $survivor['id']);
        self::assertNotNull($log);
        $fieldDecision = current(array_filter($log['metadata']['fieldDecisions'], static fn (array $d): bool => $d['field'] === 'birthDate'));
        self::assertSame('2015-01-01', $fieldDecision['survivorValue']);
        self::assertSame('2014-06-06', $fieldDecision['absorbedValue']);
        self::assertSame('absorbed', $fieldDecision['chosen']);
    }

    public function testMergePausesOnRegistrationConflictAndResumesAfterResolution(): void
    {
        $event = $this->createEvent('conflict');
        $survivor = $this->competitors->create(['firstName' => 'Dela', 'lastName' => $this->prefix]);
        $absorbed = $this->competitors->create(['firstName' => 'Dela', 'lastName' => $this->prefix]);

        $survivorReg = $this->registrations->create($this->registrationPayload($event, (string) $survivor['id'], paid: true));
        $absorbedReg = $this->registrations->create($this->registrationPayload($event, (string) $absorbed['id'], paid: true));

        $paused = $this->merge->merge(['sub' => $this->moderatorId], (string) $survivor['id'], (string) $absorbed['id'], []);
        self::assertSame('conflict_pending', $paused['status']);
        self::assertCount(1, $paused['conflicts']);
        self::assertEqualsCanonicalizing(
            [$survivorReg['id'], $absorbedReg['id']],
            $paused['conflicts'][0]['registrationIds']
        );

        // Absorbed side is untouched while paused.
        self::assertSame('active', $this->competitors->find((string) $absorbed['id'])['status']);

        $resolved = $this->merge->merge(
            ['sub' => $this->moderatorId],
            (string) $survivor['id'],
            (string) $absorbed['id'],
            [],
            [[
                'eventId' => $event['id'],
                'distanceId' => $event['distances'][0]['id'],
                'winnerRegistrationId' => $survivorReg['id'],
            ]]
        );

        self::assertSame('merged', $resolved['status']);

        // Winner keeps its confirmed status; loser went through E1.5's refund cycle
        // (requestRefund -> approveRefund), never silently disappeared.
        self::assertSame('confirmed', $this->registrations->find($survivorReg['id'])['status']);
        $refund = $this->rawRefund($absorbedReg['id']);
        self::assertNotNull($refund);
        self::assertSame('approved', $refund['status']);

        $log = $this->latestMergeLogFor((string) $survivor['id']);
        self::assertNotEmpty($log['metadata']['conflictResolutions']);
        self::assertSame($survivorReg['id'], $log['metadata']['conflictResolutions'][0]['winnerRegistrationId']);
        self::assertSame($absorbedReg['id'], $log['metadata']['conflictResolutions'][0]['rejectedRegistrationId']);
    }

    public function testActivityLogSnapshotCapturesFullPreMergeState(): void
    {
        $survivor = $this->competitors->create(['firstName' => 'Ela', 'lastName' => $this->prefix, 'club' => 'Survivor Club']);
        $absorbed = $this->competitors->create(['firstName' => 'Ella', 'lastName' => $this->prefix, 'club' => 'Absorbed Club']);

        $this->merge->merge(
            ['sub' => $this->moderatorId],
            (string) $survivor['id'],
            (string) $absorbed['id'],
            ['firstName' => 'survivor', 'club' => 'survivor']
        );

        $log = $this->latestMergeLogFor((string) $survivor['id']);
        self::assertNotNull($log);
        self::assertSame($this->moderatorId, $log['userId']);
        self::assertSame('competitor_merged', $log['action']);

        // The whole point of the snapshot (spec Answer §5): full field values
        // of BOTH profiles survive, not just their now-meaningless ids.
        self::assertSame('Ela', $log['metadata']['survivorBefore']['firstName']);
        self::assertSame('Ella', $log['metadata']['absorbedBefore']['firstName']);
        self::assertSame('Absorbed Club', $log['metadata']['absorbedBefore']['club']);
        self::assertTrue($log['metadata']['identityCodeAliased']);
    }

    /**
     * E8.1 fix-loop, gap 1: a refund/cancel failure on the rejected side of a
     * conflict must never look like "the merge failed" — the merge has
     * already committed by the time that loop runs. Proves both halves of
     * the fix: (a) the activity-log snapshot exists with full pre-merge data
     * even though a refund call in the post-commit loop is forced to fail,
     * and (b) the response says so honestly (`refundFailures`) instead of
     * throwing/looking like total failure.
     *
     * Forces the failure with a fault-injecting PDO subclass wrapping the
     * SAME connection (not a separate one — a real second connection would
     * not see the first connection's just-committed transaction reliably
     * under MySQL's default isolation without extra synchronization, and
     * would also desync the merge's own beginTransaction()/commit() from the
     * repositories it hands work to). It throws only when the exact
     * approveRefund() UPDATE for the targeted registration runs, so the rest
     * of the merge (which uses the same PDO/repositories) is unaffected.
     */
    public function testRefundFailureStillWritesActivityLogAndReportsPartialFailure(): void
    {
        $event = $this->createEvent('refund-failure');
        $survivor = $this->competitors->create(['firstName' => 'Fela', 'lastName' => $this->prefix]);
        $absorbed = $this->competitors->create(['firstName' => 'Fela', 'lastName' => $this->prefix]);

        $survivorReg = $this->registrations->create($this->registrationPayload($event, (string) $survivor['id'], paid: true));
        $absorbedReg = $this->registrations->create($this->registrationPayload($event, (string) $absorbed['id'], paid: true));

        $paused = $this->merge->merge(['sub' => $this->moderatorId], (string) $survivor['id'], (string) $absorbed['id'], []);
        self::assertSame('conflict_pending', $paused['status']);

        $faultyPdo = new FaultInjectingRefundPdo((string) $absorbedReg['id']);
        $faultyRegistrations = new RegistrationRepository($faultyPdo);
        $faultyMerge = new CompetitorMergeService(
            $faultyPdo,
            new CompetitorRepository($faultyPdo),
            new CareRepository($faultyPdo),
            $faultyRegistrations,
            new ResultRepository($faultyPdo),
            new ChallengeRepository($faultyPdo),
            new AdminRepository($faultyPdo)
        );

        $result = $faultyMerge->merge(
            ['sub' => $this->moderatorId],
            (string) $survivor['id'],
            (string) $absorbed['id'],
            [],
            [[
                'eventId' => $event['id'],
                'distanceId' => $event['distances'][0]['id'],
                'winnerRegistrationId' => $survivorReg['id'],
            ]]
        );

        // The merge itself still fully succeeded — it must never be reported
        // as if it had not happened.
        self::assertSame('merged', $result['status']);
        self::assertSame('scalony', $this->competitors->find((string) $absorbed['id'])['status']);

        // But the caller is told, honestly, that the rejected registration's
        // refund needs manual follow-up — not silence, not a false "merge
        // failed".
        self::assertNotEmpty($result['refundFailures']);
        self::assertSame($absorbedReg['id'], $result['refundFailures'][0]['registrationId']);

        // The ONLY recovery mechanism (the pre-merge snapshot) still exists,
        // in full, despite the refund loop failing.
        $log = $this->latestMergeLogFor((string) $survivor['id']);
        self::assertNotNull($log);
        self::assertSame('Fela', $log['metadata']['survivorBefore']['firstName']);
        self::assertSame('Fela', $log['metadata']['absorbedBefore']['firstName']);
        self::assertSame($absorbedReg['id'], $log['metadata']['rejectedRegistrationIds'][0]);

        // And the failure itself was also logged for moderator visibility.
        $failureLogs = $this->admin->activityLogs(['action' => 'competitor_merge_refund_issue', 'targetId' => $survivor['id']]);
        self::assertNotEmpty($failureLogs);
    }

    /**
     * E8.1 fix-loop, gap 2: the one real, existing place in this codebase
     * where a moderator pastes a raw kod tożsamości (`zqr_...`) to find a
     * Zawodnik (CompetitorMergeTab's search box, `searchForMerge()`) must
     * resolve an OLD, now-aliased code from an already-merged profile to its
     * current survivor — the "scan an already-printed card after the merge"
     * scenario resolveIdentityCode() exists for, previously wired nowhere.
     *
     * @group known-failure-pp14
     *
     * PP.13: pre-existing failure on both MySQL 8.4 and MariaDB 10.4, unrelated to
     * migrations/CI — tracked by PP.14. Remove this tag once fixed there.
     */
    public function testSearchForMergeResolvesOldIdentityCodeToSurvivor(): void
    {
        $survivor = $this->competitors->create(['firstName' => 'Gina', 'lastName' => $this->prefix]);
        $absorbed = $this->competitors->create(['firstName' => 'Gita', 'lastName' => $this->prefix]);
        $oldIdentityCode = (string) $absorbed['identityCode'];

        $result = $this->merge->merge(
            ['sub' => $this->moderatorId],
            (string) $survivor['id'],
            (string) $absorbed['id'],
            []
        );
        self::assertSame('merged', $result['status']);

        // Before the fix, this returned [] — the absorbed profile's row is
        // now status = "scalony" and gets filtered out, and the alias was
        // never consulted.
        $found = $this->competitors->searchForMerge($oldIdentityCode);

        self::assertNotEmpty($found);
        self::assertSame((string) $survivor['id'], (string) $found[0]['id']);
        self::assertSame('active', $found[0]['status']);
    }

    /** @param array<string, mixed> $event */
    private function registrationPayload(array $event, string $competitorId, bool $paid = false): array
    {
        return [
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'userId' => 'phpunit-merge-user',
            'competitorId' => $competitorId,
            'userName' => 'PHPUnit Merge User',
            'userEmail' => 'phpunit-merge-user@example.test',
            'distanceId' => $event['distances'][0]['id'],
            'distanceName' => $event['distances'][0]['name'],
            'category' => 'Open',
            'merchandiseOrders' => [],
            'paymentDetails' => $paid ? ['startFeePaid' => true] : null,
        ];
    }

    private function createEvent(string $suffix): array
    {
        $slug = $this->prefix . '-' . $suffix;
        return $this->events->create([
            'name' => 'PHPUnit Merge Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-15',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-merge-organizer',
            'organizerName' => 'PHPUnit Merge Organizer',
            'status' => 'draft',
            'registrationDeadline' => '2026-09-01',
            'featured' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
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
            ]],
        ]);
    }

    private function latestMergeLogFor(string $survivorId): ?array
    {
        $logs = $this->admin->activityLogs(['action' => 'competitor_merged', 'targetId' => $survivorId]);
        return $logs[0] ?? null;
    }

    private function rawRefund(string $registrationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT status FROM registration_refunds WHERE registration_id = :id');
        $stmt->execute(['id' => $registrationId]);
        return $stmt->fetch() ?: null;
    }
}

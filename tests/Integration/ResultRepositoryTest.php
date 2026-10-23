<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Competitors\CompetitorNamePresenter;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;

/**
 * Manual create/update of a Result (POST/PATCH /results, backing
 * AddResultDialog.tsx) must enforce the same DNF/DNS/DSQ-excluded-from-
 * ranking rule that ResultImportRepository already enforces for CSV import
 *, and must keep sibling positions in the same
 * event+distance correct after a manual insert/edit - see
 * ResultRepository::renumberEventDistance().
 */
final class ResultRepositoryTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private ResultRepository $results;
    private UserRepository $users;
    private CompetitorRepository $competitors;
    private string $prefix;
    private array $event;
    /** @var array<int, string> */
    private array $createdCompetitorIds = [];

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->prefix = 'phpres-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->deletePhpUnitData();
            $this->events = new EventRepository($this->pdo);
            $this->registrations = new RegistrationRepository($this->pdo);
            $this->results = new ResultRepository($this->pdo);
            $this->users = new UserRepository($this->pdo);
            $this->competitors = new CompetitorRepository($this->pdo);
            $this->event = $this->events->create($this->eventPayload());
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for result repository tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        $this->deletePhpUnitData();
        foreach ($this->createdCompetitorIds as $competitorId) {
            $this->pdo->prepare('DELETE FROM competitors WHERE id = :id')->execute(['id' => $competitorId]);
        }
    }

    public function testManualCreateWithDnsStatusIsForcedToPositionZeroRegardlessOfPayload(): void
    {
        $participant = $this->createParticipant('Ewa Krol');

        // A manual entry (or a client bug) could still send a nonzero
        // position for a non-finished status - the repository must not
        // trust it.
        $created = $this->results->create($this->resultPayload($participant, [
            'status' => 'dns',
            'time' => '',
            'timeInSeconds' => 0,
            'position' => 5,
            'categoryPosition' => 3,
        ]));

        self::assertSame('dns', $created['status']);
        self::assertSame(0, (int) $created['position']);
        self::assertSame(0, (int) $created['categoryPosition']);
    }

    public function testManualCreateWithDnfStatusMayKeepAPartialTimeButNoPosition(): void
    {
        $participant = $this->createParticipant('Piotr Zielinski');

        $created = $this->results->create($this->resultPayload($participant, [
            'status' => 'dnf',
            'time' => '0:20:00',
            'timeInSeconds' => 1200,
            'position' => 7,
            'categoryPosition' => 4,
        ]));

        self::assertSame('dnf', $created['status']);
        self::assertSame('0:20:00', $created['time']);
        self::assertSame(0, (int) $created['position']);
        self::assertSame(0, (int) $created['categoryPosition']);
    }

    public function testManualUpdateFlippingStatusToDsqZeroesOutItsPosition(): void
    {
        $participant = $this->createParticipant('Tomasz Nowicki');
        $created = $this->results->create($this->resultPayload($participant, [
            'status' => 'finished',
            'time' => '0:30:00',
            'timeInSeconds' => 1800,
            'position' => 1,
            'categoryPosition' => 1,
        ]));

        $updated = $this->results->update((string) $created['id'], [
            'status' => 'dsq',
            // Payload still carries the old ranking - repository must
            // override it, not trust it.
            'position' => 1,
            'categoryPosition' => 1,
        ]);

        self::assertSame('dsq', $updated['status']);
        self::assertSame(0, (int) $updated['position']);
        self::assertSame(0, (int) $updated['categoryPosition']);
    }

    public function testManualNetTimeRoundTripsThroughCreateAndUpdate(): void
    {
        $participant = $this->createParticipant('Kamil Wojcik');

        $created = $this->results->create($this->resultPayload($participant, [
            'time' => '0:41:10',
            'timeInSeconds' => 2470,
            'netTime' => '0:40:58',
            'netTimeInSeconds' => 2458,
            'position' => 1,
            'categoryPosition' => 1,
        ]));

        self::assertSame('0:40:58', $created['netTime']);
        self::assertEqualsWithDelta(2458.0, (float) $created['netTimeInSeconds'], 0.001);

        $updated = $this->results->update((string) $created['id'], [
            'netTime' => '0:40:50',
            'netTimeInSeconds' => 2450,
        ]);

        self::assertSame('0:40:50', $updated['netTime']);
        self::assertEqualsWithDelta(2450.0, (float) $updated['netTimeInSeconds'], 0.001);
    }

    /**
     * Inserting a faster finisher into an event+distance that already has a
     * result must renumber the whole finished group by timeInSeconds
     * ascending (no explicit position given by either), mirroring
     * ResultImportRepository::positions()'s ordering rule.
     */
    public function testInsertingAFasterFinisherRenumbersExistingSiblings(): void
    {
        $slow = $this->createParticipant('Marta Wisniewska');
        $slowResult = $this->results->create($this->resultPayload($slow, [
            'time' => '1:00:00',
            'timeInSeconds' => 3600,
            'position' => 0,
            'categoryPosition' => 0,
        ]));
        self::assertSame(1, (int) $slowResult['position']);

        $fast = $this->createParticipant('Anna Nowak');
        $fastResult = $this->results->create($this->resultPayload($fast, [
            'time' => '0:30:00',
            'timeInSeconds' => 1800,
            'position' => 0,
            'categoryPosition' => 0,
        ]));

        self::assertSame(1, (int) $fastResult['position']);
        self::assertSame(1, (int) $fastResult['categoryPosition']);

        $refreshedSlow = $this->results->find((string) $slowResult['id']);
        self::assertSame(2, (int) $refreshedSlow['position']);
        self::assertSame(2, (int) $refreshedSlow['categoryPosition']);
    }

    /**
     * Flipping a result from `finished` to `dns` must not leave the
     * remaining finished sibling with a stale (skipped) position.
     */
    public function testFlippingAResultToDnsRenumbersRemainingFinishedSiblings(): void
    {
        $first = $this->createParticipant('Jan Kowalski');
        $firstResult = $this->results->create($this->resultPayload($first, [
            'time' => '0:30:00',
            'timeInSeconds' => 1800,
            'position' => 0,
            'categoryPosition' => 0,
        ]));

        $second = $this->createParticipant('Ewa Krol');
        $secondResult = $this->results->create($this->resultPayload($second, [
            'time' => '0:40:00',
            'timeInSeconds' => 2400,
            'position' => 0,
            'categoryPosition' => 0,
        ]));
        self::assertSame(1, (int) $firstResult['position']);
        self::assertSame(2, (int) $secondResult['position']);

        $this->results->update((string) $firstResult['id'], ['status' => 'dns', 'time' => '', 'timeInSeconds' => 0]);

        $refreshedSecond = $this->results->find((string) $secondResult['id']);
        self::assertSame(1, (int) $refreshedSecond['position']);
        self::assertSame(1, (int) $refreshedSecond['categoryPosition']);
    }

    /**
     * A freshly created result is "wstepne" without
     * any organizer action - publication is never the starting state.
     */
    public function testNewlyCreatedResultDefaultsToPreliminaryPublicationStatus(): void
    {
        $participant = $this->createParticipant('Preliminary Domyslnie');

        $created = $this->results->create($this->resultPayload($participant));

        self::assertSame('preliminary', $created['publicationStatus']);
    }

    /**
     * markOfficial() operates on the event+distance unit (same
     * granularity as renumberEventDistance()), not on a single Result -
     * every finished/dnf/dns/dsq row sharing that event+distance flips to
     * "official" in one call, and rows in a different distance are left
     * untouched.
     */
    public function testMarkOfficialFlipsEveryResultInTheEventDistanceButNotOtherDistances(): void
    {
        $first = $this->createParticipant('Official Pierwszy');
        $firstResult = $this->results->create($this->resultPayload($first));
        $second = $this->createParticipant('Official Drugi');
        $secondResult = $this->results->create($this->resultPayload($second, ['bibNumber' => '2']));

        $otherDistanceId = $this->prefix . '-other-distance';
        $otherParticipant = $this->createParticipant('Official Inny Dystans');
        $otherResult = $this->results->create($this->resultPayload($otherParticipant, [
            'bibNumber' => '3',
            'distanceId' => $otherDistanceId,
            'distanceName' => 'Inny dystans',
        ]));

        $updatedCount = $this->results->markOfficial((string) $this->event['id'], (string) $this->event['distances'][0]['id']);

        self::assertSame(2, $updatedCount);
        self::assertSame('official', $this->results->find((string) $firstResult['id'])['publicationStatus']);
        self::assertSame('official', $this->results->find((string) $secondResult['id'])['publicationStatus']);
        self::assertSame('preliminary', $this->results->find((string) $otherResult['id'])['publicationStatus']);
    }

    /**
     * "Korekta jest mozliwa zawsze, takze po
     * ogloszeniu oficjalnych" - a correction must not silently revert the
     * set back to preliminary. update() doesn't even know about the column,
     * so this asserts the observable guarantee: official stays official
     * across an unrelated field correction.
     */
    public function testCorrectingAnOfficialResultDoesNotRevertItToPreliminary(): void
    {
        $participant = $this->createParticipant('Korekta Po Oficjalnym');
        $created = $this->results->create($this->resultPayload($participant));
        $this->results->markOfficial((string) $this->event['id'], (string) $this->event['distances'][0]['id']);
        self::assertSame('official', $this->results->find((string) $created['id'])['publicationStatus']);

        $updated = $this->results->update((string) $created['id'], [
            'time' => '0:59:00',
            'timeInSeconds' => 3540,
        ]);

        self::assertSame('official', $updated['publicationStatus']);
    }

    /**
     * A competitor's lifetime record for a distance
     * length only counts status='finished' rows - dnf/dns/dsq are not "an
     * actually completed" live-timed start, even though dnf can carry a
     * partial time.
     */
    public function testLifetimeRecordExcludesDnfDnsDsqResults(): void
    {
        [$account, $competitorId] = $this->createCompetitorWithAccount('Rekord Bez Ukonczenia');
        $registrationId = $this->insertRegistration((string) $this->event['id'], (string) $this->event['distances'][0]['id'], $competitorId, $account);

        $this->results->create($this->resultPayload($account, [
            'registrationId' => $registrationId,
            'status' => 'dnf',
            'time' => '0:10:00',
            'timeInSeconds' => 600,
        ]));

        $records = $this->results->lifetimeRecordsForCompetitor($competitorId);

        self::assertSame([], $records, 'A dnf result must not produce a lifetime record.');
    }

    /**
     * "wynik wirtualny ... jest wykluczony, nawet po
     * weryfikacji organizatora" - a result materialized from an approved
     * virtual submission (is_virtual = 1, see VirtualResultRepository) must
     * never win a record, even when it is the only/fastest qualifying-looking
     * row.
     */
    public function testLifetimeRecordExcludesVirtualResults(): void
    {
        [$account, $competitorId] = $this->createCompetitorWithAccount('Rekord Wirtualny Wykluczony');
        $registrationId = $this->insertRegistration((string) $this->event['id'], (string) $this->event['distances'][0]['id'], $competitorId, $account);

        $created = $this->results->create($this->resultPayload($account, [
            'registrationId' => $registrationId,
            'time' => '0:20:00',
            'timeInSeconds' => 1200,
        ]));
        $this->pdo->prepare('UPDATE results SET is_virtual = 1 WHERE id = :id')->execute(['id' => $created['id']]);

        $records = $this->results->lifetimeRecordsForCompetitor($competitorId);

        self::assertSame([], $records, 'A virtual-origin result must not produce a lifetime record.');
    }

    /**
     * Core acceptance criterion: the record groups by distance LENGTH,
     * not by event_id/distance_id - two different Events, each with their own
     * event_distances row but the same length, must be treated as "the same
     * distance" for record purposes, and the lower gross time (regardless of
     * which Event it was run at) wins.
     */
    public function testLifetimeRecordGroupsAcrossDifferentEventsByDistanceLengthAndKeepsTheFastestTime(): void
    {
        [$account, $competitorId] = $this->createCompetitorWithAccount('Rekord Ponad Wydarzeniami');

        $firstRegistrationId = $this->insertRegistration((string) $this->event['id'], (string) $this->event['distances'][0]['id'], $competitorId, $account);
        $slower = $this->results->create($this->resultPayload($account, [
            'registrationId' => $firstRegistrationId,
            'time' => '1:00:00',
            'timeInSeconds' => 3600,
        ]));

        // Second Event, own event_distances row, same length (10 km) but a
        // deliberately different label - proves the grouping key is length,
        // not distance_name/distance_id/event_id.
        $secondEvent = $this->events->create($this->eventPayload([
            'slug' => $this->prefix . '-second-event',
            'distances' => [[
                'id' => $this->prefix . '-10k-relabeled',
                'name' => 'Bieg na 10 kilometrow',
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
        ]));
        $secondRegistrationId = $this->insertRegistration((string) $secondEvent['id'], (string) $secondEvent['distances'][0]['id'], $competitorId, $account);
        $faster = $this->results->create(array_merge($this->resultPayload($account, [
            'registrationId' => $secondRegistrationId,
            'time' => '0:45:00',
            'timeInSeconds' => 2700,
        ]), [
            'eventId' => $secondEvent['id'],
            'eventName' => $secondEvent['name'],
            'distanceId' => $secondEvent['distances'][0]['id'],
            'distanceName' => $secondEvent['distances'][0]['name'],
        ]));

        $records = $this->results->lifetimeRecordsForCompetitor($competitorId);

        self::assertCount(1, $records, 'Same-length distances across two Events must collapse into a single record.');
        self::assertSame((string) $faster['id'], $records[0]['resultId']);
        self::assertSame(2700.0, $records[0]['timeInSeconds']);
        self::assertSame((string) $secondEvent['id'], $records[0]['eventId']);
        self::assertEqualsWithDelta(10000.0, $records[0]['distanceLengthMeters'], 0.001);
        // The slower run at the first Event must not linger anywhere in the
        // result set.
        foreach ($records as $record) {
            self::assertNotSame((string) $slower['id'], $record['resultId']);
        }
    }

    /**
     * event_distances.length is nullable (migration 014) for
     * legacy/incomplete distances - those fall back to grouping by
     * distance_name rather than being silently dropped.
     */
    public function testLifetimeRecordFallsBackToNameGroupingWhenDistanceLengthIsNull(): void
    {
        $this->pdo->prepare('UPDATE event_distances SET length = NULL WHERE id = :id')
            ->execute(['id' => $this->event['distances'][0]['id']]);

        [$account, $competitorId] = $this->createCompetitorWithAccount('Rekord Bez Dlugosci');
        $registrationId = $this->insertRegistration((string) $this->event['id'], (string) $this->event['distances'][0]['id'], $competitorId, $account);
        $created = $this->results->create($this->resultPayload($account, [
            'registrationId' => $registrationId,
            'time' => '0:50:00',
            'timeInSeconds' => 3000,
        ]));

        $records = $this->results->lifetimeRecordsForCompetitor($competitorId);

        self::assertCount(1, $records);
        self::assertSame((string) $created['id'], $records[0]['resultId']);
        self::assertNull($records[0]['distanceLengthMeters']);
        self::assertSame($this->event['distances'][0]['name'], $records[0]['distanceLabel']);
    }

    /**
     * A correction to a result's time (PATCH
     * /results/{id} -> update()) must be reflected in the record because it
     * is a live query, not a stored/cached column - no separate
     * invalidation/recompute step exists or is needed.
     */
    public function testLifetimeRecordReflectsATimeCorrectionWithNoSeparateRecomputeStep(): void
    {
        [$account, $competitorId] = $this->createCompetitorWithAccount('Rekord Po Korekcie');
        $registrationId = $this->insertRegistration((string) $this->event['id'], (string) $this->event['distances'][0]['id'], $competitorId, $account);
        $created = $this->results->create($this->resultPayload($account, [
            'registrationId' => $registrationId,
            'time' => '1:00:00',
            'timeInSeconds' => 3600,
        ]));

        self::assertSame(3600.0, $this->results->lifetimeRecordsForCompetitor($competitorId)[0]['timeInSeconds']);

        $this->results->update((string) $created['id'], ['time' => '0:55:00', 'timeInSeconds' => 3300]);

        $records = $this->results->lifetimeRecordsForCompetitor($competitorId);
        self::assertSame(3300.0, $records[0]['timeInSeconds'], 'A corrected time must be picked up immediately - the record is a live query.');
    }

    /**
     * Competitor identity resolution falls back to results.user_id =
     * competitors.user_id (a Zawodnik's own Konto) when a result has no
     * registration_id at all - e.g. an older/manual entry, per
     * ResultRepository::lifetimeRecordsForCompetitor()'s docblock.
     */
    public function testLifetimeRecordFallsBackToUserIdWhenResultHasNoRegistrationId(): void
    {
        $participant = $this->createParticipant('Rekord Bez Zapisu');
        $competitor = $this->competitors->ensureForUser($participant);
        $this->createdCompetitorIds[] = (string) $competitor['id'];

        // No 'registrationId' key at all - mirrors AddResultDialog.tsx's manual
        // entry payload, which never sends one.
        $created = $this->results->create($this->resultPayload($participant, [
            'time' => '0:44:00',
            'timeInSeconds' => 2640,
        ]));
        self::assertNull($created['registrationId']);

        $records = $this->results->lifetimeRecordsForCompetitor((string) $competitor['id']);

        self::assertCount(1, $records);
        self::assertSame((string) $created['id'], $records[0]['resultId']);
    }

    /**
     * `GET /events/{eventId}/results` is public and
     * unauthenticated (routes.php) — byEvent() must mask a competitor's real
     * name via the registration_id -> registrations.competitor_id chain
     * (the authoritative path per lifetimeRecordsForCompetitor()'s own
     * docblock) once that competitor opts into anonymization.
     */
    public function testAnonymizedCompetitorsRealNameIsMaskedOnThePublicByEventClassification(): void
    {
        [$account, $competitorId] = $this->createCompetitorWithAccount('Rekord Anonimowy');
        $this->competitors->setAnonymization($competitorId, true);
        $registrationId = $this->insertRegistration((string) $this->event['id'], (string) $this->event['distances'][0]['id'], $competitorId, $account);

        $created = $this->results->create($this->resultPayload($account, [
            'registrationId' => $registrationId,
        ]));

        $rows = $this->results->byEvent((string) $this->event['id']);
        $entry = current(array_filter($rows, static fn (array $r): bool => $r['id'] === $created['id']));

        self::assertNotFalse($entry);
        self::assertSame(CompetitorNamePresenter::anonymousLabel($competitorId), $entry['userName']);
        self::assertStringNotContainsString('Rekord', $entry['userName']);
    }

    /** Non-anonymized competitor must keep appearing with their real name — no false-positive masking. */
    public function testNonAnonymizedCompetitorsNameAppearsNormallyOnThePublicByEventClassification(): void
    {
        [$account, $competitorId] = $this->createCompetitorWithAccount('Rekord Widoczny');
        $registrationId = $this->insertRegistration((string) $this->event['id'], (string) $this->event['distances'][0]['id'], $competitorId, $account);

        $created = $this->results->create($this->resultPayload($account, [
            'registrationId' => $registrationId,
        ]));

        $rows = $this->results->byEvent((string) $this->event['id']);
        $entry = current(array_filter($rows, static fn (array $r): bool => $r['id'] === $created['id']));

        self::assertNotFalse($entry);
        self::assertSame($account['name'], $entry['userName']);
    }

    /**
     * A result with no registration_id at all (older/manual entry) falls
     * back to results.user_id = competitors.user_id — the same fallback
     * lifetimeRecordsForCompetitor() already documents and relies on.
     */
    public function testAnonymizedCompetitorIsMaskedViaUserIdFallbackWhenResultHasNoRegistrationId(): void
    {
        $participant = $this->createParticipant('Rekord Bez Zapisu Anonim');
        $competitor = $this->competitors->ensureForUser($participant);
        $this->createdCompetitorIds[] = (string) $competitor['id'];
        $this->competitors->setAnonymization((string) $competitor['id'], true);

        $created = $this->results->create($this->resultPayload($participant));
        self::assertNull($created['registrationId']);

        $rows = $this->results->byEvent((string) $this->event['id']);
        $entry = current(array_filter($rows, static fn (array $r): bool => $r['id'] === $created['id']));

        self::assertNotFalse($entry);
        self::assertSame(CompetitorNamePresenter::anonymousLabel((string) $competitor['id']), $entry['userName']);
    }

    /**
     * `GET /results` (list()) is just as public/unauthenticated
     * as `GET /events/{eventId}/results` (routes.php has no AuthMiddleware on
     * either) and reads the exact same `results` table, so it must mask an
     * anonymized competitor's real name the same way byEvent() already does.
     */
    public function testAnonymizedCompetitorsRealNameIsMaskedOnThePublicResultsList(): void
    {
        [$account, $competitorId] = $this->createCompetitorWithAccount('Lista Anonimowa');
        $this->competitors->setAnonymization($competitorId, true);
        $registrationId = $this->insertRegistration((string) $this->event['id'], (string) $this->event['distances'][0]['id'], $competitorId, $account);

        $created = $this->results->create($this->resultPayload($account, [
            'registrationId' => $registrationId,
        ]));

        $page = $this->results->list(['eventId' => $this->event['id']]);
        $entry = current(array_filter($page['data'], static fn (array $r): bool => $r['id'] === $created['id']));

        self::assertNotFalse($entry);
        self::assertSame(CompetitorNamePresenter::anonymousLabel($competitorId), $entry['userName']);
        self::assertStringNotContainsString('Lista', $entry['userName']);
    }

    /**
     * Masking only the name is not enough: userId / registrationId / birthYear on an anonymised
     * row would still identify the person in the public classification and list.
     */
    public function testAnonymizedRowsDropIdentifyingFieldsOnPublicClassificationAndList(): void
    {
        [$account, $competitorId] = $this->createCompetitorWithAccount('Tozsamosc Ukryta');
        $this->competitors->setAnonymization($competitorId, true);
        $registrationId = $this->insertRegistration((string) $this->event['id'], (string) $this->event['distances'][0]['id'], $competitorId, $account);
        $created = $this->results->create($this->resultPayload($account, [
            'registrationId' => $registrationId,
            'birthYear' => 1990,
        ]));
        self::assertSame(1990, (int) $this->pdo->query("SELECT birth_year FROM results WHERE id = '" . $created['id'] . "'")->fetchColumn());

        $rows = [
            $this->results->byEvent((string) $this->event['id']),
            $this->results->list(['eventId' => $this->event['id']])['data'],
        ];
        foreach ($rows as $set) {
            $entry = current(array_filter($set, static fn (array $r): bool => $r['id'] === $created['id']));
            self::assertNotFalse($entry);
            self::assertNull($entry['userId']);
            self::assertNull($entry['registrationId']);
            self::assertNull($entry['birthYear']);
        }
    }

    /** A public search by real name must not find (and so confirm) an anonymised competitor's result. */
    public function testPublicSearchDoesNotMatchAnonymizedCompetitorsRealName(): void
    {
        [$account, $competitorId] = $this->createCompetitorWithAccount('Szukany Anonim');
        $this->competitors->setAnonymization($competitorId, true);
        $registrationId = $this->insertRegistration((string) $this->event['id'], (string) $this->event['distances'][0]['id'], $competitorId, $account);
        $created = $this->results->create($this->resultPayload($account, ['registrationId' => $registrationId]));

        $page = $this->results->list(['eventId' => $this->event['id'], 'search' => 'Szukany']);

        self::assertSame(0, $page['total']);
        self::assertSame([], array_column($page['data'], 'id'));
        // Without the filter the (masked) row is still listed.
        self::assertContains($created['id'], array_column($this->results->list(['eventId' => $this->event['id']])['data'], 'id'));
    }

    /** Non-anonymized competitor must keep appearing with their real name on the public list - no false-positive masking. */
    public function testNonAnonymizedCompetitorsNameAppearsNormallyOnThePublicResultsList(): void
    {
        [$account, $competitorId] = $this->createCompetitorWithAccount('Lista Widoczna');
        $registrationId = $this->insertRegistration((string) $this->event['id'], (string) $this->event['distances'][0]['id'], $competitorId, $account);

        $created = $this->results->create($this->resultPayload($account, [
            'registrationId' => $registrationId,
        ]));

        $page = $this->results->list(['eventId' => $this->event['id']]);
        $entry = current(array_filter($page['data'], static fn (array $r): bool => $r['id'] === $created['id']));

        self::assertNotFalse($entry);
        self::assertSame($account['name'], $entry['userName']);
    }

    /**
     * Same registration_id-missing fallback as byEvent()'s equivalent test -
     * falls back to results.user_id = competitors.user_id.
     */
    public function testAnonymizedCompetitorIsMaskedOnThePublicResultsListViaUserIdFallback(): void
    {
        $participant = $this->createParticipant('Lista Bez Zapisu Anonim');
        $competitor = $this->competitors->ensureForUser($participant);
        $this->createdCompetitorIds[] = (string) $competitor['id'];
        $this->competitors->setAnonymization((string) $competitor['id'], true);

        $created = $this->results->create($this->resultPayload($participant));
        self::assertNull($created['registrationId']);

        $page = $this->results->list(['eventId' => $this->event['id']]);
        $entry = current(array_filter($page['data'], static fn (array $r): bool => $r['id'] === $created['id']));

        self::assertNotFalse($entry);
        self::assertSame(CompetitorNamePresenter::anonymousLabel((string) $competitor['id']), $entry['userName']);
    }

    /**
     * `GET /users/{userId}/results` (byUser()) is just as
     * public/unauthenticated as byEvent() and reads the same `results` table -
     * same leak, same fix.
     */
    public function testAnonymizedCompetitorsRealNameIsMaskedOnThePublicByUserResults(): void
    {
        [$account, $competitorId] = $this->createCompetitorWithAccount('Uzytkownik Anonimowy');
        $this->competitors->setAnonymization($competitorId, true);
        $registrationId = $this->insertRegistration((string) $this->event['id'], (string) $this->event['distances'][0]['id'], $competitorId, $account);

        $created = $this->results->create($this->resultPayload($account, [
            'registrationId' => $registrationId,
        ]));

        $rows = $this->results->byUser((string) $account['id']);
        $entry = current(array_filter($rows, static fn (array $r): bool => $r['id'] === $created['id']));

        self::assertNotFalse($entry);
        self::assertSame(CompetitorNamePresenter::anonymousLabel($competitorId), $entry['userName']);
        self::assertStringNotContainsString('Uzytkownik', $entry['userName']);
    }

    /** Non-anonymized competitor must keep appearing with their real name on byUser() - no false-positive masking. */
    public function testNonAnonymizedCompetitorsNameAppearsNormallyOnThePublicByUserResults(): void
    {
        [$account, $competitorId] = $this->createCompetitorWithAccount('Uzytkownik Widoczny');
        $registrationId = $this->insertRegistration((string) $this->event['id'], (string) $this->event['distances'][0]['id'], $competitorId, $account);

        $created = $this->results->create($this->resultPayload($account, [
            'registrationId' => $registrationId,
        ]));

        $rows = $this->results->byUser((string) $account['id']);
        $entry = current(array_filter($rows, static fn (array $r): bool => $r['id'] === $created['id']));

        self::assertNotFalse($entry);
        self::assertSame($account['name'], $entry['userName']);
    }

    /**
     * Same registration_id-missing fallback as byEvent()'s equivalent test -
     * falls back to results.user_id = competitors.user_id.
     */
    public function testAnonymizedCompetitorIsMaskedOnThePublicByUserResultsViaUserIdFallback(): void
    {
        $participant = $this->createParticipant('Uzytkownik Bez Zapisu Anonim');
        $competitor = $this->competitors->ensureForUser($participant);
        $this->createdCompetitorIds[] = (string) $competitor['id'];
        $this->competitors->setAnonymization((string) $competitor['id'], true);

        $created = $this->results->create($this->resultPayload($participant));
        self::assertNull($created['registrationId']);

        $rows = $this->results->byUser((string) $participant['id']);
        $entry = current(array_filter($rows, static fn (array $r): bool => $r['id'] === $created['id']));

        self::assertNotFalse($entry);
        self::assertSame(CompetitorNamePresenter::anonymousLabel((string) $competitor['id']), $entry['userName']);
    }

    /**
     * Creates a real Konto (so results.user_id/user_name have a genuine owner,
     * matching how `AddResultDialog.tsx`/CSV import always populate them) plus
     * its auto-provisioned Zawodnik profile (CompetitorRepository::ensureForUser(),
     * the same one-per-Konto backfill migration 027 established).
     *
     * @return array{0: array{id: string, name: string}, 1: string} [account shaped like createParticipant()'s return, competitorId]
     */
    private function createCompetitorWithAccount(string $name): array
    {
        $account = $this->createParticipant($name);
        $competitor = $this->competitors->ensureForUser($account);
        $this->createdCompetitorIds[] = (string) $competitor['id'];

        return [$account, (string) $competitor['id']];
    }

    /**
     * Registrations carry the authoritative competitor_id link (migration 027)
     * that results.registration_id points to. RegistrationRepository::create()
     * pulls in pricing/capacity machinery this test suite doesn't need to
     * exercise, so this inserts the minimal valid row directly - the same
     * shortcut ResultsContractTest already takes for schema not exposed
     * through a repository method.
     *
     * @param array{id: string, name: string} $account
     */
    private function insertRegistration(string $eventId, string $distanceId, string $competitorId, array $account): string
    {
        $id = $this->prefix . '-reg-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO registrations (id, event_id, event_name, user_id, competitor_id, user_name, user_email, distance_id, distance_name, category, registered_at, status, qr_code)
             VALUES (:id, :eventId, :eventName, :userId, :competitorId, :userName, :userEmail, :distanceId, :distanceName, :category, :registeredAt, :status, :qrCode)'
        )->execute([
            'id' => $id,
            'eventId' => $eventId,
            'eventName' => $this->event['name'],
            'userId' => $account['id'],
            'competitorId' => $competitorId,
            'userName' => $account['name'],
            'userEmail' => $this->prefix . '@example.test',
            'distanceId' => $distanceId,
            'distanceName' => $this->event['distances'][0]['name'],
            'category' => 'Open',
            'registeredAt' => date(DATE_ATOM),
            'status' => 'confirmed',
            // qr_code has a UNIQUE index - the column defaults to ''
            // when omitted, which collides across more than one row inserted
            // by this helper within the same test.
            'qrCode' => 'phpres_' . bin2hex(random_bytes(16)),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function resultPayload(array $participant, array $overrides = []): array
    {
        return array_merge([
            'eventId' => $this->event['id'],
            'eventName' => $this->event['name'],
            'distanceId' => $this->event['distances'][0]['id'],
            'distanceName' => $this->event['distances'][0]['name'],
            'userId' => $participant['id'],
            'userName' => $participant['name'],
            'bibNumber' => '1',
            'category' => 'Open',
            'time' => '1:00:00',
            'timeInSeconds' => 3600,
            'position' => 0,
            'categoryPosition' => 0,
            'status' => 'finished',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function createParticipant(string $name): array
    {
        return $this->users->create([
            'name' => $name,
            'email' => $this->prefix . '.' . strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(2)) . '@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function eventPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'PHPUnit Result Event',
            'slug' => $this->prefix . '-event',
            'discipline' => 'running',
            'date' => '2026-06-15',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->prefix . '-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'active',
            'registrationDeadline' => '2026-06-01',
            'featured' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [
                [
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
                ],
            ],
        ], $overrides);
    }

    private function deletePhpUnitData(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => $this->prefix . '%']);
        foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $eventId) {
            $resultStmt = $this->pdo->prepare('SELECT id FROM results WHERE event_id = :id');
            $resultStmt->execute(['id' => $eventId]);
            foreach (($resultStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $resultId) {
                $this->pdo->prepare('DELETE FROM result_splits WHERE result_id = :id')->execute(['id' => $resultId]);
            }

            $this->pdo->prepare('DELETE FROM results WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distance_pricing_variants WHERE distance_id IN (SELECT id FROM event_distances WHERE event_id = :id)')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        }

        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '.%@example.test']);
    }
}

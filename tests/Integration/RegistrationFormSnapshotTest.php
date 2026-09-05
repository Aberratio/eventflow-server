<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldType;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;

/**
 * E1.14 ("Formularz: snapshot danych przy zakończeniu Wydarzenia + korekta
 * wsteczna ze śladem"). Covers the repository-level acceptance criteria:
 * a finished Event's Registration freezes its public form-field values on
 * first read, a later Competitor edit no longer reaches that frozen payload,
 * and a not-yet-finished Registration keeps reading live (E1.13's existing
 * behavior, unchanged).
 *
 * The Event's `publication_status` is flipped to `finished` directly via SQL
 * rather than by waiting on EventRepository::lazilyFinishPastEvents()'s own
 * date-based trigger — RegistrationRepository::eventIsFinished() only ever
 * reads that column, so this is equivalent to (and far less flaky than)
 * fast-forwarding a real Event past its date, and is exactly the same
 * "flip the column directly" shortcut FormFieldRepositoryTest's siblings use
 * for other lazily-derived states in this codebase.
 */
final class RegistrationFormSnapshotTest extends TestCase
{
    private PDO $pdo;
    private FormFieldRepository $fields;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private CompetitorRepository $competitors;
    private string $slugPrefix;
    private string $eventId;
    private string $distanceId;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->slugPrefix = 'phpunit-formsnap-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->fields = new FormFieldRepository($this->pdo);
            $this->events = new EventRepository($this->pdo);
            $this->registrations = new RegistrationRepository($this->pdo, null, null, null);
            $this->competitors = new CompetitorRepository($this->pdo);
            $this->eventId = $this->createEvent();
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->slugPrefix)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slug');
        $stmt->execute(['slug' => $this->slugPrefix . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM competitors WHERE first_name LIKE :name')->execute(['name' => 'PHPUnit Snapshot%']);
    }

    /**
     * Wires a publicListVisible custom field ("Rozmiar koszulki") and the
     * system `first_name` field onto the public list, creates a Registration
     * whose Competitor's first name is "PHPUnit Snapshot Before", finishes the
     * Event, and checks the very first publicStartList() read after finish
     * returns exactly that live value — proving the captured snapshot matches
     * what was live at the moment of finish.
     */
    public function testSnapshotIsCapturedAtFinishAndMatchesWhatWasLiveAtThatMoment(): void
    {
        [$registrationId, $competitorId] = $this->createRegistrationWithCompetitor('PHPUnit Snapshot Before');
        $this->makeFirstNameAndShirtSizePublic();

        $this->finishEvent();

        $entry = $this->findInStartList($registrationId);
        $valuesByKey = array_column($entry['formFields'], 'value', 'fieldKey');
        self::assertSame('PHPUnit Snapshot Before', $valuesByKey['first_name']);

        $stmt = $this->pdo->prepare('SELECT form_snapshot_captured_at AS capturedAt FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $registrationId]);
        self::assertNotNull($stmt->fetch()['capturedAt'], 'First read after finish must stamp form_snapshot_captured_at.');
    }

    /**
     * The core "zamarzanie" guarantee: once captured, a later Competitor edit
     * (a real profile change, not a correction through this ticket's audited
     * endpoint) must never reach a subsequent read — the public start list for
     * a finished Event keeps serving the frozen value.
     */
    public function testPostFinishReadServesTheFrozenSnapshotEvenAfterTheCompetitorIsEditedAfterward(): void
    {
        [$registrationId, $competitorId] = $this->createRegistrationWithCompetitor('PHPUnit Snapshot Original');
        $this->makeFirstNameAndShirtSizePublic();
        $this->finishEvent();

        // First read captures the snapshot.
        $before = $this->findInStartList($registrationId);
        self::assertSame('PHPUnit Snapshot Original', array_column($before['formFields'], 'value', 'fieldKey')['first_name']);

        // A live Competitor edit after the Event is finished...
        $this->competitors->update($competitorId, ['firstName' => 'PHPUnit Snapshot Edited']);

        // ...must not surface on a later read: the frozen snapshot wins.
        $after = $this->findInStartList($registrationId);
        self::assertSame(
            'PHPUnit Snapshot Original',
            array_column($after['formFields'], 'value', 'fieldKey')['first_name'],
            'A finished Event must keep serving its frozen snapshot, not the live (edited) Competitor value.'
        );
    }

    /** Pre-finish behavior must stay exactly as E1.13 built it: live, so a typo fix keeps working. */
    public function testPreFinishReadStaysLiveAndReflectsACompetitorEditImmediately(): void
    {
        [$registrationId, $competitorId] = $this->createRegistrationWithCompetitor('PHPUnit Snapshot Typo');
        $this->makeFirstNameAndShirtSizePublic();
        // Event is deliberately left un-finished (still 'published' from createEvent()).

        $before = $this->findInStartList($registrationId);
        self::assertSame('PHPUnit Snapshot Typo', array_column($before['formFields'], 'value', 'fieldKey')['first_name']);

        $this->competitors->update($competitorId, ['firstName' => 'PHPUnit Snapshot Fixed']);

        $after = $this->findInStartList($registrationId);
        self::assertSame(
            'PHPUnit Snapshot Fixed',
            array_column($after['formFields'], 'value', 'fieldKey')['first_name'],
            'Before finish, the public start list must keep reading the live Competitor value.'
        );

        $stmt = $this->pdo->prepare('SELECT form_snapshot_captured_at AS capturedAt FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $registrationId]);
        self::assertNull($stmt->fetch()['capturedAt'], 'Nothing should be captured before the Event is finished.');
    }

    /** correctFormSnapshot() itself: merges into the frozen payload and reports old/new for the caller's audit log. */
    public function testCorrectFormSnapshotUpdatesTheFrozenValueAndReportsOldAndNew(): void
    {
        [$registrationId] = $this->createRegistrationWithCompetitor('PHPUnit Snapshot Correctable');
        $this->makeFirstNameAndShirtSizePublic();
        $this->finishEvent();
        $this->findInStartList($registrationId); // captures the snapshot

        $result = $this->registrations->correctFormSnapshot($registrationId, ['first_name' => 'PHPUnit Snapshot Corrected']);

        self::assertSame('PHPUnit Snapshot Correctable', $result['changes']['first_name']['old']);
        self::assertSame('PHPUnit Snapshot Corrected', $result['changes']['first_name']['new']);

        $snapshot = $this->registrations->formSnapshotFor($registrationId);
        self::assertSame('PHPUnit Snapshot Corrected', array_column($snapshot, 'value', 'fieldKey')['first_name']);

        // A later read must keep serving the corrected value, not recompute it
        // back to the (unrelated, never-edited) live Competitor value.
        $after = $this->findInStartList($registrationId);
        self::assertSame('PHPUnit Snapshot Corrected', array_column($after['formFields'], 'value', 'fieldKey')['first_name']);
    }

    /** The correction path must reject outright for a Registration whose Event isn't finished yet. */
    public function testCorrectFormSnapshotRejectsWhenEventIsNotFinished(): void
    {
        [$registrationId] = $this->createRegistrationWithCompetitor('PHPUnit Snapshot NotFinished');
        $this->makeFirstNameAndShirtSizePublic();
        // Event stays 'published' — never finished.

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('registration_form_snapshot_not_finished');
        $this->registrations->correctFormSnapshot($registrationId, ['first_name' => 'Should Not Apply']);
    }

    private function makeFirstNameAndShirtSizePublic(): void
    {
        $this->fields->ensureSystemCatalog($this->eventId);
        $firstName = $this->findFieldByKey('first_name');
        $this->fields->updatePublicListVisibility($firstName['id'], true);
    }

    /** @return array{0: string, 1: string} [registrationId, competitorId] */
    private function createRegistrationWithCompetitor(string $firstName): array
    {
        $competitor = $this->competitors->create([
            'firstName' => $firstName,
            'lastName' => 'Testowy',
        ], skipDuplicateCheck: true);

        $registration = $this->registrations->create([
            'eventId' => $this->eventId,
            'eventName' => 'PHPUnit FormSnapshot Event',
            'competitorId' => $competitor['id'],
            'userId' => 'phpunit-user-' . bin2hex(random_bytes(4)),
            'userName' => $firstName . ' Testowy',
            'userEmail' => 'phpunit-formsnap-' . bin2hex(random_bytes(4)) . '@example.test',
            'distanceId' => $this->distanceId,
            'distanceName' => '10 km',
            'category' => 'Open',
        ]);

        return [(string) $registration['id'], (string) $competitor['id']];
    }

    private function finishEvent(): void
    {
        $this->pdo->prepare("UPDATE events SET publication_status = 'finished' WHERE id = :id")->execute(['id' => $this->eventId]);
    }

    /** @return array<string, mixed> */
    private function findInStartList(string $registrationId): array
    {
        $startList = $this->registrations->publicStartList($this->eventId, true);
        $entry = current(array_filter($startList, static fn (array $r): bool => $r['id'] === $registrationId));
        self::assertNotFalse($entry, 'Registration must appear on the public start list.');
        return $entry;
    }

    /** @return array<string, mixed> */
    private function findFieldByKey(string $key): array
    {
        foreach ($this->fields->listForEvent($this->eventId) as $field) {
            if ($field['fieldKey'] === $key) {
                return $field;
            }
        }
        self::fail('Field not found: ' . $key);
    }

    private function createEvent(): string
    {
        $slug = $this->slugPrefix . '-event';
        $event = $this->events->create([
            'name' => 'PHPUnit FormSnapshot Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+6 months')),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'published',
            'registrationDeadline' => date('Y-m-d', strtotime('+5 months')),
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $slug . '-10k',
                'name' => '10 km',
                'length' => 10,
                'unit' => 'km',
                'startType' => 'individual',
            ]],
        ]);
        $this->distanceId = (string) $event['distances'][0]['id'];
        return (string) $event['id'];
    }
}

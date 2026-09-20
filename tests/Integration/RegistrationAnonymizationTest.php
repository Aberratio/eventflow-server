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
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;

/**
 * TD.6 (E6.6 follow-up, "wire anonymization masking into public start-list
 * and results"): E6.6 wired the real `competitors.is_anonymized` toggle into
 * the Wyzwanie/Series public ranking only — the public event start list
 * (`GET /events/{eventId}/start-list`, unauthenticated) kept showing a
 * competitor's real name completely unmasked even after they opted in. This
 * covers RegistrationRepository::publicStartList()'s new masking: the flat
 * `userName` snapshot AND the `first_name`/`last_name` formFields entries,
 * for both the live (pre-finish) and frozen (post-finish snapshot) paths, and
 * confirms staff-facing reads of the exact same data
 * (RegistrationRepository::byEvent(), the organizer-only sibling of
 * publicStartList()) are left untouched.
 */
final class RegistrationAnonymizationTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private CompetitorRepository $competitors;
    private FormFieldRepository $fields;
    private string $slugPrefix;
    private string $eventId;
    private string $distanceId;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->slugPrefix = 'phpunit-anon-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }

        Migrator::applyAll($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo, null, null, null);
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->eventId = $this->createEvent();
        $this->fields->ensureSystemCatalog($this->eventId);
        $this->makeFirstAndLastNamePublic();
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
        $this->pdo->prepare('DELETE FROM competitors WHERE first_name LIKE :name')->execute(['name' => 'PHPUnit Anon%']);
    }

    public function testAnonymizedCompetitorsRealNameIsMaskedOnThePublicStartList(): void
    {
        [$registrationId, $competitorId] = $this->createRegistrationWithCompetitor('PHPUnit Anon Hidden', 'Zawodnik');
        $this->competitors->setAnonymization($competitorId, true);

        $entry = $this->findInStartList($registrationId);

        self::assertSame(CompetitorNamePresenter::ANONYMIZED_LABEL, $entry['userName']);
        self::assertStringNotContainsString('Hidden', $entry['userName']);

        $valuesByKey = array_column($entry['formFields'], 'value', 'fieldKey');
        self::assertSame(CompetitorNamePresenter::ANONYMIZED_LABEL, $valuesByKey['first_name']);
        self::assertNull($valuesByKey['last_name']);
    }

    public function testNonAnonymizedCompetitorsNameAppearsNormallyOnThePublicStartList(): void
    {
        [$registrationId] = $this->createRegistrationWithCompetitor('PHPUnit Anon Visible', 'Zawodnik');

        $entry = $this->findInStartList($registrationId);

        self::assertSame('PHPUnit Anon Visible Zawodnik', $entry['userName']);
        $valuesByKey = array_column($entry['formFields'], 'value', 'fieldKey');
        self::assertSame('PHPUnit Anon Visible', $valuesByKey['first_name']);
        self::assertSame('Zawodnik', $valuesByKey['last_name']);
    }

    /**
     * The mask must reflect the CURRENT is_anonymized flag even for a
     * finished Event already serving its frozen formFields snapshot — a
     * competitor who opts in after finish still expects it to take effect,
     * not only from the moment the snapshot happened to be captured.
     */
    public function testAnonymizingAfterEventFinishedStillMasksTheFrozenSnapshot(): void
    {
        [$registrationId, $competitorId] = $this->createRegistrationWithCompetitor('PHPUnit Anon LateOptIn', 'Zawodnik');

        $this->finishEvent();
        $before = $this->findInStartList($registrationId); // captures the frozen snapshot
        self::assertSame('PHPUnit Anon LateOptIn Zawodnik', $before['userName']);

        $this->competitors->setAnonymization($competitorId, true);

        $after = $this->findInStartList($registrationId);
        self::assertSame(CompetitorNamePresenter::ANONYMIZED_LABEL, $after['userName']);
        $valuesByKey = array_column($after['formFields'], 'value', 'fieldKey');
        self::assertSame(CompetitorNamePresenter::ANONYMIZED_LABEL, $valuesByKey['first_name']);
    }

    /**
     * RegistrationRepository::byEvent() is the staff-facing sibling of
     * publicStartList() (organizer view, gated by AuthMiddleware in
     * routes.php's `/events/{eventId}/registrations`) — it must keep showing
     * real names regardless of the anonymization flag; organizers need real
     * names to manage the event.
     */
    public function testStaffFacingByEventIsNeverMasked(): void
    {
        [$registrationId, $competitorId] = $this->createRegistrationWithCompetitor('PHPUnit Anon StaffView', 'Zawodnik');
        $this->competitors->setAnonymization($competitorId, true);

        $staffRows = $this->registrations->byEvent($this->eventId);
        $entry = current(array_filter($staffRows, static fn (array $r): bool => $r['id'] === $registrationId));

        self::assertNotFalse($entry);
        self::assertSame('PHPUnit Anon StaffView Zawodnik', $entry['userName']);
    }

    private function makeFirstAndLastNamePublic(): void
    {
        foreach (['first_name', 'last_name'] as $key) {
            $field = $this->findFieldByKey($key);
            $this->fields->updatePublicListVisibility($field['id'], true);
        }
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

    /** @return array{0: string, 1: string} [registrationId, competitorId] */
    private function createRegistrationWithCompetitor(string $firstName, string $lastName): array
    {
        $competitor = $this->competitors->create([
            'firstName' => $firstName,
            'lastName' => $lastName,
        ], skipDuplicateCheck: true);

        $registration = $this->registrations->create([
            'eventId' => $this->eventId,
            'eventName' => 'PHPUnit Anonymization Event',
            'competitorId' => $competitor['id'],
            'userId' => 'phpunit-user-' . bin2hex(random_bytes(4)),
            'userName' => $firstName . ' ' . $lastName,
            'userEmail' => 'phpunit-anon-' . bin2hex(random_bytes(4)) . '@example.test',
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

    private function createEvent(): string
    {
        $slug = $this->slugPrefix . '-event';
        $event = $this->events->create([
            'name' => 'PHPUnit Anonymization Event',
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

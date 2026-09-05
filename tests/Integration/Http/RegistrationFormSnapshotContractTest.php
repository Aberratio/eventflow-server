<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * E1.14: `PATCH /registrations/{id}/form-snapshot` — the audited "korekta
 * wsteczna" endpoint. Drives the real route (not the repository directly)
 * so the $canManageEvent authorization gate and the $logActivity wiring are
 * both exercised the way ChallengeJoinCompletionContractTest exercises its
 * own route-only side effect: calling RegistrationRepository::
 * correctFormSnapshot() directly would prove the merge logic (already
 * covered by RegistrationFormSnapshotTest) but say nothing about whether the
 * route actually enforces authorization or actually writes to activity_logs.
 */
final class RegistrationFormSnapshotContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private CompetitorRepository $competitors;
    private FormFieldRepository $fields;
    private string $prefix;
    private array $organizerA;
    private array $organizerB;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo, null, null, null);
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->prefix = 'http-formsnap-' . bin2hex(random_bytes(4));

        $this->organizerA = $this->users->create([
            'name' => 'Snapshot Organizer A',
            'email' => $this->prefix . '-orgA@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            'eventLimit' => 5,
        ]);
        $this->organizerB = $this->users->create([
            'name' => 'Snapshot Organizer B',
            'email' => $this->prefix . '-orgB@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            'eventLimit' => 5,
        ]);
        $this->admin = $this->users->create([
            'name' => 'Snapshot Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slug');
        $stmt->execute(['slug' => $this->prefix . '-%']);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
            $this->pdo->prepare('DELETE FROM activity_logs WHERE target_type = :t AND target_id IN (SELECT id FROM registrations WHERE event_id = :id)')
                ->execute(['t' => 'registration', 'id' => $id]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM competitors WHERE first_name = :name')->execute(['name' => 'Snapshot Contract']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testOwningOrganizerCanCorrectAFinishedRegistrationsSnapshotAndActivityLogRecordsIt(): void
    {
        [$eventId, $registrationId] = $this->createFinishedEventWithRegistration('-owner-ok');

        $response = $this->request(
            'PATCH',
            '/api/v1/registrations/' . $registrationId . '/form-snapshot',
            ['first_name' => 'Snapshot Corrected By Organizer'],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $values = array_column($payload['data']['formFields'], 'value', 'fieldKey');
        self::assertSame('Snapshot Corrected By Organizer', $values['first_name']);

        $stmt = $this->pdo->prepare(
            "SELECT description FROM activity_logs WHERE action = 'registration_form_snapshot_corrected' AND target_id = :id"
        );
        $stmt->execute(['id' => $registrationId]);
        $description = $stmt->fetchColumn();
        self::assertNotFalse($description, 'Correcting the snapshot must write an activity_logs entry.');
        self::assertStringContainsString('first_name', (string) $description);
        self::assertStringContainsString('Snapshot Contract', (string) $description, 'Entry must carry the OLD value.');
        self::assertStringContainsString('Snapshot Corrected By Organizer', (string) $description, 'Entry must carry the NEW value.');
    }

    public function testAdminCanAlsoCorrectAFinishedRegistrationsSnapshot(): void
    {
        [$eventId, $registrationId] = $this->createFinishedEventWithRegistration('-admin-ok');

        $response = $this->request(
            'PATCH',
            '/api/v1/registrations/' . $registrationId . '/form-snapshot',
            ['first_name' => 'Snapshot Corrected By Admin'],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testOtherOrganizerCannotCorrectSomeoneElsesEventRegistration(): void
    {
        [$eventId, $registrationId] = $this->createFinishedEventWithRegistration('-forbidden');

        $response = $this->request(
            'PATCH',
            '/api/v1/registrations/' . $registrationId . '/form-snapshot',
            ['first_name' => 'Should Not Apply'],
            $this->bearer($this->organizerB['id'], 'organizer', $this->organizerB['email'])
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->json($response)['error']['code']);
    }

    public function testCorrectionIsRejectedWhenTheEventIsNotFinishedYet(): void
    {
        [$eventId, $registrationId] = $this->createFinishedEventWithRegistration('-not-finished', finish: false);

        $response = $this->request(
            'PATCH',
            '/api/v1/registrations/' . $registrationId . '/form-snapshot',
            ['first_name' => 'Should Not Apply'],
            $this->bearer($this->organizerA['id'], 'organizer', $this->organizerA['email'])
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('registration_form_snapshot_not_finished', $this->json($response)['error']['code']);
    }

    /** @return array{0: string, 1: string} [eventId, registrationId] */
    private function createFinishedEventWithRegistration(string $slugSuffix, bool $finish = true): array
    {
        $slug = $this->prefix . $slugSuffix;
        $event = $this->events->create([
            'name' => 'HTTP FormSnapshot Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+6 months')),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->organizerA['id'],
            'organizerName' => 'Snapshot Organizer A',
            'status' => 'published',
            'registrationDeadline' => date('Y-m-d', strtotime('+5 months')),
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => 'd-' . bin2hex(random_bytes(6)),
                'name' => '10 km',
                'length' => 10,
                'unit' => 'km',
                'startType' => 'individual',
            ]],
        ]);
        $eventId = (string) $event['id'];
        $distanceId = (string) $event['distances'][0]['id'];

        $this->fields->ensureSystemCatalog($eventId);
        foreach ($this->fields->listForEvent($eventId) as $field) {
            if ($field['fieldKey'] === 'first_name') {
                $this->fields->updatePublicListVisibility($field['id'], true);
                break;
            }
        }

        $competitor = $this->competitors->create(['firstName' => 'Snapshot Contract', 'lastName' => 'Testowy'], skipDuplicateCheck: true);
        $registration = $this->registrations->create([
            'eventId' => $eventId,
            'eventName' => 'HTTP FormSnapshot Event',
            'competitorId' => $competitor['id'],
            'userId' => 'http-formsnap-user-' . bin2hex(random_bytes(4)),
            'userName' => 'Snapshot Contract Testowy',
            'userEmail' => $this->prefix . '-reg-' . bin2hex(random_bytes(4)) . '@example.test',
            'distanceId' => $distanceId,
            'distanceName' => '10 km',
            'category' => 'Open',
        ]);
        $registrationId = (string) $registration['id'];

        if ($finish) {
            $this->pdo->prepare("UPDATE events SET publication_status = 'finished' WHERE id = :id")->execute(['id' => $eventId]);
            // First read after finish captures the snapshot, same as production's
            // start-list read path (routes.php reads $events->find() then
            // $registrations->publicStartList() for the same Event).
            $this->registrations->publicStartList($eventId, true);
        }

        return [$eventId, $registrationId];
    }
}

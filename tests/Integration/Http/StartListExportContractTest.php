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
 * Start-list export (CSV in Windows-1250 + XLSX):
 * `GET /events/{eventId}/distances/{distanceId}/start-list-export`.
 *
 * Drives the real route (not RegistrationRepository::startListExportRows()
 * directly) — a known trap: a repository-
 * level test proves the row-building logic, but only a real HTTP request
 * through routes.php can prove the route itself enforces $canManageEvent
 * and actually calls $logActivity, the way
 * ChallengeJoinCompletionContractTest had to be added to catch that exact
 * gap for a different route.
 */
final class StartListExportContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private CompetitorRepository $competitors;
    private FormFieldRepository $fields;
    private string $prefix;
    private array $organizer;
    private array $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo, null, null, null);
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->prefix = 'hsle-' . bin2hex(random_bytes(4));

        $this->organizer = $this->users->create([
            'name' => 'Export Organizer',
            'email' => $this->prefix . '-organizer@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            'eventLimit' => 5,
        ]);
        $this->stranger = $this->users->create([
            'name' => 'Export Stranger',
            'email' => $this->prefix . '-stranger@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            'eventLimit' => 5,
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
            $this->pdo->prepare('DELETE FROM activity_logs WHERE target_type = :t AND target_id = :id')
                ->execute(['t' => 'event', 'id' => $id]);
            $this->pdo->prepare('DELETE FROM form_fields WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM competitors WHERE first_name LIKE :name')->execute(['name' => 'Export Contract%']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testOwningOrganizerGetsCsvAndActivityLogRecordsTheRealHttpExport(): void
    {
        [$eventId, $distanceId] = $this->createEventWithRegistration();

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $eventId . '/distances/' . $distanceId . '/start-list-export?format=csv',
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('windows-1250', strtolower($response->getHeaderLine('Content-Type')));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
        $body = (string) $response->getBody();
        self::assertNotSame('', $body);
        $decoded = iconv('Windows-1250', 'UTF-8', $body);
        self::assertStringContainsString('Numer startowy', (string) $decoded);
        self::assertStringContainsString('Status płatności', (string) $decoded);

        // The important trap: assert the activity log
        // row through the REAL route, not by calling the repository/AdminRepository
        // insert directly — this only passes if routes.php actually wires
        // $logActivity into this endpoint.
        $stmt = $this->pdo->prepare(
            "SELECT description, target_id, user_id FROM activity_logs WHERE action = 'startlist_exported' AND target_id = :id ORDER BY timestamp DESC LIMIT 1"
        );
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch();
        self::assertNotFalse($row, 'A successful export through the real route must write an activity_logs entry.');
        self::assertSame($this->organizer['id'], $row['user_id']);
        self::assertStringContainsString('CSV', (string) $row['description']);
    }

    public function testXmlFormatIsServedAsUtf8WithTheSameColumnsAsCsvAndIsLogged(): void
    {
        [$eventId, $distanceId] = $this->createEventWithRegistration();
        $auth = $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']);
        $base = '/api/v1/events/' . $eventId . '/distances/' . $distanceId . '/start-list-export';

        $response = $this->request('GET', $base . '?format=xml', [], $auth);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/xml', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('utf-8', strtolower($response->getHeaderLine('Content-Type')));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('.xml', $response->getHeaderLine('Content-Disposition'));
        $doc = new \SimpleXMLElement((string) $response->getBody());
        self::assertGreaterThanOrEqual(1, count($doc->entry));
        $xmlLabels = [];
        foreach ($doc->entry[0]->field as $field) {
            $xmlLabels[] = (string) $field['label'];
        }
        self::assertContains('Numer startowy', $xmlLabels);
        self::assertContains('Status płatności', $xmlLabels);

        $csv = $this->request('GET', $base . '?format=csv', [], $auth);
        $csvHeader = str_getcsv((string) strtok((string) iconv('Windows-1250', 'UTF-8', (string) $csv->getBody()), "\n"), ';', '"', '\\');
        self::assertSame($csvHeader, $xmlLabels, 'XML must carry exactly the CSV columns, in the same order.');

        $stmt = $this->pdo->prepare(
            "SELECT description, user_id FROM activity_logs WHERE action = 'startlist_exported' AND target_id = :id AND description LIKE '%XML%' ORDER BY timestamp DESC LIMIT 1"
        );
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch();
        self::assertNotFalse($row, 'An XML export must write an activity_logs entry like CSV/XLSX.');
        self::assertSame($this->organizer['id'], $row['user_id']);
    }

    public function testStrangerOrganizerCannotExportXml(): void
    {
        [$eventId, $distanceId] = $this->createEventWithRegistration();

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $eventId . '/distances/' . $distanceId . '/start-list-export?format=xml',
            [],
            $this->bearer($this->stranger['id'], 'organizer', $this->stranger['email'])
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testXlsxFormatIsAlsoServedWithTheCorrectContentType(): void
    {
        [$eventId, $distanceId] = $this->createEventWithRegistration();

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $eventId . '/distances/' . $distanceId . '/start-list-export?format=xlsx',
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );

        if ($response->getStatusCode() === 500 && str_contains((string) $response->getBody(), 'xlsx_not_supported')) {
            self::markTestSkipped('ZipArchive is required to write XLSX files.');
        }

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->getHeaderLine('Content-Type')
        );
        self::assertStringContainsString('.xlsx', $response->getHeaderLine('Content-Disposition'));
        self::assertNotSame('', (string) $response->getBody());
    }

    public function testStrangerOrganizerIsForbidden(): void
    {
        [$eventId, $distanceId] = $this->createEventWithRegistration();

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $eventId . '/distances/' . $distanceId . '/start-list-export?format=csv',
            [],
            $this->bearer($this->stranger['id'], 'organizer', $this->stranger['email'])
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testUnsupportedFormatIsRejected(): void
    {
        [$eventId, $distanceId] = $this->createEventWithRegistration();

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $eventId . '/distances/' . $distanceId . '/start-list-export?format=pdf',
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * File import stays as a fallback ("Import z pliku zostaje jako droga awaryjna"):
     * regression coverage proving the file-export fallback and the
     * BZ pull-integration model coexist. This is the "integration
     * ON" direction — an organizer who has flipped `bz_integration_enabled`
     * on and set a `bz_organization_token` must still be able to use the
     * CSV/XLSX export exactly as before, e.g. as a manual backup during the
     * transition period or alongside the API sync. The route only ever
     * calls $canManageEvent — nothing in it reads bz_integration_enabled or
     * bz_organization_token at all — so this proves that by exercising the
     * real HTTP route on a BZ-enabled event and comparing its output shape
     * (column headers, row count, competitor name) against the BZ-disabled
     * case from the other tests above, not just a 200 status.
     */
    public function testExportStillWorksWhenBzIntegrationIsEnabled(): void
    {
        [$eventId, $distanceId] = $this->createEventWithRegistration([
            'bzIntegrationEnabled' => true,
            'bzOrganizationToken' => 'ORG-TOKEN-E3-8',
        ]);

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $eventId . '/distances/' . $distanceId . '/start-list-export?format=csv',
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('windows-1250', strtolower($response->getHeaderLine('Content-Type')));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));

        $body = (string) $response->getBody();
        self::assertNotSame('', $body);
        $decoded = (string) iconv('Windows-1250', 'UTF-8', $body);
        // Same column set as the BZ-disabled tests above (no form_fields
        // catalog seeded here either, so the export is bibNumber +
        // paymentStatus only) — the toggle must change neither the export's
        // shape nor its data, only whether the separate bz/participants pull
        // endpoint also happens to be reachable for this Event.
        self::assertStringContainsString('Numer startowy', $decoded);
        self::assertStringContainsString('Status płatności', $decoded);
        $lines = array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $decoded))));
        self::assertCount(2, $lines, 'Header row + exactly one data row for the one seeded registration.');

        $stmt = $this->pdo->prepare(
            "SELECT id FROM activity_logs WHERE action = 'startlist_exported' AND target_id = :id"
        );
        $stmt->execute(['id' => $eventId]);
        self::assertNotFalse($stmt->fetch(), 'The export on a BZ-enabled event must still write its usual activity-log entry.');
    }

    /** @return array{0: string, 1: string} [eventId, distanceId] */
    private function createEventWithRegistration(array $eventOverrides = []): array
    {
        $slug = $this->prefix . '-event-' . bin2hex(random_bytes(3));
        $event = $this->events->createForOrganizer(
            array_replace($this->eventPayload($slug), $eventOverrides),
            $this->organizer,
            $this->organizer['id']
        );
        $eventId = (string) $event['id'];
        $distanceId = (string) $event['distances'][0]['id'];

        $competitor = $this->competitors->create(['firstName' => 'Export Contract', 'lastName' => 'Testowy'], skipDuplicateCheck: true);
        $this->registrations->create([
            'eventId' => $eventId,
            'eventName' => 'Export Contract Event',
            'competitorId' => $competitor['id'],
            'userId' => $this->prefix . '-user-' . bin2hex(random_bytes(4)),
            'userName' => 'Export Contract Testowy',
            'userEmail' => $this->prefix . '-reg-' . bin2hex(random_bytes(4)) . '@example.test',
            'distanceId' => $distanceId,
            'distanceName' => '10 km',
            'category' => 'Open',
        ]);

        return [$eventId, $distanceId];
    }

    private function eventPayload(string $slug): array
    {
        return [
            'name' => 'Export Contract Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => $this->organizer['name'],
            'status' => 'draft',
            'registrationDeadline' => '2026-09-01',
            'featured' => false,
            'isPrivate' => false,
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
            ],
        ];
    }
}

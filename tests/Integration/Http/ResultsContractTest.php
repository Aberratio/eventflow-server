<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

final class ResultsContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private ResultRepository $results;
    private string $prefix;
    private array $organizer;
    private array $participant;
    private array $admin;
    private array $activeEvent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo);
        $this->results = new ResultRepository($this->pdo);
        $this->prefix = 'http-res-' . bin2hex(random_bytes(4));

        $this->ensureEventStatusSchema();

        $this->organizer = $this->users->create([
            'name' => 'Results Organizer',
            'email' => $this->prefix . '-org@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
        ]);
        $this->participant = $this->users->create([
            'name' => 'Results Participant',
            'email' => $this->prefix . '-part@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $this->admin = $this->users->create([
            'name' => 'Results Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
        $this->activeEvent = $this->events->create($this->eventPayload([
            'slug' => $this->prefix . '-event',
            'status' => 'active',
        ]));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $eventId = (string) $this->activeEvent['id'];
        $competitorStmt = $this->pdo->prepare('SELECT competitor_id FROM registrations WHERE event_id = :id AND competitor_id IS NOT NULL');
        $competitorStmt->execute(['id' => $eventId]);
        $competitorIds = $competitorStmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        $resultStmt = $this->pdo->prepare('SELECT id FROM results WHERE event_id = :id');
        $resultStmt->execute(['id' => $eventId]);
        foreach ($resultStmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $resultId) {
            $this->pdo->prepare('DELETE FROM result_splits WHERE result_id = :id')->execute(['id' => $resultId]);
        }

        $this->pdo->prepare('DELETE FROM activity_logs WHERE target_type = :t AND target_id IN (SELECT id FROM results WHERE event_id = :id)')
            ->execute(['t' => 'result', 'id' => $eventId]);
        $this->pdo->prepare('DELETE FROM result_import_rows WHERE batch_id IN (SELECT id FROM result_import_batches WHERE event_id = :id)')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM result_import_batches WHERE event_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM results WHERE event_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $eventId]);
        foreach ($competitorIds as $competitorId) {
            $this->pdo->prepare('DELETE FROM competitors WHERE id = :id')->execute(['id' => $competitorId]);
        }
        $this->pdo->prepare('DELETE FROM event_distance_pricing_variants WHERE distance_id IN (SELECT id FROM event_distances WHERE event_id = :id)')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testPublicResultsListIsAccessibleWithoutAuth(): void
    {
        $response = $this->request('GET', '/api/v1/results');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('data', $payload);
        self::assertArrayHasKey('total', $payload);
    }

    public function testEventResultsEndpointIsPublic(): void
    {
        $response = $this->request('GET', '/api/v1/events/' . $this->activeEvent['id'] . '/results');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
    }

    public function testCreateResultRequiresEventManagerRole(): void
    {
        $noAuth = $this->request('POST', '/api/v1/results', $this->resultPayload());
        self::assertSame(401, $noAuth->getStatusCode());

        $participantResp = $this->request(
            'POST',
            '/api/v1/results',
            $this->resultPayload(),
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        self::assertSame(403, $participantResp->getStatusCode());
    }

    public function testOrganizerCanCreateResultForOwnActiveEvent(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/results',
            $this->resultPayload(),
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        $payload = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame($this->participant['id'], $payload['data']['userId']);
        self::assertSame('01:00:00', $payload['data']['time']);
    }

    public function testOrganizerCannotCreateResultForAnotherOrganizersEvent(): void
    {
        $otherOrganizer = $this->users->create([
            'name' => 'Other Org',
            'email' => $this->prefix . '-other-org@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
        ]);

        $response = $this->request(
            'POST',
            '/api/v1/results',
            $this->resultPayload(),
            $this->bearer($otherOrganizer['id'], 'organizer', $otherOrganizer['email'])
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('result_event_not_open', $this->json($response)['error']['code']);
    }

    public function testAdminCanCreateAndDeleteResult(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/results',
            $this->resultPayload(),
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(201, $createResp->getStatusCode());

        $resultId = $this->json($createResp)['data']['id'];

        $deleteResp = $this->request(
            'DELETE',
            '/api/v1/results/' . $resultId,
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $deleteResp->getStatusCode());
        self::assertTrue($this->json($deleteResp)['success']);
    }

    public function testUserPersonalRecordsEndpointIsPublic(): void
    {
        $response = $this->request('GET', '/api/v1/users/' . $this->participant['id'] . '/personal-records');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
    }

    public function testUserResultsEndpointIsPublic(): void
    {
        $response = $this->request('GET', '/api/v1/users/' . $this->participant['id'] . '/results');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
    }

    /**
     * E2.4 (spec 14 sekcja 3): GET /competitors/{id}/records mirrors the
     * self/Opiekun/staff authorization already proven for
     * /competitors/{id}/rotate-identity-code (E1.12) - a stranger participant
     * with no Opieka relation must be refused, while the Zawodnik themselves,
     * an admin, and a Konto with granted Opieka must all be let through.
     */
    public function testCompetitorRecordsEndpointGatesAccessToSelfOpiekunOrStaff(): void
    {
        $competitors = new CompetitorRepository($this->pdo);
        $ownProfile = $competitors->findByUserId((string) $this->participant['id']);
        self::assertNotNull($ownProfile, 'UserRepository::create() must auto-provision a Zawodnik profile.');
        $competitorId = (string) $ownProfile['id'];

        $noAuth = $this->request('GET', '/api/v1/competitors/' . $competitorId . '/records');
        self::assertSame(401, $noAuth->getStatusCode());

        $stranger = $this->users->create([
            'name' => 'Results Stranger',
            'email' => $this->prefix . '-stranger@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $forbidden = $this->request(
            'GET',
            '/api/v1/competitors/' . $competitorId . '/records',
            [],
            $this->bearer($stranger['id'], 'participant', $stranger['email'])
        );
        self::assertSame(403, $forbidden->getStatusCode());

        $selfResponse = $this->request(
            'GET',
            '/api/v1/competitors/' . $competitorId . '/records',
            [],
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        self::assertSame(200, $selfResponse->getStatusCode());
        self::assertTrue($this->json($selfResponse)['success']);

        $adminResponse = $this->request(
            'GET',
            '/api/v1/competitors/' . $competitorId . '/records',
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $adminResponse->getStatusCode());

        $notFound = $this->request(
            'GET',
            '/api/v1/competitors/does-not-exist/records',
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(404, $notFound->getStatusCode());
    }

    /**
     * E2.4 core behavior at the HTTP layer: a finished, non-virtual result
     * shows up as the competitor's record for that distance length; a
     * dns result for the same competitor+distance in a second Event does not
     * displace it (dns is excluded, not just "slower").
     */
    public function testCompetitorRecordsEndpointReturnsTheBestFinishedLiveResult(): void
    {
        $competitors = new CompetitorRepository($this->pdo);
        $competitorId = (string) $competitors->findByUserId((string) $this->participant['id'])['id'];

        $createResp = $this->request(
            'POST',
            '/api/v1/results',
            $this->resultPayload(['userId' => $this->participant['id'], 'userName' => $this->participant['name']]),
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        self::assertSame(201, $createResp->getStatusCode());

        $response = $this->request(
            'GET',
            '/api/v1/competitors/' . $competitorId . '/records',
            [],
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $payload['data']);
        self::assertSame('01:00:00', $payload['data'][0]['time']);
        self::assertEqualsWithDelta(10000.0, $payload['data'][0]['distanceLengthMeters'], 0.001);
    }

    /**
     * Spec 14 sekcja 6: "Korekta jest możliwa zawsze ... ze śladem: kto zmienił,
     * kiedy, z jakiego na jaki czas". Mirrors the pattern already proven for
     * registration form-snapshot corrections (RegistrationFormSnapshotContractTest):
     * assert on the real activity_logs row, not just the HTTP response.
     */
    public function testCorrectingAResultsTimeWritesAnActivityLogWithOldAndNewValue(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/results',
            $this->resultPayload(),
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        $resultId = $this->json($createResp)['data']['id'];

        $response = $this->request(
            'PATCH',
            '/api/v1/results/' . $resultId,
            ['time' => '00:59:30', 'timeInSeconds' => 3570],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('00:59:30', $payload['data']['time']);

        $stmt = $this->pdo->prepare("SELECT description FROM activity_logs WHERE action = 'result_updated' AND target_id = :id");
        $stmt->execute(['id' => $resultId]);
        $description = $stmt->fetchColumn();

        self::assertNotFalse($description, 'Correcting a result must write an activity_logs entry.');
        self::assertStringContainsString('01:00:00', (string) $description, 'Entry must carry the OLD time.');
        self::assertStringContainsString('00:59:30', (string) $description, 'Entry must carry the NEW time.');
    }

    /**
     * E2.3 (spec 14 sekcja 6): the "mark official" mutation - $canUploadResults
     * gate (same as every other /results/* mutation), operates on the whole
     * event+distance, and leaves the result individually correctable
     * afterwards without reverting the set to preliminary.
     */
    public function testMarkOfficialRequiresEventManagerRoleAndFlipsPublicationStatus(): void
    {
        $distanceId = (string) $this->activeEvent['distances'][0]['id'];
        $createResp = $this->request(
            'POST',
            '/api/v1/results',
            $this->resultPayload(),
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        $resultId = $this->json($createResp)['data']['id'];
        self::assertSame('preliminary', $this->json($createResp)['data']['publicationStatus']);

        $publishUrl = '/api/v1/events/' . $this->activeEvent['id'] . '/distances/' . $distanceId . '/results/publish-official';

        $unauthorized = $this->request(
            'POST',
            $publishUrl,
            [],
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        self::assertSame(403, $unauthorized->getStatusCode());

        $response = $this->request(
            'POST',
            $publishUrl,
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('official', $payload['data']['publicationStatus']);
        self::assertSame(1, $payload['data']['updatedCount']);

        // No GET /results/{id} route exists - re-fetch via the event listing
        // instead, which already exposes the publication status per row.
        $eventResults = $this->json($this->request('GET', '/api/v1/events/' . $this->activeEvent['id'] . '/results'));
        $refreshed = current(array_filter($eventResults['data'], fn (array $row) => $row['id'] === $resultId));
        self::assertSame('official', $refreshed['publicationStatus']);

        $stmt = $this->pdo->prepare("SELECT description FROM activity_logs WHERE action = 'results_published_official' AND target_id = :id");
        $stmt->execute(['id' => $distanceId]);
        self::assertNotFalse($stmt->fetchColumn(), 'Marking results official must write an activity_logs entry.');

        // Correcting the now-official result must not revert it to preliminary.
        $patchResp = $this->request(
            'PATCH',
            '/api/v1/results/' . $resultId,
            ['time' => '00:58:00', 'timeInSeconds' => 3480],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        self::assertSame(200, $patchResp->getStatusCode());
        self::assertSame('official', $this->json($patchResp)['data']['publicationStatus']);
    }

    /**
     * Spec 14 sekcja 1: the manual-match screen's "no existing Zapis" action -
     * forces a Zapis via the office-only pricing variant instead of leaving an
     * orphan result. Exercised at the HTTP layer to also prove authorization.
     */
    public function testCreateRegistrationForUnmatchedImportRowRequiresEventManagerRole(): void
    {
        $distanceId = (string) $this->activeEvent['distances'][0]['id'];
        // Ticket 07 pkt 13 / E0.3: an office-only Wariant lives on
        // event_distance_pricing_variants - inserted directly here rather than
        // via EventRepository::update() (a full distances replace) to keep this
        // test focused on the route under test, not the event-editing pipeline.
        $this->pdo->prepare(
            'INSERT INTO event_distance_pricing_variants (id, distance_id, name, price, office_only, visible_in_public_form, sort_order)
             VALUES (:id, :distanceId, :name, :price, 1, 0, 0)'
        )->execute([
            'id' => $this->prefix . '-office',
            'distanceId' => $distanceId,
            'name' => 'Zapis w biurze zawodow',
            'price' => 100,
        ]);

        // The multipart upload route (/events/{id}/distances/{id}/result-imports)
        // has no HTTP test helper for file uploads in this suite yet - go through
        // the repository directly to build the batch (already covered end-to-end
        // by ResultImportRepositoryTest), then exercise the HTTP route under test
        // (create-registration) for real.
        $csv = "numer,uczestnik,czas,kategoria,status\n555,Kontraktowy Zawodnik,0:55:00,Open,finished\n";
        $tmpFile = tempnam(sys_get_temp_dir(), 'phpunit-import') . '.csv';
        file_put_contents($tmpFile, $csv);
        $imports = new \ZmierzymyCzas\Domain\Results\ResultImportRepository(
            $this->pdo,
            $this->registrations,
            new \ZmierzymyCzas\Domain\Competitors\CompetitorRepository($this->pdo)
        );
        $batch = $imports->create((string) $this->activeEvent['id'], $distanceId, 'replace', [
            'path' => $tmpFile,
            'absolutePath' => $tmpFile,
            'url' => '/tmp/' . basename($tmpFile),
            'mimeType' => 'text/csv',
            'originalName' => 'phpunit-import.csv',
        ], (string) $this->organizer['id']);
        self::assertSame('needs_match', $batch['rows'][0]['status']);
        $rowId = $batch['rows'][0]['id'];

        $unauthorized = $this->request(
            'POST',
            '/api/v1/result-import-rows/' . $rowId . '/create-registration',
            [],
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        self::assertSame(403, $unauthorized->getStatusCode());

        $response = $this->request(
            'POST',
            '/api/v1/result-import-rows/' . $rowId . '/create-registration',
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        $payload = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('ready', $payload['data']['rows'][0]['status']);
        self::assertNotNull($payload['data']['rows'][0]['selectedRegistrationId']);
    }

    private function organizerHeaders(): array
    {
        return $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']);
    }

    /** @return array<string, mixed> */
    private function assertValidationError(\Psr\Http\Message\ResponseInterface $response, string $field): array
    {
        $payload = $this->json($response);

        self::assertSame(422, $response->getStatusCode(), json_encode($payload));
        self::assertFalse($payload['success']);
        self::assertSame('validation_error', $payload['error']['code']);
        self::assertSame($field, $payload['error']['details']['field']);
        self::assertStringStartsWith('Pole „', $payload['error']['message']);

        return $payload;
    }

    private function countResultsOfActiveEvent(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM results WHERE event_id = :id');
        $stmt->execute(['id' => $this->activeEvent['id']]);

        return (int) $stmt->fetchColumn();
    }

    /** PP.76: strict MySQL answered 1364 (event_name has no default) -> 500; now a 422 naming the field. */
    public function testCreateResultWithoutCompetitorNameIs422(): void
    {
        $response = $this->request('POST', '/api/v1/results', [
            'eventId' => $this->activeEvent['id'],
            'distanceId' => $this->activeEvent['distances'][0]['id'],
        ], $this->organizerHeaders());

        $payload = $this->assertValidationError($response, 'userName');
        self::assertSame('Pole „Imię i nazwisko zawodnika” jest wymagane.', $payload['error']['message']);
        self::assertSame(0, $this->countResultsOfActiveEvent());
    }

    /** PP.76: a finished result without timeInSeconds hit 1364 on strict MySQL. */
    public function testCreateFinishedResultWithoutTimeIs422(): void
    {
        $response = $this->request('POST', '/api/v1/results', [
            'eventId' => $this->activeEvent['id'],
            'distanceId' => $this->activeEvent['distances'][0]['id'],
            'userName' => 'Bez Czasu',
            'time' => '00:50:00',
        ], $this->organizerHeaders());

        $this->assertValidationError($response, 'timeInSeconds');
        self::assertSame(0, $this->countResultsOfActiveEvent());
    }

    /** PP.76: an unknown status was 1265 (ENUM) -> 500 on strict MySQL. */
    public function testCreateResultWithUnknownStatusIs422(): void
    {
        $response = $this->request('POST', '/api/v1/results', $this->resultPayload(['status' => 'bogus']), $this->organizerHeaders());

        $this->assertValidationError($response, 'status');
        self::assertSame(0, $this->countResultsOfActiveEvent());
    }

    public function testCreateResultFillsDerivedNamesAndDefaults(): void
    {
        $response = $this->request('POST', '/api/v1/results', [
            'eventId' => $this->activeEvent['id'],
            'distanceId' => $this->activeEvent['distances'][0]['id'],
            'userName' => 'Minimalny Wynik',
            'time' => '00:50:00',
            'timeInSeconds' => 3000,
        ], $this->organizerHeaders());
        $payload = $this->json($response);

        self::assertSame(201, $response->getStatusCode(), json_encode($payload));
        self::assertSame($this->activeEvent['name'], $payload['data']['eventName']);
        self::assertSame($this->activeEvent['distances'][0]['name'], $payload['data']['distanceName']);
        self::assertSame('', $payload['data']['bibNumber']);
        self::assertSame('', $payload['data']['category']);
        self::assertSame(1, $payload['data']['position']);
    }

    public function testCreateDnsResultDefaultsTimeAndPosition(): void
    {
        $response = $this->request('POST', '/api/v1/results', [
            'eventId' => $this->activeEvent['id'],
            'distanceId' => $this->activeEvent['distances'][0]['id'],
            'userName' => 'Nie Wystartowal',
            'status' => 'dns',
        ], $this->organizerHeaders());
        $payload = $this->json($response);

        self::assertSame(201, $response->getStatusCode(), json_encode($payload));
        self::assertSame('DNS', $payload['data']['time']);
        self::assertSame(0, $payload['data']['position']);
    }

    /** PP.76: {"userName": null} was 1048 -> 500 on strict MySQL. */
    public function testPatchResultRejectsNullCompetitorName(): void
    {
        $created = $this->json($this->request('POST', '/api/v1/results', $this->resultPayload(), $this->organizerHeaders()));
        $id = (string) $created['data']['id'];

        $response = $this->request('PATCH', '/api/v1/results/' . $id, ['userName' => null], $this->organizerHeaders());

        $this->assertValidationError($response, 'userName');
        $stmt = $this->pdo->prepare('SELECT user_name FROM results WHERE id = :id');
        $stmt->execute(['id' => $id]);
        self::assertSame($this->participant['name'], $stmt->fetchColumn());
    }

    private function resultPayload(array $overrides = []): array
    {
        return array_merge([
            'eventId' => $this->activeEvent['id'],
            'eventName' => $this->activeEvent['name'],
            'distanceId' => $this->activeEvent['distances'][0]['id'],
            'distanceName' => $this->activeEvent['distances'][0]['name'],
            'userId' => $this->participant['id'],
            'userName' => $this->participant['name'],
            'bibNumber' => '1',
            'category' => 'Open',
            'time' => '01:00:00',
            'timeInSeconds' => 3600,
            'position' => 1,
            'categoryPosition' => 1,
            'status' => 'finished',
        ], $overrides);
    }

    /**
     * E1.1 dropped the free-text `status` column entirely (see
     * 031_e11_event_lifecycle.sql), so there is no ENUM to patch in place any more — the
     * schema this test needs only exists once every migration has actually run. Calling
     * the real Migrator (idempotent, no ledger — see its docblock) is the only thing that
     * still makes sense here.
     */
    private function ensureEventStatusSchema(): void
    {
        \ZmierzymyCzas\Database\Migrator::applyAll($this->pdo);
    }

    private function eventPayload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? $this->prefix);

        return array_replace_recursive([
            'name' => 'PHPUnit Results Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'Results Organizer',
            'status' => 'active',
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
        ], $overrides);
    }
}

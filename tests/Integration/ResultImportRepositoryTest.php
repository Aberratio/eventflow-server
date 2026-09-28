<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Results\ResultImportRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;

final class ResultImportRepositoryTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private CompetitorRepository $competitors;
    private ResultImportRepository $imports;
    private ResultRepository $results;
    private UserRepository $users;
    private string $prefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->prefix = 'phpimp-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->deletePhpUnitData();
            $this->events = new EventRepository($this->pdo);
            $this->registrations = new RegistrationRepository($this->pdo);
            $this->competitors = new CompetitorRepository($this->pdo);
            $this->imports = new ResultImportRepository($this->pdo, $this->registrations, $this->competitors);
            $this->results = new ResultRepository($this->pdo);
            $this->users = new UserRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for result import tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        $this->deletePhpUnitData();
    }

    public function testCsvImportMatchesDuplicateNameByCategoryAndCommitsResults(): void
    {
        $event = $this->events->create($this->eventPayload());
        $distanceId = (string) $event['distances'][0]['id'];

        $janM30 = $this->createParticipant('Jan Kowalski', 'M', 1993);
        $janM40 = $this->createParticipant('Jan Kowalski', 'M', 1983);

        $this->createRegistration($event, $janM30, 'M30', '101');
        $matchedRegistration = $this->createRegistration($event, $janM40, 'M40', '102');

        $csv = "miejsce,numer,uczestnik,czas,kategoria\n1,102,Jan Kowalski,00:41:10,M40\n";
        $file = $this->tempImportFile('csv', $csv);

        $batch = $this->imports->create((string) $event['id'], $distanceId, 'replace', $file, 'phpunit-admin');

        self::assertSame(1, $batch['summary']['ready']);
        self::assertSame(0, $batch['summary']['ambiguous']);
        self::assertSame($matchedRegistration['id'], $batch['rows'][0]['selectedRegistrationId']);

        $committed = $this->imports->commit((string) $batch['id']);
        $eventResults = $this->results->byEvent((string) $event['id'], $distanceId);

        self::assertSame('committed', $committed['status']);
        self::assertCount(1, $eventResults);
        self::assertSame($janM40['id'], $eventResults[0]['userId']);
        self::assertSame('0:41:10', $eventResults[0]['time']);
        self::assertSame(2470, (int) $eventResults[0]['timeInSeconds']);
        self::assertSame(1, (int) $eventResults[0]['position']);
        self::assertSame('M40', $eventResults[0]['category']);
    }

    public function testTextPdfImportBuildsEditableRowsBeforeCommit(): void
    {
        $event = $this->events->create($this->eventPayload());
        $distanceId = (string) $event['distances'][0]['id'];
        $anna = $this->createParticipant('Anna Nowak', 'K', 1990);
        $registration = $this->createRegistration($event, $anna, 'K30', '201');

        $pdf = <<<'PDF'
%PDF-1.4
1 0 obj
<< /Length 94 >>
stream
BT
(1 201 Anna Nowak 00:45:01 K30) Tj
ET
endstream
endobj
%%EOF
PDF;
        $file = $this->tempImportFile('pdf', $pdf);

        $batch = $this->imports->create((string) $event['id'], $distanceId, 'replace', $file, 'phpunit-admin');

        self::assertSame('PDF eksperymentalnie', $batch['sheetName']);
        self::assertSame(1, $batch['summary']['ready']);
        self::assertSame($registration['id'], $batch['rows'][0]['selectedRegistrationId']);
        self::assertSame('0:45:01', $batch['rows'][0]['mappedData']['time']);
        self::assertSame('K30', $batch['rows'][0]['mappedData']['category']);
    }

    /**
     * @group requires-local-samples
     *
     * Needs both the `zip` PHP extension and the real-world sample under
     * `przyklady/import-wynikow/` (outside this repository, never present in CI) —
     * excluded from the CI --fail-on-skipped gate for exactly that reason.
     */
    public function testXlsxSampleImportBuildsRows(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required to parse XLSX files.');
        }

        $event = $this->events->create($this->eventPayload());
        $distanceId = (string) $event['distances'][0]['id'];
        $jan = $this->createParticipant('Jan Kowalski', 'M', 1983);
        $this->createRegistration($event, $jan, 'M40', '101');

        $path = $this->realWorldSample('wyniki-10km.xlsx');

        $batch = $this->imports->create((string) $event['id'], $distanceId, 'replace', [
            'path' => $path,
            'absolutePath' => $path,
            'url' => '/przyklady/import-wynikow/wyniki-10km.xlsx',
            'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'originalName' => 'wyniki-10km.xlsx',
        ], 'phpunit-admin');

        self::assertSame('Wyniki 10 km', $batch['sheetName']);
        self::assertGreaterThanOrEqual(1, $batch['summary']['ready']);
        self::assertSame('Jan Kowalski', $batch['rows'][0]['mappedData']['fullName']);
    }

    /**
     * Spec 14 sekcja 1: real-world sample from przyklady/import-wynikow -
     * DNF row (empty czas, status DNF) must import as a visible-but-unranked
     * result, not be dropped or rejected.
     *
     * @group requires-local-samples
     *
     * Needs the real-world sample under `przyklady/import-wynikow/` (outside this
     * repository, never present in CI) — excluded from the CI --fail-on-skipped
     * gate for exactly that reason.
     */
    public function testRealSampleCsvImportsDnfRowWithStatusAndNoRankingPosition(): void
    {
        $event = $this->events->create($this->eventPayload());
        $distanceId = (string) $event['distances'][0]['id'];

        $this->createRegistration($event, $this->createParticipant('Jan Kowalski', 'M', 1986), 'M40', '101');
        $this->createRegistration($event, $this->createParticipant('Anna Nowak', 'K', 1994), 'K30', '102');
        $this->createRegistration($event, $this->createParticipant('Piotr Zielinski', 'M', 1994), 'M30', '103');
        $this->createRegistration($event, $this->createParticipant('Marta Wisniewska', 'K', 1984), 'K40', '104');

        $path = $this->realWorldSample('wyniki-10km.csv');

        $batch = $this->imports->create((string) $event['id'], $distanceId, 'replace', [
            'path' => $path,
            'absolutePath' => $path,
            'url' => '/przyklady/import-wynikow/wyniki-10km.csv',
            'mimeType' => 'text/csv',
            'originalName' => 'wyniki-10km.csv',
        ], 'phpunit-admin');

        self::assertSame(4, $batch['summary']['ready']);
        $this->imports->commit((string) $batch['id']);

        $results = $this->results->byEvent((string) $event['id'], $distanceId);
        $dnf = current(array_filter($results, static fn (array $r): bool => $r['bibNumber'] === '104'));
        self::assertNotFalse($dnf);
        self::assertSame('dnf', $dnf['status']);
        self::assertSame('DNF', $dnf['time']);
        self::assertSame(0, (int) $dnf['position']);
    }

    public function testDnsAndDsqRowsHaveNoTimeAndAreExcludedFromRanking(): void
    {
        $event = $this->events->create($this->eventPayload());
        $distanceId = (string) $event['distances'][0]['id'];
        $this->createRegistration($event, $this->createParticipant('Ewa Krol', 'K', 1990), 'K30', '201');
        $this->createRegistration($event, $this->createParticipant('Tomasz Nowicki', 'M', 1990), 'M30', '202');

        $csv = "numer,uczestnik,czas,kategoria,status\n201,Ewa Krol,,K30,DNS\n202,Tomasz Nowicki,00:30:00,M30,DSQ\n";
        $file = $this->tempImportFile('csv', $csv);
        $batch = $this->imports->create((string) $event['id'], $distanceId, 'replace', $file, 'phpunit-admin');

        self::assertSame(2, $batch['summary']['ready']);
        $this->imports->commit((string) $batch['id']);

        $results = $this->results->byEvent((string) $event['id'], $distanceId);
        $dns = current(array_filter($results, static fn (array $r): bool => $r['bibNumber'] === '201'));
        $dsq = current(array_filter($results, static fn (array $r): bool => $r['bibNumber'] === '202'));

        self::assertSame('dns', $dns['status']);
        self::assertSame('DNS', $dns['time']);
        self::assertSame('dsq', $dsq['status']);
        // DSQ never carries a time even though the file supplied one (spec 14 sekcja 1).
        self::assertSame('DSQ', $dsq['time']);
        self::assertSame(0, (int) $dns['position']);
        self::assertSame(0, (int) $dsq['position']);
    }

    /**
     * Spec 14 sekcja 2: gross/net as two separate optional-net fields, hundredth
     * precision, plus optional splits (miedzyczasy) carried alongside.
     */
    public function testGrossNetTimeAndSplitsAreImportedWithHundredthPrecision(): void
    {
        $event = $this->events->create($this->eventPayload());
        $distanceId = (string) $event['distances'][0]['id'];
        $this->createRegistration($event, $this->createParticipant('Kamil Wojcik', 'M', 1990), 'M30', '301');

        $csv = "numer,uczestnik,czas brutto,czas netto,kategoria,status,miedzyczas 5km\n"
            . "301,Kamil Wojcik,0:41:10.55,0:40:58.20,M30,finished,0:20:15.10\n";
        $file = $this->tempImportFile('csv', $csv);
        $batch = $this->imports->create((string) $event['id'], $distanceId, 'replace', $file, 'phpunit-admin');

        self::assertSame(1, $batch['summary']['ready']);
        self::assertSame('0:41:10.55', $batch['rows'][0]['mappedData']['time']);
        self::assertSame('0:40:58.20', $batch['rows'][0]['mappedData']['netTime']);
        self::assertSame('miedzyczas 5km', $batch['rows'][0]['mappedData']['splits'][0]['label']);
        self::assertEqualsWithDelta(1215.10, $batch['rows'][0]['mappedData']['splits'][0]['timeSeconds'], 0.001);

        $committed = $this->imports->commit((string) $batch['id']);
        self::assertSame('committed', $committed['status']);

        $result = $this->results->find((string) $this->results->byEvent((string) $event['id'], $distanceId)[0]['id']);
        self::assertSame('0:41:10.55', $result['time']);
        self::assertEqualsWithDelta(2470.55, (float) $result['timeInSeconds'], 0.001);
        self::assertSame('0:40:58.20', $result['netTime']);
        self::assertEqualsWithDelta(2458.20, (float) $result['netTimeInSeconds'], 0.001);
        self::assertCount(1, $result['splits']);
        self::assertSame('miedzyczas 5km', $result['splits'][0]['label']);
        self::assertEqualsWithDelta(1215.10, $result['splits'][0]['timeSeconds'], 0.001);
    }

    /**
     * Spec 14 sekcja 1: a participant with no matching Zapis must not become an
     * orphan result - the organizer's manual-match action forces a Zapis using
     * the office-only ("dostępny wyłącznie przy zapisie w biurze zawodów")
     * pricing variant from ticket 07 pkt 13, then the row becomes ready.
     */
    public function testUnmatchedRowCanForceCreateOfficeRegistrationAndThenCommits(): void
    {
        $event = $this->events->create($this->eventPayload());
        $distanceId = (string) $event['distances'][0]['id'];
        // No existing registration at all for this distance - every row starts unmatched.

        $csv = "numer,uczestnik,czas,kategoria,status\n999,Nowy Zawodnik,0:50:00,Open,finished\n";
        $file = $this->tempImportFile('csv', $csv);
        $batch = $this->imports->create((string) $event['id'], $distanceId, 'replace', $file, 'phpunit-admin');

        self::assertSame('needs_match', $batch['rows'][0]['status']);
        self::assertSame(1, $batch['summary']['needsMatch']);

        $updatedBatch = $this->imports->createRegistrationForRow((string) $batch['rows'][0]['id'], 'phpunit-organizer');

        self::assertSame('ready', $updatedBatch['rows'][0]['status']);
        self::assertNotNull($updatedBatch['rows'][0]['selectedRegistrationId']);
        self::assertSame(1, $updatedBatch['summary']['ready']);

        $registration = $this->registrations->find((string) $updatedBatch['rows'][0]['selectedRegistrationId']);
        self::assertSame('confirmed', $registration['status']);
        self::assertSame('999', $registration['bibNumber']);
        self::assertSame('Nowy Zawodnik', $registration['userName']);

        $committed = $this->imports->commit((string) $updatedBatch['id']);
        self::assertSame('committed', $committed['status']);
        $results = $this->results->byEvent((string) $event['id'], $distanceId);
        self::assertCount(1, $results);
        self::assertSame($registration['id'], $results[0]['registrationId']);
    }

    public function testForceCreateRegistrationFailsFastWhenDistanceHasNoOfficeVariant(): void
    {
        $eventPayload = $this->eventPayload();
        unset($eventPayload['distances'][0]['pricingVariants']);
        $event = $this->events->create($eventPayload);
        $distanceId = (string) $event['distances'][0]['id'];

        $csv = "numer,uczestnik,czas,kategoria,status\n999,Nowy Zawodnik,0:50:00,Open,finished\n";
        $file = $this->tempImportFile('csv', $csv);
        $batch = $this->imports->create((string) $event['id'], $distanceId, 'replace', $file, 'phpunit-admin');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('result_import_office_variant_missing');
        $this->imports->createRegistrationForRow((string) $batch['rows'][0]['id'], 'phpunit-organizer');
    }

    /** @return array<string, mixed> */
    private function eventPayload(): array
    {
        return [
            'name' => 'PHPUnit Import Event',
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
                    'categories' => ['Open', 'M30', 'M40', 'K30'],
                    'ageCategories' => [],
                    'specialCategories' => [],
                    'limit' => 100,
                    'registeredCount' => 0,
                    'price' => 80,
                    'routeFiles' => [],
                    'pricingVariants' => [
                        [
                            'id' => $this->prefix . '-office-variant',
                            'name' => 'Zapis w biurze zawodow',
                            'price' => 100,
                            'officeOnly' => true,
                            'visibleInPublicForm' => false,
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function createParticipant(string $name, string $gender, int $birthYear): array
    {
        return $this->users->create([
            'name' => $name,
            'email' => $this->prefix . '.' . strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(2)) . '@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
            'gender' => $gender,
            'birthYear' => $birthYear,
        ]);
    }

    /** @param array<string, mixed> $event @param array<string, mixed> $user @return array<string, mixed> */
    private function createRegistration(array $event, array $user, string $category, string $bibNumber): array
    {
        $registration = $this->registrations->create([
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'userId' => $user['id'],
            'userName' => $user['name'],
            'userEmail' => $user['email'],
            'distanceId' => $event['distances'][0]['id'],
            'distanceName' => $event['distances'][0]['name'],
            'category' => $category,
            'merchandiseOrders' => [],
        ]);

        return $this->registrations->update((string) $registration['id'], [
            'status' => 'confirmed',
            'bibNumber' => $bibNumber,
        ]) ?? $registration;
    }

    /** @return array{path: string, absolutePath: string, url: string, mimeType: string, originalName: string} */
    private function tempImportFile(string $extension, string $content): array
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->prefix . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        file_put_contents($path, $content);

        return [
            'path' => $path,
            'absolutePath' => $path,
            'url' => '/tmp/' . basename($path),
            'mimeType' => $extension === 'pdf' ? 'application/pdf' : 'text/csv',
            'originalName' => 'phpunit-import.' . $extension,
        ];
    }

    private function deletePhpUnitData(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => $this->prefix . '%']);
        foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $eventId) {
            $competitorStmt = $this->pdo->prepare('SELECT competitor_id FROM registrations WHERE event_id = :id AND competitor_id IS NOT NULL');
            $competitorStmt->execute(['id' => $eventId]);
            $competitorIds = $competitorStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $resultStmt = $this->pdo->prepare('SELECT id FROM results WHERE event_id = :id');
            $resultStmt->execute(['id' => $eventId]);
            foreach (($resultStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $resultId) {
                $this->pdo->prepare('DELETE FROM result_splits WHERE result_id = :id')->execute(['id' => $resultId]);
            }

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
        }

        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '.%@example.test']);
    }

    /**
     * Absolute path to one of the real-world samples in `przyklady/import-wynikow`.
     *
     * That directory sits next to this repository rather than inside it — it is not
     * tracked here and never has been — so a checkout without it is a perfectly
     * ordinary one, not a broken test. Skipping says which file is missing and why;
     * asserting it exists just turned an absent sibling directory into a red suite,
     * which is what it did on every machine that does not have it.
     */
    private function realWorldSample(string $name): string
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'przyklady'
            . DIRECTORY_SEPARATOR . 'import-wynikow' . DIRECTORY_SEPARATOR . $name;

        if (!is_file($path)) {
            self::markTestSkipped("Real-world sample {$name} is not available: {$path} (przyklady/ is not part of this repository).");
        }

        return $path;
    }
}

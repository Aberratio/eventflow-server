<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
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
            $this->applyImportMigration();
            $this->deletePhpUnitData();
            $this->events = new EventRepository($this->pdo);
            $this->registrations = new RegistrationRepository($this->pdo);
            $this->imports = new ResultImportRepository($this->pdo);
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

    public function testXlsxSampleImportBuildsRows(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required to parse XLSX files.');
        }

        $event = $this->events->create($this->eventPayload());
        $distanceId = (string) $event['distances'][0]['id'];
        $jan = $this->createParticipant('Jan Kowalski', 'M', 1983);
        $this->createRegistration($event, $jan, 'M40', '101');

        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'przyklady' . DIRECTORY_SEPARATOR . 'import-wynikow' . DIRECTORY_SEPARATOR . 'wyniki-10km.xlsx';
        self::assertFileExists($path);

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

    private function applyImportMigration(): void
    {
        $migration = __DIR__ . '/../../database/migrations/016_result_imports.sql';
        $sql = (string) file_get_contents($migration);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->pdo->exec($statement);
        }
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
            $this->pdo->prepare('DELETE FROM result_import_rows WHERE batch_id IN (SELECT id FROM result_import_batches WHERE event_id = :id)')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM result_import_batches WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM results WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        }

        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '.%@example.test']);
    }
}

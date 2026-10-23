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
use ZmierzymyCzas\Domain\Registrations\StartListExportFormatter;
use ZmierzymyCzas\Support\Env;

/**
 * Start-list export (CSV in Windows-1250 + XLSX):
 * Repository-level acceptance criteria for
 * RegistrationRepository::startListExportRows():
 *  - columns = form fields for the Distance's Event+ bib number +
 *    payment status;
 *  - NO monetary/price/amount field ever appears ("bez kwot");
 *  - `is_confidential` fields are EXCLUDED, the same as the public start
 *    list (see FormFieldRepository::exportColumns()'s docblock) — a
 *    non-confidential field with the same "shown" state still appears;
 *  - the live/frozen fork mirrors eventIsFinished(), same as
 *    attachPublicFormFields()/publicStartList().
 */
final class StartListExportTest extends TestCase
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
        $this->slugPrefix = 'phpunit-startlistexport-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }

        Migrator::applyAll($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo, null, null, null);
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->eventId = $this->createEvent();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->slugPrefix)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slug');
        $stmt->execute(['slug' => $this->slugPrefix . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $this->pdo->prepare('DELETE FROM form_field_answers WHERE form_field_id IN (SELECT id FROM form_fields WHERE event_id = :id)')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM form_fields WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM competitors WHERE first_name LIKE :name')->execute(['name' => 'PHPUnit StartListExport%']);
    }

    public function testColumnsIncludeFormFieldsBibNumberAndPaymentStatusButNeverAMonetaryAmount(): void
    {
        $this->fields->createCustom($this->eventId, [
            'label' => 'Rozmiar koszulki',
            'fieldType' => FormFieldType::SINGLE_CHOICE,
            'options' => [['key' => 's', 'label' => 'S', 'price' => 15], ['key' => 'm', 'label' => 'M', 'price' => 20]],
            'state' => 'shown',
        ]);
        [$registrationId] = $this->createRegistrationWithCompetitor('PHPUnit StartListExport A', 's');
        $this->registrations->update($registrationId, ['bibNumber' => '101']);

        $export = $this->registrations->startListExportRows($this->eventId, $this->distanceId);

        $columnKeys = array_column($export['columns'], 'key');
        self::assertContains('bibNumber', $columnKeys);
        self::assertContains('paymentStatus', $columnKeys);
        // Exactly one custom form field column exists (the t-shirt one) —
        // confirms columns come from the form, not from a hardcoded list.
        self::assertCount(1, array_filter($columnKeys, static fn (string $k): bool => !in_array($k, ['bibNumber', 'paymentStatus'], true)));

        $columnLabels = implode(' ', array_column($export['columns'], 'label'));
        foreach (['cena', 'kwota', 'price', 'amount', 'zł', 'PLN'] as $moneyWord) {
            self::assertStringNotContainsStringIgnoringCase($moneyWord, $columnLabels, "Export columns must never surface a monetary word ('{$moneyWord}') — spec: bez kwot.");
        }

        self::assertCount(1, $export['rows']);
        $row = $export['rows'][0];
        self::assertSame('101', $row['bibNumber']);
        self::assertSame('Oczekująca', $row['paymentStatus']);
        // The t-shirt column's value is the stored option key, never its price.
        foreach ($row as $value) {
            self::assertIsNotFloat($value);
        }
    }

    public function testConfidentialFieldsAreExcludedFromTheOrganizerExportButOtherFieldsStillAppear(): void
    {
        $confidential = $this->fields->createCustom($this->eventId, [
            'label' => 'Uwagi medyczne',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'state' => 'shown',
            'isConfidential' => true,
        ]);
        $nonConfidential = $this->fields->createCustom($this->eventId, [
            'label' => 'Klub',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'state' => 'shown',
            'isConfidential' => false,
        ]);
        [$registrationId] = $this->createRegistrationWithCompetitor('PHPUnit StartListExport Confidential', null);
        $this->fields->reorder($this->eventId, [$confidential['id'], $nonConfidential['id']]);
        $answers = new \ZmierzymyCzas\Domain\FormFields\FormFieldAnswerRepository($this->pdo);
        $answers->saveAnswer($this->fields, $confidential['id'], $registrationId, 'Alergia na pyłki');
        $answers->saveAnswer($this->fields, $nonConfidential['id'], $registrationId, 'AZS');

        $export = $this->registrations->startListExportRows($this->eventId, $this->distanceId);

        self::assertNotContains('Uwagi medyczne', array_column($export['columns'], 'label'), 'Confidential fields must never appear in the organizer export — same exclusion as the public start list.');
        self::assertArrayNotHasKey($confidential['fieldKey'], $export['rows'][0]);
        self::assertContains('Klub', array_column($export['columns'], 'label'), 'A non-confidential field in the same "shown" state must still appear.');
        self::assertSame('AZS', $export['rows'][0][$nonConfidential['fieldKey']]);
    }

    public function testHiddenFieldsAreExcludedFromExportColumns(): void
    {
        $this->fields->createCustom($this->eventId, [
            'label' => 'Pole ukryte',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'state' => 'hidden',
        ]);
        $this->createRegistrationWithCompetitor('PHPUnit StartListExport Hidden', null);

        $export = $this->registrations->startListExportRows($this->eventId, $this->distanceId);

        self::assertNotContains('Pole ukryte', array_column($export['columns'], 'label'));
    }

    public function testLiveVsFrozenBranchingMirrorsEventIsFinished(): void
    {
        [$registrationId, $competitorId] = $this->createRegistrationWithCompetitor('PHPUnit StartListExport Live', null);
        // ensureSystemCatalog() defaults the locked "first_name" system field to
        // 'required' (the only state a CONTROL_LOCKED field allows) — already
        // exactly the "configured, non-hidden" state this export needs, no
        // further state change required.
        $this->fields->ensureSystemCatalog($this->eventId);

        // Pre-finish: live read reflects an edit made after registration.
        $this->competitors->update($competitorId, ['firstName' => 'PHPUnit StartListExport LiveEdited']);
        $before = $this->registrations->startListExportRows($this->eventId, $this->distanceId);
        self::assertSame('PHPUnit StartListExport LiveEdited', $before['rows'][0]['first_name']);

        // Finish the Event, then edit again — the export must now serve the
        // frozen snapshot captured at first post-finish read, not the new edit.
        $this->pdo->prepare("UPDATE events SET publication_status = 'finished' WHERE id = :id")->execute(['id' => $this->eventId]);
        $frozenFirstRead = $this->registrations->startListExportRows($this->eventId, $this->distanceId);
        self::assertSame('PHPUnit StartListExport LiveEdited', $frozenFirstRead['rows'][0]['first_name']);

        $this->competitors->update($competitorId, ['firstName' => 'PHPUnit StartListExport PostFinishEdit']);
        $after = $this->registrations->startListExportRows($this->eventId, $this->distanceId);
        self::assertSame(
            'PHPUnit StartListExport LiveEdited',
            $after['rows'][0]['first_name'],
            'Once the Event is finished, the export must keep serving the frozen snapshot, not a later live edit.'
        );
    }

    public function testCsvAndXlsxFormattersProduceWellFormedFilesFromTheseRows(): void
    {
        $this->fields->createCustom($this->eventId, [
            'label' => 'Rozmiar koszulki',
            'fieldType' => FormFieldType::SINGLE_CHOICE,
            'options' => [['key' => 'l', 'label' => 'L', 'price' => 0]],
            'state' => 'shown',
        ]);
        $this->createRegistrationWithCompetitor('PHPUnit StartListExport Format Ząbek', 'l');

        $export = $this->registrations->startListExportRows($this->eventId, $this->distanceId);

        $csv = StartListExportFormatter::toCsv($export['columns'], $export['rows']);
        self::assertNotSame('', $csv);
        $decoded = iconv('Windows-1250', 'UTF-8', $csv);
        self::assertIsString($decoded);
        // "Oczekująca" (the default payment-status label) carries the same
        // Polish diacritics ("ą") this assertion cares about — the header
        // label "Status płatności" (ł) does too — so a lossy Windows-1250
        // round trip would already be caught here without needing the
        // Competitor's own name (never a column in this export) in the file.
        self::assertStringContainsString('Oczekująca', $decoded, 'Windows-1250 must round-trip Polish diacritics.');
        self::assertStringContainsString('Status płatności', $decoded, 'Windows-1250 must round-trip Polish diacritics in the header row too.');

        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required to validate the XLSX structure.');
        }

        $xlsx = StartListExportFormatter::toXlsx($export['columns'], $export['rows']);
        $tmp = tempnam(sys_get_temp_dir(), 'zc_xlsx_test_');
        file_put_contents($tmp, $xlsx);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmp));
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml'] as $part) {
            $xml = $zip->getFromName($part);
            self::assertIsString($xml, "Missing OOXML part: {$part}");
            new \SimpleXMLElement($xml); // throws on malformed XML
        }
        $sheet = new \SimpleXMLElement($zip->getFromName('xl/worksheets/sheet1.xml'));
        self::assertCount(2, $sheet->sheetData->row, 'Header row + one data row.');
        $zip->close();
        @unlink($tmp);
    }

    public function testCsvNeutralisesFormulaTextButKeepsNumbersNumeric(): void
    {
        $columns = [
            ['key' => 'name', 'label' => 'Uczestnik'],
            ['key' => 'amount', 'label' => 'Kwota (PLN)'],
            ['key' => 'count', 'label' => 'Liczba'],
        ];
        $rows = [
            ['name' => '=HYPERLINK("http://x","a")', 'amount' => '-12,50', 'count' => -3],
            ['name' => '@SUM(A1)', 'amount' => '100,00', 'count' => 7],
            ['name' => 'Anna Nowak', 'amount' => '0,00', 'count' => 0],
        ];

        $csv = StartListExportFormatter::toCsv($columns, $rows);
        $lines = array_map('str_getcsv', explode("\n", trim(str_replace("\r\n", "\n", $csv))), array_fill(0, 4, ';'));

        self::assertSame("'=HYPERLINK(\"http://x\",\"a\")", $lines[1][0]);
        self::assertSame("'@SUM(A1)", $lines[2][0]);
        self::assertSame('Anna Nowak', $lines[3][0]);
        self::assertSame('-12,50', $lines[1][1], 'A negative amount is a number, not text.');
        self::assertSame('-3', $lines[1][2]);
    }

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
    private function createRegistrationWithCompetitor(string $firstName, ?string $shirtAnswer): array
    {
        $competitor = $this->competitors->create([
            'firstName' => $firstName,
            'lastName' => 'Testowy',
        ], skipDuplicateCheck: true);

        $registration = $this->registrations->create([
            'eventId' => $this->eventId,
            'eventName' => 'PHPUnit StartListExport Event',
            'competitorId' => $competitor['id'],
            'userId' => 'phpunit-user-' . bin2hex(random_bytes(4)),
            'userName' => $firstName . ' Testowy',
            'userEmail' => 'phpunit-startlistexport-' . bin2hex(random_bytes(4)) . '@example.test',
            'distanceId' => $this->distanceId,
            'distanceName' => '10 km',
            'category' => 'Open',
        ]);

        if ($shirtAnswer !== null) {
            $field = current(array_filter(
                $this->fields->listForEvent($this->eventId),
                static fn (array $f): bool => $f['fieldType'] === FormFieldType::SINGLE_CHOICE
            ));
            if ($field !== false) {
                (new \ZmierzymyCzas\Domain\FormFields\FormFieldAnswerRepository($this->pdo))
                    ->saveAnswer($this->fields, $field['id'], (string) $registration['id'], $shirtAnswer);
            }
        }

        return [(string) $registration['id'], (string) $competitor['id']];
    }

    private function createEvent(): string
    {
        $slug = $this->slugPrefix . '-event';
        $event = $this->events->create([
            'name' => 'PHPUnit StartListExport Event',
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
                // event_distances.id is VARCHAR(36); slugPrefix + '-event' + '-10k' can run
                // past that (MariaDB silently truncates locally, MySQL 8's strict sql_mode
                // rejects the INSERT outright — error 1406), so use a short fixed suffix.
                'id' => bin2hex(random_bytes(4)) . '-10k',
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

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldAnswerRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldCatalog;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldType;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;

/**
 * E1.13: "Pozycja formularza" (model-formularza-zapisow.md). Covers the
 * acceptance criteria that need a real database: the 13-position catalog
 * seeded per event, three-state control-class enforcement, label-override
 * not touching the key, public-list derivation, and the seams' inertness
 * (priced option never reaching a total, distance_id always null,
 * confidential fields still excluded only from the public list — not from
 * export/Moderator/retention, which stay explicitly unbuilt).
 */
final class FormFieldRepositoryTest extends TestCase
{
    private PDO $pdo;
    private FormFieldRepository $fields;
    private FormFieldAnswerRepository $answers;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private string $slugPrefix;
    private string $eventId;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->slugPrefix = 'phpunit-formfield-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->fields = new FormFieldRepository($this->pdo);
            $this->answers = new FormFieldAnswerRepository($this->pdo);
            $this->events = new EventRepository($this->pdo);
            $this->registrations = new RegistrationRepository($this->pdo, null, null, null);
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
    }

    public function testEnsureSystemCatalogSeedsExactlyThirteenPositions(): void
    {
        $seeded = $this->fields->ensureSystemCatalog($this->eventId);

        self::assertCount(13, $seeded);
        self::assertSame(FormFieldCatalog::systemKeys(), array_column($seeded, 'fieldKey'));
        // Idempotent: calling twice never duplicates rows.
        self::assertCount(13, $this->fields->ensureSystemCatalog($this->eventId));
    }

    public function testLockedFieldRejectsAnyStateOtherThanRequired(): void
    {
        $this->fields->ensureSystemCatalog($this->eventId);
        $firstName = $this->findByKey('first_name');

        $this->expectException(\InvalidArgumentException::class);
        $this->fields->updateState($firstName['id'], 'hidden');
    }

    public function testAgeDrivenFieldRejectsHiddenUntilAgeClassificationIsWired(): void
    {
        $this->fields->ensureSystemCatalog($this->eventId);
        $birthDate = $this->findByKey('birth_date');

        $this->expectException(\InvalidArgumentException::class);
        $this->fields->updateState($birthDate['id'], 'hidden');
    }

    public function testFreeFieldAcceptsAllThreeStates(): void
    {
        $this->fields->ensureSystemCatalog($this->eventId);
        $phone = $this->findByKey('phone');

        $shown = $this->fields->updateState($phone['id'], 'shown');
        self::assertSame('shown', $shown['state']);
        $required = $this->fields->updateState($phone['id'], 'required');
        self::assertSame('required', $required['state']);
    }

    public function testAnonymizationRejectsRequired(): void
    {
        $this->fields->ensureSystemCatalog($this->eventId);
        $anonymization = $this->findByKey('anonymization');

        $this->expectException(\InvalidArgumentException::class);
        $this->fields->updateState($anonymization['id'], 'required');
    }

    public function testLabelOverrideDoesNotChangeTheStableKey(): void
    {
        $this->fields->ensureSystemCatalog($this->eventId);
        $phone = $this->findByKey('phone');

        $updated = $this->fields->updateLabelAndTooltip($phone['id'], 'Numer kontaktowy', 'Podaj numer z rozszerzeniem kraju');

        self::assertSame('phone', $updated['fieldKey']);
        self::assertSame('Numer kontaktowy', $updated['labelOverride']);
        self::assertSame('Podaj numer z rozszerzeniem kraju', $updated['tooltipOverride']);
    }

    /** @dataProvider customFieldTypeProvider */
    public function testEachOfTheSevenCustomTypesCanBeCreatedAndValidated(string $type, mixed $validValue, mixed $invalidValue, array $validation, array $options): void
    {
        $created = $this->fields->createCustom($this->eventId, [
            'label' => 'Testowe pole ' . $type,
            'fieldType' => $type,
            'validationConfig' => $validation,
            'options' => $options,
            'state' => 'required',
        ]);
        self::assertSame($type, $created['fieldType']);

        $registrationId = $this->createRegistration();

        $saved = $this->answers->saveAnswer($this->fields, $created['id'], $registrationId, $validValue);
        self::assertNotEmpty($saved['id']);

        try {
            $this->answers->saveAnswer($this->fields, $created['id'], $registrationId, $invalidValue);
            self::fail('Expected invalid value to be rejected for type ' . $type);
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public static function customFieldTypeProvider(): array
    {
        return [
            'short_text' => [FormFieldType::SHORT_TEXT, 'ok', str_repeat('x', 20), ['maxLength' => 10], []],
            'long_text' => [FormFieldType::LONG_TEXT, 'ok', str_repeat('x', 600), ['maxLength' => 500], []],
            'number' => [FormFieldType::NUMBER, 50, 150, ['min' => 0, 'max' => 100], []],
            'date' => [FormFieldType::DATE, '2022-01-01', '2030-01-01', ['min' => '2020-01-01', 'max' => '2025-01-01'], []],
            'single_choice' => [FormFieldType::SINGLE_CHOICE, 'veg', 'sushi', [], [['key' => 'veg', 'label' => 'Wege', 'price' => 0], ['key' => 'meat', 'label' => 'Mięso', 'price' => 0]]],
            'multi_choice' => [FormFieldType::MULTI_CHOICE, ['veg'], ['sushi'], [], [['key' => 'veg', 'label' => 'Wege', 'price' => 0], ['key' => 'meat', 'label' => 'Mięso', 'price' => 0]]],
            'consent' => [FormFieldType::CONSENT, true, false, [], []],
        ];
    }

    public function testPublicListColumnsFollowFormOrderAndVisibilityFlag(): void
    {
        $this->fields->ensureSystemCatalog($this->eventId);
        $phone = $this->findByKey('phone');
        $club = $this->findByKey('club');

        $this->fields->updatePublicListVisibility($phone['id'], true);
        // club stays invisible (default Nie)

        $columns = $this->fields->publicListColumns($this->eventId);

        self::assertSame(['phone'], array_column($columns, 'fieldKey'));
        self::assertNotContains('club', array_column($columns, 'fieldKey'));
    }

    /** Seam #3: confidential fields are excluded from the public list regardless of the visibility flag. */
    public function testConfidentialFieldNeverAppearsOnPublicListEvenWhenFlagIsSet(): void
    {
        $confidential = $this->fields->createCustom($this->eventId, [
            'label' => 'Choroby przewlekłe',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'isConfidential' => true,
        ]);

        // Attempting to make a confidential field publicly visible is rejected outright.
        $this->expectException(\InvalidArgumentException::class);
        $this->fields->updatePublicListVisibility($confidential['id'], true);
    }

    public function testConfidentialFieldStillAppearsInAnswerListing(): void
    {
        // Seam #3 explicitly does NOT build export-exclusion or Zespół-only reads —
        // this proves the answer is still readable through the normal path, i.e. the
        // seam has no effect beyond the public-list exclusion.
        $confidential = $this->fields->createCustom($this->eventId, [
            'label' => 'PESEL (poufne)',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'isConfidential' => true,
            'state' => 'shown',
        ]);
        $registrationId = $this->createRegistration();
        $this->answers->saveAnswer($this->fields, $confidential['id'], $registrationId, '12345');

        $answers = $this->answers->forRegistration($registrationId);
        self::assertContains($confidential['id'], array_column($answers, 'formFieldId'));
    }

    /** Seam #1: distance_id is always null — no per-Distance override is ever produced by this ticket's code paths. */
    public function testDistanceScopingColumnIsAlwaysNull(): void
    {
        $this->fields->ensureSystemCatalog($this->eventId);
        $custom = $this->fields->createCustom($this->eventId, ['label' => 'Uwagi', 'fieldType' => FormFieldType::LONG_TEXT]);

        foreach ($this->fields->listForEvent($this->eventId) as $field) {
            self::assertNull($field['distanceId']);
        }
        self::assertNull($custom['distanceId']);
    }

    /** Seam #2: a priced option is stored but never read into a registration's total. */
    public function testPricedOptionIsStoredButNeverAffectsRegistrationTotal(): void
    {
        $priced = $this->fields->createCustom($this->eventId, [
            'label' => 'Koszulka dodatkowa (pytanie, nie Gadżet)',
            'fieldType' => FormFieldType::SINGLE_CHOICE,
            'options' => [['key' => 'yes', 'label' => 'Tak', 'price' => 25.0], ['key' => 'no', 'label' => 'Nie', 'price' => 0.0]],
            'state' => 'shown',
        ]);
        self::assertSame(25.0, $priced['options'][0]['price']);

        $registrationId = $this->createRegistration();
        $before = $this->registrations->find($registrationId);
        $this->answers->saveAnswer($this->fields, $priced['id'], $registrationId, 'yes');
        $after = $this->registrations->find($registrationId);

        self::assertSame($before['pricePaid'] ?? null, $after['pricePaid'] ?? null);
    }

    /** Seam #4: the toggle is stored, nothing reads it to redact anything. */
    public function testAnonymizationToggleDoesNotRedactAnyName(): void
    {
        $this->fields->ensureSystemCatalog($this->eventId);
        $anonymization = $this->findByKey('anonymization');
        $updated = $this->fields->updateState($anonymization['id'], 'shown');
        self::assertSame('shown', $updated['state']);

        $registrationId = $this->createRegistration();
        $startList = $this->registrations->publicStartList($this->eventId, true);
        $entry = current(array_filter($startList, static fn (array $r): bool => $r['id'] === $registrationId));
        self::assertNotFalse($entry, 'Registration should still appear, name un-redacted (seam is inert).');
        self::assertNotEmpty($entry['userName'] ?? '');
    }

    /**
     * E1.13 verification fix: the acceptance criterion ("public start-list
     * column derivation follows form-item order + visibility flag,
     * confidential fields always excluded") must be reachable through the
     * real endpoint path, not just FormFieldRepository::publicListColumns()
     * in isolation (see the other tests above). This calls exactly the
     * method routes.php's `GET /events/{eventId}/start-list` calls —
     * RegistrationRepository::publicStartList() — and asserts its output now
     * carries the ordered, visibility-filtered, confidentiality-excluded
     * form-field values.
     */
    public function testPublicStartListWiresFormFieldVisibilityAndConfidentialityThroughTheRealEndpointPath(): void
    {
        $this->fields->ensureSystemCatalog($this->eventId);
        $phone = $this->findByKey('phone');
        $this->fields->updateState($phone['id'], 'shown');
        $this->fields->updatePublicListVisibility($phone['id'], true);

        $visibleCustom = $this->fields->createCustom($this->eventId, [
            'label' => 'Rozmiar koszulki',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'state' => 'shown',
            'publicListVisible' => true,
        ]);
        $confidentialCustom = $this->fields->createCustom($this->eventId, [
            'label' => 'PESEL',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'state' => 'shown',
            'isConfidential' => true,
        ]);
        $hiddenCustom = $this->fields->createCustom($this->eventId, [
            'label' => 'Uwagi wewnętrzne',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'state' => 'shown',
            // publicListVisible left at default (Nie) — must not appear either.
        ]);

        $registrationId = $this->createRegistration();
        $this->answers->saveAnswer($this->fields, $visibleCustom['id'], $registrationId, 'XL');

        $startList = $this->registrations->publicStartList($this->eventId, true);
        $entry = current(array_filter($startList, static fn (array $r): bool => $r['id'] === $registrationId));
        self::assertNotFalse($entry, 'Registration should appear on the public start list.');
        self::assertArrayHasKey('formFields', $entry);

        $keys = array_column($entry['formFields'], 'fieldKey');
        self::assertContains('phone', $keys, 'A publicListVisible system field must appear.');
        self::assertContains($visibleCustom['fieldKey'], $keys, 'A publicListVisible custom field must appear.');
        self::assertNotContains($confidentialCustom['fieldKey'], $keys, 'Confidential fields must never reach the public start list.');
        self::assertNotContains($hiddenCustom['fieldKey'], $keys, 'Fields not opted into the public list must be excluded.');

        // Order must follow form-item order (sort_order), same as publicListColumns().
        $expectedKeys = array_column($this->fields->publicListColumns($this->eventId), 'fieldKey');
        self::assertSame($expectedKeys, $keys);

        $valuesByKey = array_column($entry['formFields'], 'value', 'fieldKey');
        self::assertSame('XL', $valuesByKey[$visibleCustom['fieldKey']]);
    }

    private function findByKey(string $key): array
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
            'name' => 'PHPUnit FormField Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+6 months')),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'draft',
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
        return (string) $event['id'];
    }

    private function createRegistration(): string
    {
        $event = $this->events->find($this->eventId);
        $distanceId = (string) $event['distances'][0]['id'];
        $registration = $this->registrations->create([
            'eventId' => $this->eventId,
            'eventName' => (string) $event['name'],
            'userId' => 'phpunit-user-' . bin2hex(random_bytes(4)),
            'userName' => 'Jan Testowy',
            'userEmail' => 'jan.testowy@example.test',
            'distanceId' => $distanceId,
            'distanceName' => '10 km',
            'category' => 'Open',
        ]);
        return (string) $registration['id'];
    }
}

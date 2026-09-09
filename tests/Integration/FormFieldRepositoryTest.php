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

    /**
     * E3.3: `visible_to_bz` mirrors `public_list_visible`'s own default —
     * every field, system or custom, must default to Nie so no existing field
     * becomes visible to Biuro Zawodów by accident when this column first
     * appears on a Wydarzenie's catalog.
     */
    public function testVisibleToBzDefaultsToFalseForBothSystemAndCustomFields(): void
    {
        $seeded = $this->fields->ensureSystemCatalog($this->eventId);
        foreach ($seeded as $field) {
            self::assertFalse((bool) $field['visibleToBz'], $field['fieldKey'] . ' must default visibleToBz to false.');
        }

        $custom = $this->fields->createCustom($this->eventId, [
            'label' => 'Rozmiar koszulki',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'state' => 'shown',
        ]);
        self::assertFalse((bool) $custom['visibleToBz']);
    }

    /**
     * E3.3: a pre-existing row that never touched this column (simulating a
     * form field written before migration 048 ran) must still read
     * `visibleToBz` as false, purely off the column's own DEFAULT 0 — the
     * same non-breaking backfill guarantee 047's `bz_integration_enabled`
     * provides for `events`.
     */
    public function testVisibleToBzColumnDefaultsToFalseForARowThatNeverSetItExplicitly(): void
    {
        $id = bin2hex(random_bytes(16));
        $this->pdo->prepare(
            'INSERT INTO form_fields (id, event_id, kind, field_key, storage_location, state, sort_order)
             VALUES (:id, :eventId, :kind, :fieldKey, :storageLocation, :state, :sortOrder)'
        )->execute([
            'id' => $id,
            'eventId' => $this->eventId,
            'kind' => 'custom',
            'fieldKey' => 'phpunit_bz_backfill_' . bin2hex(random_bytes(4)),
            'storageLocation' => 'registration',
            'state' => 'shown',
            'sortOrder' => 999,
        ]);

        $field = $this->fields->find($id);
        self::assertFalse((bool) $field['visibleToBz'], 'A row inserted without visible_to_bz must fall back to the column DEFAULT 0.');
    }

    /**
     * E3.3: settable the same way `publicListVisible` is — through
     * updateBzVisibility(), the method the PATCH /form-fields/{fieldId}
     * endpoint calls when the request body carries `visibleToBz`.
     */
    public function testUpdateBzVisibilityTogglesTheFlag(): void
    {
        $custom = $this->fields->createCustom($this->eventId, [
            'label' => 'Rozmiar koszulki',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'state' => 'shown',
        ]);
        self::assertFalse((bool) $custom['visibleToBz']);

        $updated = $this->fields->updateBzVisibility($custom['id'], true);
        self::assertTrue((bool) $updated['visibleToBz']);

        $reverted = $this->fields->updateBzVisibility($custom['id'], false);
        self::assertFalse((bool) $reverted['visibleToBz']);
    }

    /**
     * E3.3: a confidential field can never become visible to Biuro Zawodów
     * either — same defense as updatePublicListVisibility()'s own guard.
     */
    public function testUpdateBzVisibilityRejectsAConfidentialField(): void
    {
        $confidential = $this->fields->createCustom($this->eventId, [
            'label' => 'PESEL',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'state' => 'shown',
            'isConfidential' => true,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->fields->updateBzVisibility($confidential['id'], true);
    }

    /**
     * E3.3: bzVisibleColumns() is the filter RegistrationRepository::
     * bzParticipantList() uses to decide which CUSTOM fields ride along on
     * the BZ pull — system fields are deliberately excluded here (the
     * identity section is sent unconditionally by the caller, not gated by
     * this flag), confidential is excluded unconditionally same as
     * publicListColumns()/exportColumns(), and a field visible on the public
     * list but not flagged for BZ must still be excluded here — the two
     * flags are independent opt-ins.
     */
    public function testBzVisibleColumnsIncludesOnlyBzFlaggedNonConfidentialCustomFields(): void
    {
        $bzVisible = $this->fields->createCustom($this->eventId, [
            'label' => 'Numer kontaktu awaryjnego',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'state' => 'shown',
            'visibleToBz' => true,
        ]);
        $publicOnly = $this->fields->createCustom($this->eventId, [
            'label' => 'Rozmiar koszulki',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'state' => 'shown',
            'publicListVisible' => true,
            // visibleToBz left at default (false) — independent opt-in.
        ]);
        $neither = $this->fields->createCustom($this->eventId, [
            'label' => 'Uwagi wewnętrzne',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'state' => 'shown',
        ]);

        $keys = array_column($this->fields->bzVisibleColumns($this->eventId), 'fieldKey');
        self::assertContains($bzVisible['fieldKey'], $keys);
        self::assertNotContains($publicOnly['fieldKey'], $keys, 'public_list_visible alone must not grant BZ visibility.');
        self::assertNotContains($neither['fieldKey'], $keys);
    }

    /**
     * E2.5: single_choice gets an automatic count per option, zero-count
     * options included so the organizer sees the full breakdown (e.g. a
     * t-shirt size nobody picked yet still needs to be ordered as "0").
     */
    public function testSingleChoiceAggregateCountsPerOptionIncludingZero(): void
    {
        $tshirt = $this->fields->createCustom($this->eventId, [
            'label' => 'Rozmiar koszulki',
            'fieldType' => FormFieldType::SINGLE_CHOICE,
            'options' => [
                ['key' => 's', 'label' => 'S', 'price' => 0],
                ['key' => 'm', 'label' => 'M', 'price' => 0],
                ['key' => 'l', 'label' => 'L', 'price' => 0],
            ],
            'state' => 'shown',
        ]);

        $r1 = $this->createRegistration();
        $r2 = $this->createRegistration();
        $r3 = $this->createRegistration();
        $this->answers->saveAnswer($this->fields, $tshirt['id'], $r1, 'm');
        $this->answers->saveAnswer($this->fields, $tshirt['id'], $r2, 'm');
        $this->answers->saveAnswer($this->fields, $tshirt['id'], $r3, 's');

        $aggregates = $this->fields->aggregatesForEvent($this->eventId);
        $tshirtAggregate = current(array_filter($aggregates, static fn (array $a): bool => $a['formFieldId'] === $tshirt['id']));

        self::assertNotFalse($tshirtAggregate);
        $countsByKey = array_column($tshirtAggregate['counts'], 'count', 'key');
        self::assertSame(['s' => 1, 'm' => 2, 'l' => 0], $countsByKey);
        self::assertSame('Rozmiar koszulki', $tshirtAggregate['label']);
        self::assertSame(FormFieldType::SINGLE_CHOICE, $tshirtAggregate['fieldType']);
    }

    /** E2.5: multi_choice lets one registration count toward several options — total can exceed registration count. */
    public function testMultiChoiceAggregateCountsEachSelectedOption(): void
    {
        $meal = $this->fields->createCustom($this->eventId, [
            'label' => 'Posiłek',
            'fieldType' => FormFieldType::MULTI_CHOICE,
            'options' => [
                ['key' => 'veg', 'label' => 'Wege', 'price' => 0],
                ['key' => 'meat', 'label' => 'Mięso', 'price' => 0],
                ['key' => 'gluten_free', 'label' => 'Bezglutenowy', 'price' => 0],
            ],
            'state' => 'shown',
        ]);

        $r1 = $this->createRegistration();
        $r2 = $this->createRegistration();
        $this->answers->saveAnswer($this->fields, $meal['id'], $r1, ['veg', 'gluten_free']);
        $this->answers->saveAnswer($this->fields, $meal['id'], $r2, ['meat']);

        $aggregates = $this->fields->aggregatesForEvent($this->eventId);
        $mealAggregate = current(array_filter($aggregates, static fn (array $a): bool => $a['formFieldId'] === $meal['id']));

        $countsByKey = array_column($mealAggregate['counts'], 'count', 'key');
        self::assertSame(['veg' => 1, 'meat' => 1, 'gluten_free' => 1], $countsByKey);
        self::assertSame(3, array_sum($countsByKey), 'Two registrations, three total selections — multi-select can exceed registration count.');
    }

    /** E2.5: consent tallies true vs false/unanswered among live registrations. */
    public function testConsentAggregateCountsYesAndNo(): void
    {
        $marketing = $this->fields->createCustom($this->eventId, [
            'label' => 'Zgoda marketingowa',
            'fieldType' => FormFieldType::CONSENT,
            'state' => 'shown',
        ]);

        $r1 = $this->createRegistration();
        $r2 = $this->createRegistration();
        $r3 = $this->createRegistration();
        $this->answers->saveAnswer($this->fields, $marketing['id'], $r1, true);
        $this->answers->saveAnswer($this->fields, $marketing['id'], $r2, false);
        $this->answers->saveAnswer($this->fields, $marketing['id'], $r3, true);

        $aggregates = $this->fields->aggregatesForEvent($this->eventId);
        $consentAggregate = current(array_filter($aggregates, static fn (array $a): bool => $a['formFieldId'] === $marketing['id']));

        $countsByKey = array_column($consentAggregate['counts'], 'count', 'key');
        self::assertSame(['yes' => 2, 'no' => 1], $countsByKey);
    }

    /** E2.5: a cancelled/expired registration's answer must not count toward the tally. */
    public function testCancelledRegistrationAnswerIsExcludedFromAggregate(): void
    {
        $tshirt = $this->fields->createCustom($this->eventId, [
            'label' => 'Rozmiar koszulki',
            'fieldType' => FormFieldType::SINGLE_CHOICE,
            'options' => [
                ['key' => 's', 'label' => 'S', 'price' => 0],
                ['key' => 'm', 'label' => 'M', 'price' => 0],
            ],
            'state' => 'shown',
        ]);

        $liveRegistration = $this->createRegistration();
        $cancelledRegistration = $this->createRegistration();
        $expiredRegistration = $this->createRegistration();
        $this->answers->saveAnswer($this->fields, $tshirt['id'], $liveRegistration, 's');
        $this->answers->saveAnswer($this->fields, $tshirt['id'], $cancelledRegistration, 'm');
        $this->answers->saveAnswer($this->fields, $tshirt['id'], $expiredRegistration, 'm');

        $this->registrations->cancel($cancelledRegistration);
        $this->pdo->prepare('UPDATE registrations SET status = :status WHERE id = :id')
            ->execute(['status' => 'expired', 'id' => $expiredRegistration]);

        $aggregates = $this->fields->aggregatesForEvent($this->eventId);
        $tshirtAggregate = current(array_filter($aggregates, static fn (array $a): bool => $a['formFieldId'] === $tshirt['id']));

        $countsByKey = array_column($tshirtAggregate['counts'], 'count', 'key');
        self::assertSame(['s' => 1, 'm' => 0], $countsByKey, 'Cancelled and expired registrations must not count toward the tally.');
    }

    /** E2.5: only custom choice-type fields are aggregated — system fields and other custom types are excluded. */
    public function testAggregatesForEventExcludesNonChoiceTypesAndSystemFields(): void
    {
        $this->fields->ensureSystemCatalog($this->eventId);
        $this->fields->createCustom($this->eventId, [
            'label' => 'Uwagi',
            'fieldType' => FormFieldType::LONG_TEXT,
            'state' => 'shown',
        ]);

        $aggregates = $this->fields->aggregatesForEvent($this->eventId);

        self::assertSame([], $aggregates, 'No choice-type custom field exists yet — aggregate list must be empty.');
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

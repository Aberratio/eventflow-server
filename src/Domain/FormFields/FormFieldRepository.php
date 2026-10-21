<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\FormFields;

use ZmierzymyCzas\Domain\Repository;

/**
 * "Pozycja formularza" (model-formularza-zapisow.md §1) — jeden byt dla pól
 * systemowych i własnych, jedna uporządkowana lista per Wydarzenie.
 *
 * Cztery rzeczy, które ten ticket świadomie zostawia jako udokumentowane
 * szwy (seam), zamiast implementować (patrz §10 modelu — "poza umową"):
 *
 *  1. Nadpisywanie per Dystans (§7) — `distance_id` istnieje w schemacie
 *     (migracja 041) i jest tu zawsze NULL; ta klasa nie ma żadnej metody
 *     resolve-z-nadpisaniem. Dopisanie jej później to dodanie metody, nie
 *     zmiana schematu.
 *  2. Cena wariantu (§4.1) — `options[].price` jest zapisywana i zwracana,
 *     ale żadna metoda tej klasy ani RegistrationRepository nie sumuje jej do
 *     Rozliczenia. Miejsce, w którym wpięcie by nastąpiło, jest oznaczone
 *     w RegistrationRepository (grep "E1.13 seam").
 *  3. Pole poufne (§4.2) — `is_confidential` blokuje widoczność zarówno na
 *     publicznej liście (`publicListColumns()` poniżej), jak i w eksporcie
 *     organizatora (`exportColumns()` poniżej). Ograniczenie odczytu do
 *     Zespołu (bez Moderatora, ticket 10 §9) i automatyczne kasowanie po
 *     zakończeniu Wydarzenia NIE są tu zbudowane.
 *  4. Anonimizacja (§8/F4) — `anonymization` to zwykła systemowa pozycja ze
 *     stanem Nie/Pokaż, przechowywana jak każda inna. Nic w tej klasie ani
 *     gdziekolwiek indziej nie czyta jej wartości, żeby zamaskować nazwisko
 *     na liście/wynikach — ten mechanizm nie istnieje.
 */
final class FormFieldRepository extends Repository
{
    private const COLUMNS = 'id, event_id AS eventId, distance_id AS distanceId, kind, field_key AS fieldKey, '
        . 'storage_location AS storageLocation, state, sort_order AS sortOrder, label_override AS labelOverride, '
        . 'tooltip_override AS tooltipOverride, public_list_visible AS publicListVisible, field_type AS fieldType, '
        . 'validation_config AS validationConfig, options, is_confidential AS isConfidential, '
        . 'visible_to_bz AS visibleToBz, '
        . 'created_at AS createdAt, updated_at AS updatedAt';

    private const JSON_FIELDS = ['validationConfig', 'options'];

    // Same two fields as JSON_FIELDS, but named for the snake_case columns
    // used in the pre-insert `$data` array below rather than the camelCase
    // aliases `decode()` sees after a SELECT. `encode()` matches keys
    // literally, so reusing JSON_FIELDS here silently skipped
    // `validation_config` (it only ever matched 'options' by coincidence),
    // leaving raw PHP arrays to hit PDO and violate the column's JSON CHECK
    // constraint.
    private const INSERT_JSON_FIELDS = ['validation_config', 'options'];

    /**
     * Zapewnia istnienie wszystkich 13 pozycji systemowych dla wydarzenia
     * (idempotentne — migracja 041 już to robi dla wydarzeń istniejących
     * w chwili wdrożenia; to wywołanie łata wydarzenia utworzone później, np.
     * w testach, które nie przechodzą przez migrator ponownie).
     *
     * @return array<int, array<string, mixed>>
     */
    public function ensureSystemCatalog(string $eventId): array
    {
        $existingKeys = array_column($this->listForEvent($eventId), 'fieldKey');

        foreach (FormFieldCatalog::systemPositions() as $index => $position) {
            if (in_array($position['key'], $existingKeys, true)) {
                continue;
            }
            $defaultState = FormFieldCatalog::allowedStates($position['key'])[0];
            // Swobodne pola domyślnie ukryte; nieusuwalne/napędzające logikę
            // domyślnie required (jedyny/najsurowszy dozwolony stan).
            if ($position['controlClass'] === FormFieldCatalog::CONTROL_FREE) {
                $defaultState = FormFieldCatalog::STATE_HIDDEN;
            }

            $this->insert('form_fields', $this->encode([
                'id' => $this->id(),
                'event_id' => $eventId,
                'distance_id' => null,
                'kind' => 'system',
                'field_key' => $position['key'],
                'storage_location' => $position['storageLocation'],
                'state' => $defaultState,
                'sort_order' => $index + 1,
                'public_list_visible' => 0,
                'is_confidential' => 0,
                'visible_to_bz' => 0,
            ]));
        }

        return $this->listForEvent($eventId);
    }

    /** @return array<int, array<string, mixed>> */
    public function listForEvent(string $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM form_fields WHERE event_id = :eventId AND distance_scope = \'\' ORDER BY sort_order ASC, created_at ASC');
        $stmt->execute(['eventId' => $eventId]);
        $rows = $stmt->fetchAll();
        return array_map(fn (array $row): array => self::castOptionPrices($this->decode($row, self::JSON_FIELDS)), $rows);
    }

    /**
     * R-04: rows that belong to one Dystans only (overrides of inherited Pozycje + Dystans-only fields).
     * listForEvent() deliberately never returns them, so every event-level reader (public start list,
     * exports, snapshot, aggregates) keeps seeing exactly the event form.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForDistanceOnly(string $eventId, string $distanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM form_fields WHERE event_id = :eventId AND distance_scope = :scope ORDER BY sort_order ASC, created_at ASC');
        $stmt->execute(['eventId' => $eventId, 'scope' => $distanceId]);
        return array_map(fn (array $row): array => self::castOptionPrices($this->decode($row, self::JSON_FIELDS)), $stmt->fetchAll());
    }

    /**
     * R-04: the effective form of one Dystans = the event form (inherited) with the Dystans overrides
     * swapped in at the same position, followed by the Dystans-only fields. Every row carries
     * `source`: 'event' (inherited as is), 'override' (Dystans replaces the inherited Pozycja) or
     * 'distance' (exists on this Dystans only). An empty `$distanceId` yields the plain event form.
     *
     * @return array<int, array<string, mixed>>
     */
    public function effectiveForDistance(string $eventId, ?string $distanceId): array
    {
        $event = $this->ensureSystemCatalog($eventId);
        $distanceId = (string) $distanceId;
        if ($distanceId === '') {
            return array_map(static fn (array $f): array => [...$f, 'source' => 'event'], $event);
        }

        $own = [];
        foreach ($this->listForDistanceOnly($eventId, $distanceId) as $row) {
            $own[(string) $row['fieldKey']] = $row;
        }
        $effective = [];
        foreach ($event as $field) {
            $key = (string) $field['fieldKey'];
            if (isset($own[$key])) {
                $effective[] = [...$own[$key], 'sortOrder' => $field['sortOrder'], 'source' => 'override'];
                unset($own[$key]);
            } else {
                $effective[] = [...$field, 'source' => 'event'];
            }
        }
        foreach ($own as $row) {
            $effective[] = [...$row, 'source' => 'distance'];
        }
        return $effective;
    }

    /**
     * R-04: "Nadpisz na tym Dystansie" - copies the event-level Pozycja into a Dystans-scoped row that
     * the organizer then edits through the normal PATCH endpoint. Copy-on-override: from then on the
     * Dystans row no longer follows later edits of the event row (reset to inherit again).
     *
     * @throws \InvalidArgumentException unknown field / Dystans not of this event
     * @throws FormFieldConflictException the Dystans already overrides this Pozycja
     */
    public function createOverride(string $eventId, string $distanceId, string $eventFieldId): array
    {
        $this->assertDistanceOfEvent($eventId, $distanceId);
        $base = $this->find($eventFieldId);
        if ($base === null || $base['eventId'] !== $eventId || $base['distanceId'] !== null) {
            throw new \InvalidArgumentException('Nie znaleziono pozycji formularza wydarzenia.');
        }
        foreach ($this->listForDistanceOnly($eventId, $distanceId) as $row) {
            if ($row['fieldKey'] === $base['fieldKey']) {
                throw new FormFieldConflictException(FormFieldConflictException::OVERRIDE_EXISTS, 'Ten Dystans już nadpisuje tę pozycję.');
            }
        }

        $id = $this->id();
        $this->pdo->prepare(
            'INSERT INTO form_fields (id, event_id, distance_id, distance_scope, kind, field_key, storage_location, state, sort_order, '
            . 'label_override, tooltip_override, public_list_visible, field_type, validation_config, options, is_confidential, visible_to_bz) '
            . 'SELECT :newId, event_id, :distanceId, :scope, kind, field_key, storage_location, state, sort_order, '
            . 'label_override, tooltip_override, public_list_visible, field_type, validation_config, options, is_confidential, visible_to_bz '
            . 'FROM form_fields WHERE id = :baseId'
        )->execute(['newId' => $id, 'distanceId' => $distanceId, 'scope' => $distanceId, 'baseId' => $eventFieldId]);

        return $this->find($id) ?? [];
    }

    /**
     * R-04: "Przywróć dziedziczenie" - drops the Dystans override of an event-level Pozycja. Refused
     * while registrations hold answers stored under the override (they would be deleted with it).
     *
     * @throws FormFieldConflictException override has answers
     */
    public function resetOverride(string $eventId, string $distanceId, string $eventFieldId): bool
    {
        $base = $this->find($eventFieldId);
        if ($base !== null && $base['distanceId'] === $distanceId) {
            // The override's own id was given (the editor only knows the effective form) - resolve its event-level row.
            $stmt = $this->pdo->prepare("SELECT id FROM form_fields WHERE event_id = :eventId AND field_key = :key AND distance_scope = ''");
            $stmt->execute(['eventId' => $eventId, 'key' => $base['fieldKey']]);
            $baseId = $stmt->fetchColumn();
            $base = $baseId === false ? null : $this->find((string) $baseId);
        }
        if ($base === null || $base['eventId'] !== $eventId || $base['distanceId'] !== null) {
            return false;
        }
        foreach ($this->listForDistanceOnly($eventId, $distanceId) as $row) {
            if ($row['fieldKey'] !== $base['fieldKey']) {
                continue;
            }
            if ($this->countAnswers((string) $row['id']) > 0) {
                throw new FormFieldConflictException(
                    FormFieldConflictException::HAS_ANSWERS,
                    'Nie można przywrócić dziedziczenia: zapisy mają już odpowiedzi w nadpisanym polu.'
                );
            }
            $this->pdo->prepare('DELETE FROM form_fields WHERE id = :id')->execute(['id' => $row['id']]);
            return true;
        }
        return false;
    }

    private function assertDistanceOfEvent(string $eventId, string $distanceId): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM event_distances WHERE id = :id AND event_id = :eventId');
        $stmt->execute(['id' => $distanceId, 'eventId' => $eventId]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new \InvalidArgumentException('Nie znaleziono dystansu tego wydarzenia.');
        }
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM form_fields WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return self::castOptionPrices($this->decode($stmt->fetch(), self::JSON_FIELDS));
    }

    /**
     * A whole-number price (e.g. 25.0) survives normalizeOptions() as a
     * float, but json_encode()/json_decode() round-trips it through the
     * `options` JSON column as an int (JSON has no separate float syntax for
     * whole numbers). Re-cast on the way out so callers always see the float
     * type normalizeOptions() promised, regardless of whether the value just
     * came from storage.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private static function castOptionPrices(?array $row): ?array
    {
        if ($row === null || empty($row['options']) || !is_array($row['options'])) {
            return $row;
        }
        $row['options'] = array_map(static function (array $option): array {
            if (array_key_exists('price', $option)) {
                $option['price'] = (float) $option['price'];
            }
            return $option;
        }, $row['options']);
        return $row;
    }

    /**
     * Tworzy pole własne. Klucz nadawany raz, stabilny (model §5) — nie ma
     * metody, która by go później zmieniała; `updateLabel()`/`updateTooltip()`
     * poniżej celowo dotykają wyłącznie prezentacji.
     *
     * @param array<string, mixed> $data
     */
    public function createCustom(string $eventId, array $data, ?string $distanceId = null): array
    {
        if ($distanceId !== null) {
            $this->assertDistanceOfEvent($eventId, $distanceId);
        }
        $type = (string) ($data['fieldType'] ?? '');
        if (!FormFieldType::isValid($type)) {
            throw new \InvalidArgumentException('Nieznany typ pola własnego.');
        }

        $key = (string) ($data['fieldKey'] ?? ('custom_' . bin2hex(random_bytes(6))));
        // Blokujemy tylko kolizję z katalogiem systemowym, nie treść nazwy —
        // model §1: "Nie blokujemy nazw pól własnych."
        if (FormFieldCatalog::isSystemKey($key)) {
            throw new \InvalidArgumentException('Ten klucz jest zarezerwowany dla pozycji systemowej.');
        }

        $options = FormFieldType::usesOptions($type) ? self::normalizeOptions($data['options'] ?? []) : null;

        if ($distanceId !== null) {
            // A Dystans-only field must not shadow (or duplicate) a Pozycja the event already has.
            $taken = array_column($this->listForEvent($eventId), 'fieldKey');
            $taken = [...$taken, ...array_column($this->listForDistanceOnly($eventId, $distanceId), 'fieldKey')];
            if (in_array($key, $taken, true)) {
                throw new \InvalidArgumentException('Pozycja o tym kluczu już istnieje w formularzu.');
            }
        }

        $id = $this->id();
        $this->insert('form_fields', $this->encode([
            'id' => $id,
            'event_id' => $eventId,
            'distance_id' => $distanceId,
            'distance_scope' => $distanceId ?? '',
            'kind' => 'custom',
            'field_key' => $key,
            'storage_location' => FormFieldCatalog::STORAGE_REGISTRATION, // model F1: pola własne zawsze na Zapisie
            'state' => (string) ($data['state'] ?? FormFieldCatalog::STATE_SHOWN),
            'sort_order' => (int) ($data['sortOrder'] ?? 999),
            'label_override' => $data['label'] ?? null,
            'tooltip_override' => $data['tooltip'] ?? null,
            // Domyślnie Nie, także bez flagi poufności (model §4.2).
            'public_list_visible' => !empty($data['publicListVisible']) ? 1 : 0,
            'field_type' => $type,
            'validation_config' => $data['validationConfig'] ?? null,
            'options' => $options,
            'is_confidential' => !empty($data['isConfidential']) ? 1 : 0,
            // E3.3: default Nie, same non-breaking-by-default stance as
            // public_list_visible above (issue 12 §5).
            'visible_to_bz' => !empty($data['visibleToBz']) ? 1 : 0,
        ], self::INSERT_JSON_FIELDS));

        return $this->find($id) ?? [];
    }

    public function deleteCustom(string $id): bool
    {
        $field = $this->find($id);
        if ($field === null || $field['kind'] !== 'custom') {
            // Systemowe: "można ukryć, nigdy usunąć" (model §1) — usuwanie
            // przez to repozytorium jest wprost niedostępne dla nich.
            return false;
        }
        $this->pdo->prepare('DELETE FROM form_fields WHERE id = :id')->execute(['id' => $id]);
        return true;
    }

    /**
     * B-02: edycja typu i/lub listy wariantów istniejącego pola własnego
     * (zamiast usuń + dodaj). Klucz pola i klucze wariantów zostają stabilne.
     * Reguły bezpieczeństwa wobec już zapisanych odpowiedzi:
     * - zmiana TYPU pola, które ma jakiekolwiek odpowiedzi → 409 HAS_ANSWERS;
     * - USUNIĘCIE wariantu (po kluczu) użytego w odpowiedzi → 409 OPTION_IN_USE;
     *   dodanie, zmiana etykiety i ceny są zawsze dozwolone.
     * Specyfikacja (model §6 / issue 10) zakazuje zmiany typu tylko dla pola
     * odziedziczonego przez Dystans; na poziomie Wydarzenia go nie zakazuje.
     *
     * @param array<string, mixed> $data fieldType?, options?
     * @throws \InvalidArgumentException walidacja (422)
     * @throws FormFieldConflictException kolizja z odpowiedziami (409)
     */
    public function updateDefinition(string $id, array $data): array
    {
        $field = $this->find($id);
        if ($field === null) {
            throw new \InvalidArgumentException('Nie znaleziono pozycji formularza.');
        }

        $hasType = array_key_exists('fieldType', $data);
        $hasOptions = array_key_exists('options', $data);
        if (!$hasType && !$hasOptions) {
            return $field;
        }
        if ($field['kind'] !== 'custom') {
            throw new \InvalidArgumentException('Typ i warianty można zmieniać wyłącznie w polach własnych.');
        }

        $currentType = (string) $field['fieldType'];
        $newType = $hasType ? (string) $data['fieldType'] : $currentType;
        if (!FormFieldType::isValid($newType)) {
            throw new \InvalidArgumentException('Nieznany typ pola własnego.');
        }
        if ($newType !== $currentType && $field['distanceId'] !== null && $this->inheritsFromEvent($field)) {
            // spec 10 §6: a Dystans may not retype a Pozycja it inherits from the event.
            throw new \InvalidArgumentException('Dystans nie może zmienić typu pola odziedziczonego z wydarzenia.');
        }

        $update = [];
        if ($newType !== $currentType) {
            if ($this->countAnswers($id) > 0) {
                throw new FormFieldConflictException(
                    FormFieldConflictException::HAS_ANSWERS,
                    'Nie można zmienić typu pola, które ma już odpowiedzi w Zapisach.'
                );
            }
            $update['field_type'] = $newType;
        }

        if (FormFieldType::usesOptions($newType)) {
            if ($hasOptions) {
                if (!is_array($data['options'])) {
                    throw new \InvalidArgumentException('Warianty muszą być listą.');
                }
                $options = self::normalizeOptions($data['options']);
                $keys = array_column($options, 'key');
                if (count($keys) !== count(array_unique($keys))) {
                    throw new \InvalidArgumentException('Klucze wariantów muszą być unikalne.');
                }
                $this->assertRemovedOptionsUnused($field, $keys);
                $update['options'] = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif ($newType !== $currentType) {
                $update['options'] = json_encode([], JSON_UNESCAPED_UNICODE);
            }
        } else {
            if ($hasOptions && !empty($data['options'])) {
                throw new \InvalidArgumentException('Ten typ pola nie ma wariantów.');
            }
            if ($newType !== $currentType) {
                $update['options'] = null;
            }
        }

        $this->updateById('form_fields', $id, $update);
        return $this->find($id) ?? [];
    }

    /** @param array<string, mixed> $field a Dystans-scoped row */
    private function inheritsFromEvent(array $field): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM form_fields WHERE event_id = :eventId AND field_key = :key AND distance_scope = ''");
        $stmt->execute(['eventId' => $field['eventId'], 'key' => $field['fieldKey']]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function countAnswers(string $fieldId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM form_field_answers WHERE form_field_id = :id');
        $stmt->execute(['id' => $fieldId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $field
     * @param array<int, string> $newKeys
     */
    private function assertRemovedOptionsUnused(array $field, array $newKeys): void
    {
        $removed = array_values(array_diff(
            array_map(static fn (array $o): string => (string) $o['key'], (array) ($field['options'] ?? [])),
            $newKeys
        ));
        if ($removed === []) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT value_text AS valueText, value_json AS valueJson FROM form_field_answers WHERE form_field_id = :id');
        $stmt->execute(['id' => $field['id']]);
        $used = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['valueText'] !== null && $row['valueText'] !== '') {
                $used[(string) $row['valueText']] = true;
            }
            if ($row['valueJson'] !== null && $row['valueJson'] !== '') {
                foreach ((array) json_decode((string) $row['valueJson'], true) as $key) {
                    $used[(string) $key] = true;
                }
            }
        }
        $blocked = array_values(array_filter($removed, static fn (string $k): bool => isset($used[$k])));
        if ($blocked !== []) {
            throw new FormFieldConflictException(
                FormFieldConflictException::OPTION_IN_USE,
                'Nie można usunąć wariantu użytego w odpowiedziach: ' . implode(', ', $blocked) . '.'
            );
        }
    }

    /**
     * Zmienia stan (Nie/Pokaż/Wymagany), egzekwując trzy klasy sterowania
     * (model §3) przez `FormFieldCatalog::allowedStates()`. Dla pól własnych
     * dozwolone są wszystkie trzy stany bez ograniczeń (własne pola są zawsze
     * "swobodne").
     */
    public function updateState(string $id, string $newState): array
    {
        $field = $this->find($id);
        if ($field === null) {
            throw new \InvalidArgumentException('Nie znaleziono pozycji formularza.');
        }

        if ($field['kind'] === 'system') {
            $allowed = FormFieldCatalog::allowedStates($field['fieldKey']);
            if (!in_array($newState, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Ta pozycja dopuszcza wyłącznie stany: %s.',
                    implode(', ', $allowed)
                ));
            }
        } elseif (!in_array($newState, [FormFieldCatalog::STATE_HIDDEN, FormFieldCatalog::STATE_SHOWN, FormFieldCatalog::STATE_REQUIRED], true)) {
            throw new \InvalidArgumentException('Nieznany stan pola.');
        }

        $this->updateById('form_fields', $id, ['state' => $newState]);
        return $this->find($id) ?? [];
    }

    /**
     * Nadpisanie etykiety/tooltipa — wyłącznie prezentacyjne (model §5).
     * Świadomie nie ma parametru `fieldKey` tutaj: klucz jest odczytywany
     * gdziekolwiek indziej wprost z kolumny `field_key`, ta metoda jej nigdy
     * nie dotyka, więc integracje maszynowe (klucz kanoniczny) pozostają
     * nienaruszone niezależnie od tego, ile razy organizator zmieni etykietę.
     */
    public function updateLabelAndTooltip(string $id, ?string $label, ?string $tooltip): array
    {
        if ($this->find($id) === null) {
            throw new \InvalidArgumentException('Nie znaleziono pozycji formularza.');
        }
        $this->updateById('form_fields', $id, [
            'label_override' => $label,
            'tooltip_override' => $tooltip,
        ]);
        return $this->find($id) ?? [];
    }

    /**
     * Widoczność na publicznej liście startowej. Blokowana na trwałe dla pól
     * poufnych (model §4.2: "nigdy na publicznej liście — przełącznik
     * widoczności zablokowany") — próba włączenia jej na polu poufnym jest
     * odrzucana tu, więc `publicListColumns()` poniżej nie musi już nic
     * sprawdzać poza samą flagą (obrona w głąb: sprawdza i tak, patrz niżej).
     */
    public function updatePublicListVisibility(string $id, bool $visible): array
    {
        $field = $this->find($id);
        if ($field === null) {
            throw new \InvalidArgumentException('Nie znaleziono pozycji formularza.');
        }
        if ($visible && !empty($field['isConfidential'])) {
            throw new \InvalidArgumentException('Pole poufne nie może być widoczne na publicznej liście.');
        }
        $this->updateById('form_fields', $id, ['public_list_visible' => $visible ? 1 : 0]);
        return $this->find($id) ?? [];
    }

    /**
     * E3.3: visibility on the Biuro Zawodów pull (issue 12 §5). Same "blocked
     * for confidential fields" defense as updatePublicListVisibility() above —
     * a poufne field (model §4.2) must never reach an external system either,
     * even though the BZ contract is a separate audience from the public
     * start list.
     */
    public function updateBzVisibility(string $id, bool $visible): array
    {
        $field = $this->find($id);
        if ($field === null) {
            throw new \InvalidArgumentException('Nie znaleziono pozycji formularza.');
        }
        if ($visible && !empty($field['isConfidential'])) {
            throw new \InvalidArgumentException('Pole poufne nie może być widoczne dla Biura Zawodów.');
        }
        $this->updateById('form_fields', $id, ['visible_to_bz' => $visible ? 1 : 0]);
        return $this->find($id) ?? [];
    }

    /**
     * TD.23: batched into a single `UPDATE ... CASE WHEN` instead of one
     * query per row — same per-row `id AND event_id` guard as before (a row
     * only changes if both match), same 1-based `sortOrder`, same no-op on
     * an empty list.
     *
     * Native prepares (PDO::ATTR_EMULATE_PREPARES => false, see Connection)
     * reject a named placeholder used more than once in the same query, so
     * each id needs a distinct binding for the CASE branch and another for
     * the IN() list even though the value is identical (see the same
     * pattern in ShopRepository).
     *
     * @param array<int, string> $orderedIds
     */
    public function reorder(string $eventId, array $orderedIds): void
    {
        $ids = array_values($orderedIds);
        if ($ids === []) {
            return;
        }

        $cases = [];
        $inPlaceholders = [];
        $params = ['eventId' => $eventId];
        foreach ($ids as $index => $id) {
            $caseIdParam = 'caseId' . $index;
            $inIdParam = 'inId' . $index;
            $cases[] = "WHEN :{$caseIdParam} THEN :sortOrder{$index}";
            $inPlaceholders[] = ':' . $inIdParam;
            $params[$caseIdParam] = $id;
            $params[$inIdParam] = $id;
            $params['sortOrder' . $index] = $index + 1;
        }

        $sql = sprintf(
            'UPDATE form_fields SET sort_order = CASE id %s END WHERE event_id = :eventId AND id IN (%s)',
            implode(' ', $cases),
            implode(', ', $inPlaceholders)
        );
        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * Model §9: kolumny publicznej listy startowej pochodzą z kolejności
     * i widoczności Pozycji formularza — nie osobnej konfiguracji "Lista".
     * Pola poufne są wykluczone bezwarunkowo, niezależnie od
     * `publicListVisible` (obrona w głąb wobec `updatePublicListVisibility()` —
     * gdyby flaga poufności została ustawiona na polu już widocznym, ta metoda
     * nadal go odetnie).
     *
     * @return array<int, array<string, mixed>> w kolejności formularza
     */
    public function publicListColumns(string $eventId): array
    {
        return array_values(array_filter(
            $this->listForEvent($eventId),
            static fn (array $field): bool => !empty($field['publicListVisible']) && empty($field['isConfidential'])
        ));
    }

    /**
     * E3.3: subset of CUSTOM Pozycje formularza flagged visible to Biuro
     * Zawodów (issue 12 §5, "pola custom oznaczone jako widoczne dla biura
     * zawodów — podzbiór formularza z ticketu 10, nie wszystkie pola").
     * System fields are deliberately excluded here — the identity section
     * (imię/nazwisko/e-mail) is sent to BZ unconditionally by the caller
     * (RegistrationRepository::bzParticipantList()), not gated by this flag,
     * the same way it is not gated by `public_list_visible` on the public
     * start list either. `is_confidential` is excluded unconditionally, same
     * defense-in-depth stance as publicListColumns()/exportColumns() above —
     * see updateBzVisibility()'s guard against the flag being set at all.
     *
     * @return array<int, array<string, mixed>> w kolejności formularza
     */
    public function bzVisibleColumns(string $eventId): array
    {
        return array_values(array_filter(
            $this->listForEvent($eventId),
            static fn (array $field): bool => $field['kind'] === 'custom'
                && !empty($field['visibleToBz'])
                && empty($field['isConfidential'])
        ));
    }

    /**
     * E2.6: organizer-facing start-list export columns — every non-`hidden`,
     * non-`is_confidential` Pozycja formularza (system + custom) in form
     * order. Broader than publicListColumns() above in one way only: it does
     * NOT require `publicListVisible` (that flag only ever governs the
     * public start list page's opt-in, an unrelated gate for this
     * organizer-only export). It still excludes confidential fields, the
     * same as publicListColumns() — an earlier version of this docblock
     * claimed the organizer already sees confidential answers today via
     * RegistrationRepository::byEvent()/ParticipantsTab.tsx; that claim was
     * false (byEvent() never attaches form-field answers at all) and this
     * export would otherwise have been a new personal-data exposure, so the
     * exclusion was added instead. A `hidden` field carries no collected
     * answers for this Wydarzenie at all, so it is left out the same way it
     * would show nothing anyway.
     *
     * @return array<int, array<string, mixed>> w kolejności formularza
     */
    public function exportColumns(string $eventId): array
    {
        return array_values(array_filter(
            $this->listForEvent($eventId),
            static fn (array $field): bool => ($field['state'] ?? '') !== FormFieldCatalog::STATE_HIDDEN
                && empty($field['isConfidential'])
        ));
    }

    /**
     * PP.18: the superset the E1.14 form snapshot is captured with — every non-confidential
     * field in form order, including the 13 system positions (ensureSystemCatalog(), so the BZ
     * identity fields always exist). A superset of publicListColumns(), exportColumns() and the
     * BZ identity + bzVisibleColumns() sets: whichever reader (or the scheduler) captures first,
     * every other reader can be served from it by filtering down to its own column set. Never
     * serve it unfiltered.
     *
     * @return array<int, array<string, mixed>>
     */
    public function snapshotColumns(string $eventId): array
    {
        return array_values(array_filter(
            $this->ensureSystemCatalog($eventId),
            static fn (array $field): bool => empty($field['isConfidential'])
        ));
    }

    /**
     * E2.5: automatic answer-count aggregation for every choice-type custom
     * field (single_choice/multi_choice/consent) of an event — no organizer
     * opt-in flag (model answer §1: "bez osobnej flagi 'zestawialne'"). Only
     * `kind = 'custom'` fields are considered; system fields never carry
     * a `fieldType` and are out of scope here (aggregating e.g. "gender" is
     * a separate, unbuilt cross-section per the spec's §2).
     *
     * Counts only "live" registrations, mirroring the repo-wide
     * NOT_COUNTED_STATUSES idiom (RegistrationRepository) inline rather than
     * importing it, since it's a private constant there and this is the same
     * three-status literal already duplicated at every other call site
     * (EventRepository::capacityByDistance, ResultImportRepository, etc.).
     *
     * @return array<int, array{formFieldId: string, fieldKey: string, label: string, fieldType: string, counts: array<int, array{key: string, label: string, count: int}>}>
     */
    public function aggregatesForEvent(string $eventId): array
    {
        $aggregatableTypes = [FormFieldType::SINGLE_CHOICE, FormFieldType::MULTI_CHOICE, FormFieldType::CONSENT];

        $fields = array_values(array_filter(
            $this->listForEvent($eventId),
            static fn (array $f): bool => $f['kind'] === 'custom' && in_array($f['fieldType'], $aggregatableTypes, true)
        ));

        if ($fields === []) {
            return [];
        }

        $result = [];
        foreach ($fields as $field) {
            $result[] = [
                'formFieldId' => $field['id'],
                'fieldKey' => $field['fieldKey'],
                'label' => $field['labelOverride'] ?? $field['fieldKey'],
                'fieldType' => $field['fieldType'],
                'counts' => $this->countAnswersForField($field),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function countAnswersForField(array $field): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.value_text AS valueText, a.value_json AS valueJson '
            . 'FROM form_field_answers a '
            . 'INNER JOIN registrations r ON r.id = a.registration_id '
            . "WHERE a.form_field_id = :formFieldId AND r.status NOT IN ('cancelled', 'removed', 'expired')"
        );
        $stmt->execute(['formFieldId' => $field['id']]);
        $rows = $stmt->fetchAll();

        $type = (string) $field['fieldType'];

        if ($type === FormFieldType::CONSENT) {
            $yes = 0;
            $no = 0;
            foreach ($rows as $row) {
                if (in_array($row['valueText'], ['1', 'true'], true)) {
                    $yes++;
                } else {
                    $no++;
                }
            }
            return [
                ['key' => 'yes', 'label' => 'Tak', 'count' => $yes],
                ['key' => 'no', 'label' => 'Nie', 'count' => $no],
            ];
        }

        /** @var array<int, array{key: string, label: string, price: float}> $options */
        $options = (array) ($field['options'] ?? []);
        $tally = [];
        foreach ($options as $option) {
            $tally[(string) $option['key']] = 0;
        }

        foreach ($rows as $row) {
            if ($type === FormFieldType::MULTI_CHOICE) {
                $selected = $row['valueJson'] !== null && $row['valueJson'] !== ''
                    ? (array) json_decode((string) $row['valueJson'], true)
                    : [];
            } else {
                $selected = $row['valueText'] !== null && $row['valueText'] !== '' ? [$row['valueText']] : [];
            }
            foreach ($selected as $key) {
                $tally[(string) $key] = ($tally[(string) $key] ?? 0) + 1;
            }
        }

        $counts = [];
        foreach ($options as $option) {
            $key = (string) $option['key'];
            $counts[] = ['key' => $key, 'label' => (string) $option['label'], 'count' => $tally[$key] ?? 0];
        }

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>|string> $rawOptions
     * @return array<int, array{key: string, label: string, price: float}>
     */
    private static function normalizeOptions(array $rawOptions): array
    {
        $options = [];
        foreach ($rawOptions as $index => $option) {
            if (is_string($option)) {
                $options[] = ['key' => $option, 'label' => $option, 'price' => 0.0];
                continue;
            }
            $label = (string) ($option['label'] ?? ('opcja ' . ($index + 1)));
            $options[] = [
                'key' => (string) ($option['key'] ?? $label),
                'label' => $label,
                // Seam #2 (model §4.1): cena jest przechowywana od razu (nawet
                // ujemna, jak dopuszcza model), ale nic w tym repozytorium ani
                // w RegistrationRepository jej nie sumuje do Rozliczenia.
                'price' => (float) ($option['price'] ?? 0.0),
            ];
        }
        return $options;
    }
}

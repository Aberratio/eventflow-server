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
 *  3. Pole poufne (§4.2) — `is_confidential` blokuje TYLKO widoczność na
 *     publicznej liście (`publicListColumns()` poniżej). Wykluczenie
 *     z domyślnego eksportu, ograniczenie odczytu do Zespołu i automatyczne
 *     kasowanie po zakończeniu Wydarzenia NIE są tu zbudowane.
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
        . 'created_at AS createdAt, updated_at AS updatedAt';

    private const JSON_FIELDS = ['validationConfig', 'options'];

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
            ]));
        }

        return $this->listForEvent($eventId);
    }

    /** @return array<int, array<string, mixed>> */
    public function listForEvent(string $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM form_fields WHERE event_id = :eventId ORDER BY sort_order ASC, created_at ASC');
        $stmt->execute(['eventId' => $eventId]);
        $rows = $stmt->fetchAll();
        return array_map(fn (array $row): array => $this->decode($row, self::JSON_FIELDS), $rows);
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM form_fields WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $this->decode($stmt->fetch(), self::JSON_FIELDS);
    }

    /**
     * Tworzy pole własne. Klucz nadawany raz, stabilny (model §5) — nie ma
     * metody, która by go później zmieniała; `updateLabel()`/`updateTooltip()`
     * poniżej celowo dotykają wyłącznie prezentacji.
     *
     * @param array<string, mixed> $data
     */
    public function createCustom(string $eventId, array $data): array
    {
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

        $id = $this->id();
        $this->insert('form_fields', $this->encode([
            'id' => $id,
            'event_id' => $eventId,
            'distance_id' => null, // seam #1 — patrz docblock klasy
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
        ], self::JSON_FIELDS));

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

    public function reorder(string $eventId, array $orderedIds): void
    {
        foreach (array_values($orderedIds) as $index => $id) {
            $this->pdo->prepare('UPDATE form_fields SET sort_order = :sortOrder WHERE id = :id AND event_id = :eventId')
                ->execute(['sortOrder' => $index + 1, 'id' => $id, 'eventId' => $eventId]);
        }
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

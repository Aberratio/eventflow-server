<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\FormFields;

use ZmierzymyCzas\Domain\Repository;

/**
 * "Form item" (Pozycja formularza) — one entity for system and custom fields,
 * one ordered list per Wydarzenie.
 *
 * Four things this class deliberately leaves as documented
 * seams rather than implementing:
 *
 *  1. Per-Dystans override — `distance_id` exists in the schema
 *     (migration 041) and is always NULL here; this class has no
 *     resolve-with-override method. Adding one later is a new method, not a
 *     schema change.
 *  2. Variant price — `options[].price` is stored and returned,
 *     but no method of this class or of RegistrationRepository adds it to the
 *     Rozliczenie. The place where it would be wired in is marked
 *     in RegistrationRepository.
 *  3. Confidential field — `is_confidential` blocks visibility both on the
 *     public list (`publicListColumns()` below) and in the organizer
 *     export (`exportColumns()` below). Restricting reads to the
 *     Team (excluding the Moderator) and automatic deletion after
 *     the Wydarzenie ends are NOT built here.
 *  4. Anonymization — `anonymization` is an ordinary system item with a
 *     No/Show state, stored like any other. Nothing in this class or
 *     anywhere else reads its value to mask a name
 *     in the list/results — that mechanism does not exist.
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
     * Ensures all 13 system items exist for the event
     * (idempotent — migration 041 already does this for events existing
     * at deploy time; this call patches events created later, e.g.
     * in tests that do not run the migrator again).
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
            // Free fields hidden by default; non-removable/logic-driving
            // required by default (the only/strictest allowed state).
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
     * Rows that belong to one Dystans only (overrides of inherited Pozycje + Dystans-only fields).
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
     * The effective form of one Dystans = the event form (inherited) with the Dystans overrides
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
     * "Nadpisz na tym Dystansie" - copies the event-level Pozycja into a Dystans-scoped row that
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
     * "Przywróć dziedziczenie" - drops the Dystans override of an event-level Pozycja. Refused
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
     * Creates a custom field. The key is assigned once and is stable — there is no
     * method that would change it later; `updateLabel()`/`updateTooltip()`
     * below deliberately touch presentation only.
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
        // We only block a collision with the system catalog, not the content of the name —
        // custom field names are not blocked.
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
            'storage_location' => FormFieldCatalog::STORAGE_REGISTRATION, // custom fields always live on the Zapis
            'state' => (string) ($data['state'] ?? FormFieldCatalog::STATE_SHOWN),
            'sort_order' => (int) ($data['sortOrder'] ?? 999),
            'label_override' => $data['label'] ?? null,
            'tooltip_override' => $data['tooltip'] ?? null,
            // Default No, also without the confidentiality flag.
            'public_list_visible' => !empty($data['publicListVisible']) ? 1 : 0,
            'field_type' => $type,
            'validation_config' => $data['validationConfig'] ?? null,
            'options' => $options,
            'is_confidential' => !empty($data['isConfidential']) ? 1 : 0,
            // Default Nie, same non-breaking-by-default stance as
            // public_list_visible above.
            'visible_to_bz' => !empty($data['visibleToBz']) ? 1 : 0,
        ], self::INSERT_JSON_FIELDS));

        return $this->find($id) ?? [];
    }

    public function deleteCustom(string $id): bool
    {
        $field = $this->find($id);
        if ($field === null || $field['kind'] !== 'custom') {
            // System fields: "can be hidden, never deleted" — deleting
            // through this repository is explicitly unavailable for them.
            return false;
        }
        $this->pdo->prepare('DELETE FROM form_fields WHERE id = :id')->execute(['id' => $id]);
        return true;
    }

    /**
     * Edit of the type and/or option list of an existing custom field
     * (instead of delete + add). The field key and option keys stay stable.
     * Safety rules for answers already stored:
     * - changing the field TYPE when it has any answers → 409 HAS_ANSWERS;
     * - REMOVING an option (by key) used in an answer → 409 OPTION_IN_USE;
     *   adding, and changing the label and price, are always allowed.
     * The requirements forbid changing the type only for a field
     * inherited by a Dystans; at the Wydarzenie level they do not forbid it.
     *
     * @param array<string, mixed> $data fieldType?, options?
     * @throws \InvalidArgumentException validation (422)
     * @throws FormFieldConflictException collision with answers (409)
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
            // a Dystans may not retype a Pozycja it inherits from the event.
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
     * Changes the state (No/Show/Required), enforcing the three control classes
     * via `FormFieldCatalog::allowedStates()`. For custom fields
     * all three states are allowed without restriction (custom fields are always
     * "free").
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
     * Label/tooltip override — presentation only.
     * Deliberately no `fieldKey` parameter here: the key is read
     * everywhere else directly from the `field_key` column, and this method never
     * touches it, so machine integrations (the canonical key) stay
     * intact no matter how many times the organizer changes the label.
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
     * Visibility on the public start list. Permanently blocked for
     * confidential fields ("never on the public list — the visibility
     * toggle is blocked") — an attempt to enable it on a confidential field is
     * rejected here, so `publicListColumns()` below need not check anything
     * beyond the flag itself (defense in depth: it checks anyway, see below).
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
     * Visibility on the Biuro Zawodów pull. Same "blocked
     * for confidential fields" defense as updatePublicListVisibility() above —
     * a confidential (poufne) field must never reach an external system either,
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
     * Batched into a single `UPDATE ... CASE WHEN` instead of one
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
     * The public start list columns come from the order
     * and visibility of form items — not a separate "List" configuration.
     * Confidential fields are excluded unconditionally, regardless of
     * `publicListVisible` (defense in depth against `updatePublicListVisibility()` —
     * if the confidentiality flag were set on an already visible field, this method
     * would still cut it off).
     *
     * @return array<int, array<string, mixed>> in form order
     */
    public function publicListColumns(string $eventId): array
    {
        return array_values(array_filter(
            $this->listForEvent($eventId),
            static fn (array $field): bool => !empty($field['publicListVisible']) && empty($field['isConfidential'])
        ));
    }

    /**
     * Subset of CUSTOM Pozycje formularza flagged visible to Biuro
     * Zawodów (a subset of the form, not all fields).
     * System fields are deliberately excluded here — the identity section
     * (first name/last name/e-mail) is sent to BZ unconditionally by the caller
     * (RegistrationRepository::bzParticipantList()), not gated by this flag,
     * the same way it is not gated by `public_list_visible` on the public
     * start list either. `is_confidential` is excluded unconditionally, same
     * defense-in-depth stance as publicListColumns()/exportColumns() above —
     * see updateBzVisibility()'s guard against the flag being set at all.
     *
     * @return array<int, array<string, mixed>> in form order
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
     * Organizer-facing start-list export columns — every non-`hidden`,
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
     * @return array<int, array<string, mixed>> in form order
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
     * The superset the form snapshot is captured with — every non-confidential
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
     * Automatic answer-count aggregation for every choice-type custom
     * field (single_choice/multi_choice/consent) of an event — no organizer
     * opt-in flag (no separate "aggregatable" flag). Only
     * `kind = 'custom'` fields are considered; system fields never carry
     * a `fieldType` and are out of scope here (aggregating e.g. "gender" is
     * a separate, unbuilt cross-section).
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
                // Seam: the price is stored right away (even
                // negative, as the model allows), but nothing in this repository or
                // in RegistrationRepository adds it to the Rozliczenie.
                'price' => (float) ($option['price'] ?? 0.0),
            ];
        }
        return $options;
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

use ZmierzymyCzas\Domain\FormFields\FormFieldAnswerRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldCatalog;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldType;
use ZmierzymyCzas\Domain\Repository;

/**
 * B-07: the form half of `POST /registrations` (spec 10, model-formularza-zapisow).
 *
 *  - `formFieldAnswers: [{fieldId, value}]` answer the Event's custom fields (they live on the Zapis,
 *    model F1). Validation is FormFieldType::validateAnswer() — the same one the PUT endpoint uses —
 *    plus the three-state rule (hidden fields take no answer, required ones must have one).
 *  - `identity: {phone, club, country, city, postalCode, voivodeship, address, isAnonymized}` fills the
 *    system fields of the identity section. That section is a projection of the Zawodnik, so the values
 *    are written to `competitors`. A required system field is satisfied by the payload or by what the
 *    profile already holds. A value for a hidden field is dropped, not an error (the form never shows it).
 *  - priced variants of choice fields become `modifier` Rozliczenie items; the amount is read from the
 *    field's configuration here, never from the request (spec 07 step 4).
 *
 * `prepare()` is read-only and `persist()` writes; RegistrationRepository::create() calls both inside
 * its transaction, so a rejected form leaves no Zapis behind.
 */
final class RegistrationFormService extends Repository
{
    /** identity payload key => [system field key, competitors column, max length (0 = boolean)] */
    private const IDENTITY = [
        'phone' => ['phone', 'phone', 64],
        'club' => ['club', 'club', 180],
        'country' => ['country', 'country', 100],
        'city' => ['city', 'city', 120],
        'postalCode' => ['postal_code', 'postal_code', 20],
        'voivodeship' => ['voivodeship', 'voivodeship', 100],
        'address' => ['address', 'address', 255],
        'isAnonymized' => ['anonymization', 'is_anonymized', 0],
    ];

    /**
     * @param array<string, mixed> $data create() payload
     * @return array{
     *     answers: array<int, array{field: array<string, mixed>, value: mixed}>,
     *     identity: array<string, mixed>,
     *     modifierItems: array<int, array{name: string, basis: string, amount: float}>
     * }
     * @throws RegistrationFormInvalidException
     */
    public function prepare(array $data): array
    {
        $fieldsRepo = new FormFieldRepository($this->pdo);
        $eventId = (string) $data['eventId'];
        $distanceId = (string) ($data['distanceId'] ?? '');
        $fields = $fieldsRepo->ensureSystemCatalog($eventId);
        $errors = [];

        // ── custom fields ────────────────────────────────────────────────
        $byId = [];
        foreach ($fields as $field) {
            if ($field['kind'] === 'custom' && $this->appliesToDistance($field, $distanceId)) {
                $byId[(string) $field['id']] = $field;
            }
        }

        $rawAnswers = $data['formFieldAnswers'] ?? [];
        if (!is_array($rawAnswers) || ($rawAnswers !== [] && !array_is_list($rawAnswers))) {
            throw new RegistrationFormInvalidException([$this->error(null, null, 'invalid_payload', 'formFieldAnswers musi być listą obiektów {fieldId, value}.')]);
        }
        $given = [];
        foreach ($rawAnswers as $entry) {
            $fieldId = is_array($entry) && is_scalar($entry['fieldId'] ?? null) ? (string) $entry['fieldId'] : '';
            if ($fieldId === '') {
                $errors[] = $this->error(null, null, 'invalid_payload', 'Każda odpowiedź musi mieć fieldId.');
                continue;
            }
            $field = $byId[$fieldId] ?? null;
            if ($field === null || $field['state'] === FormFieldCatalog::STATE_HIDDEN) {
                $errors[] = $this->error($fieldId, null, 'unknown_field', 'Nie znaleziono pola formularza.');
                continue;
            }
            $given[$fieldId] = $entry['value'] ?? null;
        }

        $answers = [];
        $modifierItems = [];
        foreach ($byId as $fieldId => $field) {
            if ($field['state'] === FormFieldCatalog::STATE_HIDDEN) {
                continue;
            }
            $value = $given[$fieldId] ?? null;
            $required = $field['state'] === FormFieldCatalog::STATE_REQUIRED;
            try {
                FormFieldType::validateAnswer(
                    (string) $field['fieldType'],
                    $value,
                    (array) ($field['validationConfig'] ?? []),
                    (array) ($field['options'] ?? []),
                    $required
                );
            } catch (\InvalidArgumentException $e) {
                $empty = $value === null || $value === '' || $value === [];
                $errors[] = $this->error($fieldId, null, $required && $empty ? 'required' : 'invalid_value', $e->getMessage());
                continue;
            }
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $answers[] = ['field' => $field, 'value' => $value];
            foreach ($this->modifiersFor($field, $value) as $item) {
                $modifierItems[] = $item;
            }
        }

        // ── identity (system fields) ─────────────────────────────────────
        $rawIdentity = $data['identity'] ?? [];
        if (!is_array($rawIdentity) || ($rawIdentity !== [] && array_is_list($rawIdentity))) {
            throw new RegistrationFormInvalidException([$this->error(null, null, 'invalid_payload', 'identity musi być obiektem.')]);
        }
        foreach (array_keys($rawIdentity) as $key) {
            if (!isset(self::IDENTITY[$key])) {
                $errors[] = $this->error(null, (string) $key, 'unknown_field', 'Nieznane pole tożsamości.');
            }
        }

        $stateByKey = [];
        foreach ($fields as $field) {
            if ($field['kind'] === 'system') {
                $stateByKey[(string) $field['fieldKey']] = (string) $field['state'];
            }
        }
        $current = $this->currentCompetitor((string) ($data['competitorId'] ?? ''));

        $identity = [];
        foreach (self::IDENTITY as $payloadKey => [$systemKey, $column, $maxLength]) {
            $state = $stateByKey[$systemKey] ?? FormFieldCatalog::STATE_HIDDEN;
            if ($state === FormFieldCatalog::STATE_HIDDEN) {
                continue;
            }
            $present = array_key_exists($payloadKey, $rawIdentity);
            $raw = $present ? $rawIdentity[$payloadKey] : null;

            if ($maxLength === 0) {
                if ($present && $raw !== null && !is_bool($raw)) {
                    $errors[] = $this->error(null, $systemKey, 'invalid_value', 'Wartość musi być true albo false.');
                } elseif ($present && $raw !== null) {
                    $identity[$column] = $raw ? 1 : 0;
                }
                continue;
            }

            $value = $present && is_scalar($raw) ? trim((string) $raw) : '';
            if ($present && $raw !== null && !is_scalar($raw)) {
                $errors[] = $this->error(null, $systemKey, 'invalid_value', 'Wartość musi być tekstem.');
                continue;
            }
            if ($value === '') {
                $hasStored = trim((string) ($current[$column] ?? '')) !== '';
                if ($state === FormFieldCatalog::STATE_REQUIRED && !$hasStored) {
                    $errors[] = $this->error(null, $systemKey, 'required', 'To pole jest wymagane.');
                } elseif ($present && $hasStored && $state !== FormFieldCatalog::STATE_REQUIRED) {
                    $identity[$column] = null;
                }
                continue;
            }
            if (mb_strlen($value) > $maxLength) {
                $errors[] = $this->error(null, $systemKey, 'invalid_value', sprintf('Tekst może mieć maksymalnie %d znaków.', $maxLength));
                continue;
            }
            if ($column === 'voivodeship' && !in_array(mb_strtolower($value), FormFieldCatalog::voivodeships(), true)) {
                $errors[] = $this->error(null, $systemKey, 'invalid_value', 'Wybrana wartość nie znajduje się na liście województw.');
                continue;
            }
            $identity[$column] = $value;
        }

        if ($errors !== []) {
            throw new RegistrationFormInvalidException($errors);
        }

        return ['answers' => $answers, 'identity' => $identity, 'modifierItems' => $modifierItems];
    }

    /**
     * @param array{answers: array<int, array{field: array<string, mixed>, value: mixed}>, identity: array<string, mixed>} $prepared
     */
    public function persist(string $registrationId, ?string $competitorId, array $prepared): void
    {
        $fieldsRepo = new FormFieldRepository($this->pdo);
        $answersRepo = new FormFieldAnswerRepository($this->pdo);
        foreach ($prepared['answers'] as $answer) {
            $answersRepo->saveAnswer($fieldsRepo, (string) $answer['field']['id'], $registrationId, $answer['value']);
        }
        if ($competitorId !== null && $competitorId !== '' && $prepared['identity'] !== []) {
            $this->updateById('competitors', $competitorId, $prepared['identity']);
        }
    }

    /**
     * @param array<string, mixed> $field
     * @return array<int, array{name: string, basis: string, amount: float}>
     */
    private function modifiersFor(array $field, mixed $value): array
    {
        if (!FormFieldType::usesOptions((string) $field['fieldType'])) {
            return [];
        }
        $selected = array_map('strval', is_array($value) ? array_values($value) : [$value]);
        $label = trim((string) ($field['labelOverride'] ?? '')) !== '' ? (string) $field['labelOverride'] : (string) $field['fieldKey'];
        $items = [];
        // Iterate the configured options (not the client's list): order is stable and a repeated key counts once.
        foreach ((array) ($field['options'] ?? []) as $option) {
            $price = round((float) ($option['price'] ?? 0), 2);
            if ($price === 0.0 || !in_array((string) $option['key'], $selected, true)) {
                continue;
            }
            $items[] = ['name' => $label . ': ' . (string) ($option['label'] ?? $option['key']), 'basis' => 'modifier', 'amount' => $price];
        }
        return $items;
    }

    /** @param array<string, mixed> $field */
    private function appliesToDistance(array $field, string $distanceId): bool
    {
        $scoped = (string) ($field['distanceId'] ?? '');
        return $scoped === '' || $scoped === $distanceId;
    }

    /** @return array<string, mixed> */
    private function currentCompetitor(string $competitorId): array
    {
        if ($competitorId === '') {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT phone, club, country, city, postal_code, voivodeship, address FROM competitors WHERE id = :id');
        $stmt->execute(['id' => $competitorId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /** @return array{fieldId: ?string, fieldKey: ?string, code: string, message: string} */
    private function error(?string $fieldId, ?string $fieldKey, string $code, string $message): array
    {
        return ['fieldId' => $fieldId, 'fieldKey' => $fieldKey, 'code' => $code, 'message' => $message];
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\FormFields;

use ZmierzymyCzas\Domain\Repository;

/**
 * Answers to custom fields, always attached to a Zapis (form model — the start section
 * lives on the Zapis, never on the Zawodnik). System fields have NO
 * answer here — their values live directly on competitors/registrations
 * (`storage_location` on the Pozycja says which of those two tables), and this
 * mechanism does not move those columns.
 */
final class FormFieldAnswerRepository extends Repository
{
    private const COLUMNS = 'id, form_field_id AS formFieldId, registration_id AS registrationId, '
        . 'value_text AS valueText, value_json AS valueJson, created_at AS createdAt, updated_at AS updatedAt';

    /**
     * Validates (FormFieldType::validateAnswer, from the type alone)
     * and stores one answer, upsert by (formFieldId, registrationId).
     *
     * @throws \InvalidArgumentException when the field does not exist, is not
     *         custom, or the value fails type validation.
     */
    public function saveAnswer(FormFieldRepository $fields, string $formFieldId, string $registrationId, mixed $value): array
    {
        $field = $fields->find($formFieldId);
        if ($field === null || $field['kind'] !== 'custom') {
            throw new \InvalidArgumentException('To nie jest pole własne.');
        }

        FormFieldType::validateAnswer(
            (string) $field['fieldType'],
            $value,
            (array) ($field['validationConfig'] ?? []),
            (array) ($field['options'] ?? []),
            $field['state'] === FormFieldCatalog::STATE_REQUIRED
        );

        $isMulti = $field['fieldType'] === FormFieldType::MULTI_CHOICE;
        $existing = $this->findByFieldAndRegistration($formFieldId, $registrationId);

        $data = [
            'form_field_id' => $formFieldId,
            'registration_id' => $registrationId,
            'value_text' => $isMulti ? null : ($value === null ? null : (string) $value),
            'value_json' => $isMulti ? $this->encodeJson(is_array($value) ? array_values($value) : [$value]) : null,
        ];

        if ($existing !== null) {
            $this->updateById('form_field_answers', $existing['id'], $data);
            return $this->find($existing['id']) ?? [];
        }

        $id = $this->id();
        $data['id'] = $id;
        $this->insert('form_field_answers', $data);
        return $this->find($id) ?? [];
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM form_field_answers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $this->decodeRow($stmt->fetch());
    }

    public function findByFieldAndRegistration(string $formFieldId, string $registrationId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM form_field_answers WHERE form_field_id = :formFieldId AND registration_id = :registrationId'
        );
        $stmt->execute(['formFieldId' => $formFieldId, 'registrationId' => $registrationId]);
        return $this->decodeRow($stmt->fetch());
    }

    /**
     * @param bool $includeConfidential false drops answers to `is_confidential` fields
     * @return array<int, array<string, mixed>>
     */
    public function forRegistration(string $registrationId, bool $includeConfidential = true): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM form_field_answers WHERE registration_id = :registrationId';
        if (!$includeConfidential) {
            $sql .= ' AND form_field_id NOT IN (SELECT id FROM form_fields WHERE is_confidential = 1)';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['registrationId' => $registrationId]);
        $rows = $stmt->fetchAll();
        return array_map(fn (array $row): array => $this->decodeRow($row) ?? [], $rows);
    }

    /**
     * Like forRegistration() plus the stable `fieldKey` of the answered field.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forRegistrationWithKeys(string $registrationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.form_field_id AS formFieldId, a.value_text AS valueText, a.value_json AS valueJson, f.field_key AS fieldKey '
            . 'FROM form_field_answers a INNER JOIN form_fields f ON f.id = a.form_field_id WHERE a.registration_id = :registrationId'
        );
        $stmt->execute(['registrationId' => $registrationId]);
        return array_map(fn (array $row): array => $this->decodeRow($row) ?? [], $stmt->fetchAll());
    }

    private function encodeJson(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decodeRow(array|false|null $row): ?array
    {
        return $this->decode($row, ['valueJson']);
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\FormFields;

use ZmierzymyCzas\Domain\Repository;

/**
 * Odpowiedzi na pola własne, zawsze przypięte do Zapisu (model F1 — sekcja
 * startu żyje na Zapisie, nigdy na Zawodniku). Pola systemowe NIE mają tu
 * odpowiedzi — ich wartości żyją na competitors/registrations wprost
 * (`storage_location` na Pozycji mówi, w której z tych dwóch tabel), ten
 * ticket nie przenosi tamtych kolumn do tego mechanizmu.
 */
final class FormFieldAnswerRepository extends Repository
{
    private const COLUMNS = 'id, form_field_id AS formFieldId, registration_id AS registrationId, '
        . 'value_text AS valueText, value_json AS valueJson, created_at AS createdAt, updated_at AS updatedAt';

    /**
     * Waliduje (FormFieldType::validateAnswer, wyłącznie z typu — model §4)
     * i zapisuje jedną odpowiedź, upsert po (formFieldId, registrationId).
     *
     * @throws \InvalidArgumentException gdy pole nie istnieje, nie jest
     *         własne, albo wartość nie przechodzi walidacji typu.
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

    /** @return array<int, array<string, mixed>> */
    public function forRegistration(string $registrationId): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM form_field_answers WHERE registration_id = :registrationId');
        $stmt->execute(['registrationId' => $registrationId]);
        $rows = $stmt->fetchAll();
        return array_map(fn (array $row): array => $this->decodeRow($row) ?? [], $rows);
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

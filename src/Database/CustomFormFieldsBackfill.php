<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;
use ZmierzymyCzas\Support\Uuid;

/**
 * PHP-side replacement for migration 041's original backfill of
 * `events.registration_form_config.customFields` into `form_fields`
 * (kind = custom) — see 041_e1_13_form_fields.sql's own comment for why this
 * moved out of plain SQL (E1.14 verification fix): the original single
 * INSERT ... JOIN JSON_TABLE(...) with a nested JSON_ARRAYAGG(JSON_OBJECT(...))
 * needs MariaDB 10.6 (JSON_TABLE) and 10.5 (JSON_ARRAYAGG); this environment
 * runs 10.4.32, so that statement was a hard syntax error that aborted the
 * entire migration run before 042 (E1.14's own migration) ever executed.
 *
 * Same semantics as the original SQL, just computed in PHP:
 *  - one row per legacy customFields[] entry, in array order
 *    (sort_order = 100 + index, so custom fields always land after the 13
 *    system positions)
 *  - type mapping: text -> short_text, select -> single_choice,
 *    checkbox -> consent
 *  - `required` from the JSON maps to state `required`, otherwise `shown`
 *    (being present in customFields at all meant "in the form" in the old
 *    model)
 *  - `select` options (a plain string[] in the legacy shape) become
 *    [{key, label, price: 0}] — price is seam #2 from migration 041, never
 *    read for billing
 *  - idempotent: skips a legacy field whose derived field_key
 *    (`custom_<legacy id>`) already exists on that event
 *
 * Called from Migrator::applyAll() after every .sql file has been applied, so
 * it always runs against a schema that already has `form_fields`.
 */
final class CustomFormFieldsBackfill
{
    public static function run(PDO $pdo): void
    {
        $stmt = $pdo->query(
            "SELECT id, registration_form_config FROM events
             WHERE registration_form_config IS NOT NULL"
        );
        $events = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        foreach ($events as $event) {
            $config = json_decode((string) $event['registration_form_config'], true);
            $customFields = is_array($config) ? ($config['customFields'] ?? null) : null;
            if (!is_array($customFields) || $customFields === []) {
                continue;
            }

            self::backfillEvent($pdo, (string) $event['id'], $customFields);
        }
    }

    /**
     * @param array<int, mixed> $customFields
     */
    private static function backfillEvent(PDO $pdo, string $eventId, array $customFields): void
    {
        $existingStmt = $pdo->prepare('SELECT field_key FROM form_fields WHERE event_id = :eventId');
        $existingStmt->execute(['eventId' => $eventId]);
        $existingKeys = $existingStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $insert = $pdo->prepare(
            "INSERT INTO form_fields
                (id, event_id, kind, field_key, storage_location, state, sort_order,
                 label_override, public_list_visible, field_type, validation_config, options, is_confidential)
             VALUES
                (:id, :eventId, 'custom', :fieldKey, 'registration', :state, :sortOrder,
                 :label, 0, :fieldType, NULL, :options, 0)"
        );

        foreach (array_values($customFields) as $index => $field) {
            if (!is_array($field) || !isset($field['id'])) {
                continue;
            }

            $legacyId = (string) $field['id'];
            $fieldKey = 'custom_' . $legacyId;
            if (in_array($fieldKey, $existingKeys, true)) {
                continue;
            }

            $type = (string) ($field['type'] ?? 'text');
            $fieldType = match ($type) {
                'select' => 'single_choice',
                'checkbox' => 'consent',
                default => 'short_text',
            };

            $options = null;
            if ($type === 'select' && isset($field['options']) && is_array($field['options'])) {
                $options = json_encode(
                    array_map(
                        static fn ($option): array => ['key' => (string) $option, 'label' => (string) $option, 'price' => 0],
                        $field['options']
                    ),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }

            $insert->execute([
                'id' => Uuid::v4(),
                'eventId' => $eventId,
                'fieldKey' => $fieldKey,
                'state' => !empty($field['required']) ? 'required' : 'shown',
                'sortOrder' => 100 + $index,
                'label' => $field['label'] ?? null,
                'fieldType' => $fieldType,
                'options' => $options,
            ]);

            $existingKeys[] = $fieldKey;
        }
    }
}

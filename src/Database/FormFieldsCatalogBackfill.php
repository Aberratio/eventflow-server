<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;

/**
 * Backfill for migration 041: every event gets all 13 system form-field
 * positions from the catalogue, with the same default states 041's own
 * comment documents.
 *
 * Runs on every `Migrator::applyAll()` call, not only when 041 itself
 * (re-)applies through the ledger — same reasoning as
 * {@see CompetitorProfileBackfill} and {@see ChallengeConditionTreeBackfill}:
 * seeders/fixtures insert `events` rows directly by SQL, after migrations (and the
 * ledger) have already run. `FormFieldRepository::ensureSystemCatalog()` is the
 * app-level equivalent for an event created through the API at runtime (which
 * patches events created later, e.g. in tests) — this is the same
 * idempotent catch-up, kept here so a raw migrate (no app code involved, e.g.
 * `tests/bin/test-db.php migrate`) also has every event fully catalogued.
 * Idempotent: `WHERE NOT EXISTS (... f.event_id = e.id AND f.field_key = k.field_key)`.
 *
 * The `phone`/`club` legacy-JSON `state = 'shown'` flip and the custom-fields JSON
 * migration that follow this INSERT in 041.sql are deliberately NOT part of this
 * class and stay ledger-gated (run once): re-running that flip against
 * live data on a staging bootstrap would silently change organizer-configured
 * visibility, which is out of scope here (report only, do not change).
 */
final class FormFieldsCatalogBackfill
{
    public static function run(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO form_fields (id, event_id, kind, field_key, storage_location, state, sort_order, public_list_visible, is_confidential)
             SELECT UUID(), e.id, 'system', k.field_key, k.storage_location, k.default_state, k.sort_order, 0, 0
             FROM events e
             CROSS JOIN (
               SELECT 'first_name' AS field_key, 'competitor' AS storage_location, 'required' AS default_state, 1 AS sort_order
               UNION ALL SELECT 'last_name', 'competitor', 'required', 2
               UNION ALL SELECT 'contact_email', 'registration', 'required', 3
               UNION ALL SELECT 'birth_date', 'competitor', 'required', 4
               UNION ALL SELECT 'gender', 'competitor', 'required', 5
               UNION ALL SELECT 'anonymization', 'competitor', 'hidden', 6
               UNION ALL SELECT 'country', 'competitor', 'hidden', 7
               UNION ALL SELECT 'city', 'competitor', 'hidden', 8
               UNION ALL SELECT 'postal_code', 'competitor', 'hidden', 9
               UNION ALL SELECT 'voivodeship', 'competitor', 'hidden', 10
               UNION ALL SELECT 'address', 'competitor', 'hidden', 11
               UNION ALL SELECT 'phone', 'competitor', 'hidden', 12
               UNION ALL SELECT 'club', 'registration', 'hidden', 13
             ) k
             WHERE NOT EXISTS (SELECT 1 FROM form_fields f WHERE f.event_id = e.id AND f.field_key = k.field_key)"
        );
    }
}

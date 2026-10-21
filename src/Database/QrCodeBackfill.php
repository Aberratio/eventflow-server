<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;
use ZmierzymyCzas\Support\QrCode;

/**
 * PHP-side replacement for the `RANDOM_BYTES(24)` backfills that migrations 039
 * (`registrations.qr_code`) and 040 (`competitors.identity_code`)
 * originally did in plain SQL.
 *
 * `RANDOM_BYTES()` is a MySQL 8.0 function; MariaDB only got it in 11.3. Both
 * environments this code runs on are older — development is 10.4.32 (XAMPP),
 * production is 10.6.27 — so the original statement was a hard error (1305
 * FUNCTION ... does not exist) that aborted the whole migration run at 039 and
 * left `qr_code` behind as a nullable, un-indexed column. Same class of problem
 * (and same fix) as migration 041's JSON_TABLE backfill — see
 * {@see CustomFormFieldsBackfill}.
 *
 * Deliberately not swapped for a portable SQL expression like
 * `SHA2(CONCAT(UUID(), RAND()), 256)`: these are identity tokens whose only
 * protection is unguessability, and neither MariaDB's `UUID()` (v1 — time plus
 * MAC address) nor `RAND()` is a cryptographically secure source. PHP's
 * `random_bytes(24)` is exactly what the runtime generators already use
 * (`RegistrationRepository::generateStartCode()`,
 * `CompetitorRepository::generateIdentityCode()`), so a backfilled row is
 * indistinguishable from one created after the migration — which is the whole
 * point of the backfill.
 *
 * Invoked from inside the migration scripts themselves, via the `-- @php:`
 * marker {@see Migrator::applyFile()} understands, so it runs at the one point
 * in the sequence where it is correct: after the column exists and before it is
 * made NOT NULL UNIQUE. Migrations carry no ledger and are re-applied on every
 * run, so this is also self-healing — a row that somehow ends up without a code
 * gets one on the next `composer migrate`.
 */
final class QrCodeBackfill
{
    /** Start code (`pqr_`) — belongs to the Zapis. */
    public static function registrations(PDO $pdo): void
    {
        self::fill($pdo, 'registrations', 'qr_code', QrCode::startCode(...));
    }

    /** Identity code (`zqr_`) — belongs to the Zawodnik. */
    public static function competitors(PDO $pdo): void
    {
        self::fill($pdo, 'competitors', 'identity_code', QrCode::identityCode(...));
    }

    /**
     * One UPDATE per row rather than one set-based statement, because every row
     * needs its own token — the whole reason this cannot stay in SQL here.
     *
     * "Without a code" means NULL *or* the empty string, not just NULL: this MariaDB
     * runs without STRICT_TRANS_TABLES, so an INSERT that omits the column once it is
     * already NOT NULL is not an error — it silently stores `''`. Such a row is as
     * codeless as a NULL one and has to be healed the same way, or it sits there
     * forever; the unique index tolerates exactly one of them, and the second is
     * error 1062 at INSERT time, which is how this was found.
     *
     * No collision handling on purpose: the unique index added right after this
     * step would reject a duplicate, but 24 random bytes is 192 bits of entropy,
     * so a collision is not a case worth writing code for.
     *
     * @param callable(): string $generate one of {@see QrCode}'s two generators
     */
    private static function fill(PDO $pdo, string $table, string $column, callable $generate): void
    {
        $stmt = $pdo->query("SELECT id FROM {$table} WHERE {$column} IS NULL OR {$column} = ''");
        $ids = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if ($ids === []) {
            return;
        }

        $update = $pdo->prepare(
            "UPDATE {$table} SET {$column} = :code
             WHERE id = :id AND ({$column} IS NULL OR {$column} = '')"
        );

        foreach ($ids as $id) {
            $update->execute([
                'code' => $generate(),
                'id' => (string) $id,
            ]);
        }
    }
}

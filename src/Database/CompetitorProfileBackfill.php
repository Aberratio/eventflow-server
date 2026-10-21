<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;
use ZmierzymyCzas\Support\QrCode;

/**
 * PHP-side replacement for migration 027's original `INSERT ... SELECT` that gives
 * every Konto its own profil Zawodnika (siebie samego), so existing registrations
 * and authorizations have something to attach to.
 *
 * The SQL version was correct exactly once — on the run that first applied 027,
 * when `competitors.identity_code` did not exist yet. Migrations carry no ledger
 * and are re-applied on every `composer migrate`, so from the run after 040
 * onwards that same statement inserts into a table where `identity_code`
 * is NOT NULL, has no DEFAULT and is UNIQUE. This MariaDB runs without
 * STRICT_TRANS_TABLES, so the omitted column is not an error — every inserted row
 * silently gets `''`, and the second one is error 1062 on
 * `uniq_competitors_identity_code`, taking the whole migration run down at 027,
 * before 040's backfill ({@see QrCodeBackfill::competitors()}) ever gets a chance
 * to heal anything.
 *
 * That is not a hypothetical: `bin/seed.php` migrates first and seeds users after,
 * so every seeded Konto is waiting for the *next* migrate run to get its profile —
 * fifteen of them at once on this development database.
 *
 * The fix cannot be "name the column in 027", because on a fresh database 027 runs
 * thirteen files before the column exists. So the insert moves to PHP, where it can
 * ask. The row-shaping SQL (the name split, the columns copied from `users`, the
 * `NOT EXISTS` guard that makes it idempotent) is carried over verbatim; the only
 * addition is the identity code, generated the same way
 * `CompetitorRepository::generateIdentityCode()` does it.
 *
 * Invoked from inside 027 itself via the `-- @php:` marker
 * {@see Migrator::applyFile()} understands, at the exact point the INSERT used to
 * sit: after `competitors` and `care_relations` exist, before the `care_relations`
 * backfill that joins against the profiles created here.
 */
final class CompetitorProfileBackfill
{
    public static function run(PDO $pdo): void
    {
        $stmt = $pdo->query(
            'SELECT u.id FROM users u
             WHERE NOT EXISTS (SELECT 1 FROM competitors c WHERE c.user_id = u.id)'
        );
        $userIds = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if ($userIds === []) {
            return;
        }

        $withCode = self::hasIdentityCode($pdo);

        // One INSERT per Konto rather than the original set-based one, because each
        // row needs its own token — the same reason QrCodeBackfill cannot stay in SQL.
        // The SELECT is otherwise unchanged, `NOT EXISTS` guard included: it still
        // decides per row, so a concurrent insert cannot produce a second profile.
        $insert = $pdo->prepare(
            'INSERT INTO competitors
                (id, first_name, last_name, birth_date, gender, email, phone, club, user_id, created_at'
            . ($withCode ? ', identity_code' : '') . ')
             SELECT
                UUID(),
                TRIM(SUBSTRING_INDEX(u.name, \' \', 1)),
                TRIM(CASE WHEN LOCATE(\' \', u.name) > 0 THEN SUBSTRING(u.name, LOCATE(\' \', u.name) + 1) ELSE \'\' END),
                u.birth_date,
                u.gender,
                u.email,
                u.phone,
                u.club,
                u.id,
                u.created_at'
            . ($withCode ? ', :code' : '') . '
             FROM users u
             WHERE u.id = :userId
               AND NOT EXISTS (SELECT 1 FROM competitors c WHERE c.user_id = u.id)'
        );

        foreach ($userIds as $userId) {
            $params = ['userId' => (string) $userId];
            if ($withCode) {
                $params['code'] = QrCode::identityCode();
            }

            $insert->execute($params);
        }
    }

    /**
     * 027 runs both before and after 040 depending on how far the database
     * has got, and a migration file is re-applied whole every run — so whether the
     * column is there is a question about this run, not about the schema version.
     */
    private static function hasIdentityCode(PDO $pdo): bool
    {
        $stmt = $pdo->query("SHOW COLUMNS FROM competitors LIKE 'identity_code'");

        return $stmt !== false && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }
}

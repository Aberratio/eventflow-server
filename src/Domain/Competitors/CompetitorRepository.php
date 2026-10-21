<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Competitors;

use ZmierzymyCzas\Domain\Repository;
use ZmierzymyCzas\Support\QrCode;

/**
 * Zawodnik: a profile of starting data, a standalone entity — not owned by a Konto.
 * It can exist without any linked Konto (e.g. a child registered by a parent).
 */
final class CompetitorRepository extends Repository
{
    private const COLUMNS = <<<'SQL'
id, first_name AS firstName, last_name AS lastName, birth_date AS birthDate, gender,
email, phone, club, country, city, postal_code AS postalCode, voivodeship, address, identity_code AS identityCode, status, merged_into_competitor_id AS mergedIntoCompetitorId,
is_anonymized AS isAnonymized, user_id AS userId, created_at AS createdAt, updated_at AS updatedAt
SQL;

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM competitors WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByUserId(string $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM competitors WHERE user_id = :userId');
        $stmt->execute(['userId' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Returns the existing Zawodnik linked to the Konto, creating it on the fly
     * if the account was created before this model was introduced (e.g. in tests) and does not yet
     * have a Zawodnik profile.
     *
     * @param array<string, mixed> $user
     */
    public function ensureForUser(array $user): array
    {
        $existing = $this->findByUserId((string) $user['id']);
        if ($existing !== null) {
            return $existing;
        }

        $name = trim((string) ($user['name'] ?? ''));
        $spacePos = strrpos($name, ' ');
        $firstName = $spacePos === false ? $name : substr($name, 0, $spacePos);
        $lastName = $spacePos === false ? '' : substr($name, $spacePos + 1);

        return $this->create([
            'firstName' => $firstName !== '' ? $firstName : $name,
            'lastName' => $lastName,
            'birthDate' => $user['birthDate'] ?? null,
            'gender' => $user['gender'] ?? null,
            'email' => $user['email'] ?? null,
            'phone' => $user['phone'] ?? null,
            'club' => $user['club'] ?? null,
            'userId' => $user['id'],
        ], skipDuplicateCheck: true);
    }

    /**
     * Zawodnicy matching by first name, last name and date of birth — duplicate
     * defense is a warning, not a block.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findDuplicates(string $firstName, string $lastName, ?string $birthDate, ?string $excludeId = null): array
    {
        $sql = 'SELECT ' . self::COLUMNS . '
            FROM competitors
            WHERE LOWER(first_name) = LOWER(:firstName)
              AND LOWER(last_name) = LOWER(:lastName)
              AND birth_date <=> :birthDate';
        $params = ['firstName' => $firstName, 'lastName' => $lastName, 'birthDate' => $birthDate];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :excludeId';
            $params['excludeId'] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed> Zawodnik with a list of potential duplicates under the "duplicateWarnings" key.
     */
    public function create(array $data, bool $skipDuplicateCheck = false): array
    {
        $firstName = trim((string) ($data['firstName'] ?? ''));
        $lastName = trim((string) ($data['lastName'] ?? ''));
        $birthDate = $data['birthDate'] ?? null;

        $duplicates = $skipDuplicateCheck ? [] : $this->findDuplicates($firstName, $lastName, $birthDate);

        $id = $this->id();
        $this->insert('competitors', [
            'id' => $id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'gender' => $data['gender'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'club' => $data['club'] ?? null,
            // Identity code — always generated here, when the Zawodnik is created
            // (never by BZ, see generateIdentityCode()'s docblock), so there is never
            // a Zawodnik without a code and no "not generated yet" state.
            'identity_code' => $this->generateIdentityCode(),
            'user_id' => $data['userId'] ?? null,
        ]);

        $created = $this->find($id) ?? [];
        $created['duplicateWarnings'] = $duplicates;
        return $created;
    }

    /**
     * A Moderator/Admin looks for two profiles to compare before merging.
     * Deliberately simple (first name/last name/identity code, LIKE) — this is not a public
     * participant search, only a support tool for finding a suspected
     * duplicate reported by a caregiver/organizer (there is no formal
     * "report" mechanism; the moderator just searches). It does not
     * return already-merged profiles — those are a dead archive, not candidates for
     * another merge.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchForMerge(string $q, int $limit = 20): array
    {
        $needle = trim($q);
        if ($needle === '') {
            return [];
        }

        // Native prepares (ATTR_EMULATE_PREPARES=false, see Connection) reject a repeated
        // named placeholder, so each LIKE gets its own name.
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM competitors
             WHERE status = "active"
               AND (
                 first_name LIKE :needleFirst
                 OR last_name LIKE :needleLast
                 OR CONCAT(first_name, " ", last_name) LIKE :needleFull
                 OR identity_code = :exact
               )
             ORDER BY last_name, first_name
             LIMIT ' . max(1, min(100, $limit))
        );
        $like = '%' . $needle . '%';
        $stmt->execute(['needleFirst' => $like, 'needleLast' => $like, 'needleFull' => $like, 'exact' => $needle]);
        $results = $stmt->fetchAll();

        // This search box is the one real, existing place in
        // this codebase where a moderator pastes a raw identity code
        // (`zqr_...`, see the UI's own placeholder text) to find a Zawodnik —
        // there is no separate BZ-facing "resolve a scanned code" HTTP route
        // in this repo (BZ's own scanner/resolution logic lives in the
        // separate biuro_zawodow repo; what this repo serves outbound,
        // bzParticipantList(), already reflects the post-merge survivor
        // correctly because it joins live via registrations.competitor_id,
        // which repointCompetitor() already updates — see
        // RegistrationRepository::bzParticipantList()). The plain query above
        // only matches a still-active competitor's OWN current code, so
        // pasting the OLD code of an already-merged (status = "scalony")
        // profile — exactly the scan-an-old-printed-card scenario
        // resolveIdentityCode() exists for — silently returned nothing.
        // Fall back to the alias-chain-aware lookup so that case resolves to
        // the current survivor instead.
        if (str_starts_with($needle, QrCode::IDENTITY_PREFIX)) {
            $resolved = $this->resolveIdentityCode($needle);
            if ($resolved !== null && !in_array($resolved['id'], array_column($results, 'id'), true)) {
                array_unshift($results, $resolved);
            }
        }

        return $results;
    }

    /**
     * Backward lookup — returns the Zawodnik that
     * an identity code (`zqr_`) actually resolves to today, whether
     * that exact code is their OWN active code or an old alias
     * left by a merge (possibly a chain of merges — profile A merged into B,
     * B later merged into C: A's code must still resolve to C). An alias is never
     * promoted in the UI (backward lookup only) — this is the only
     * place that reads it.
     */
    public function resolveIdentityCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM competitors WHERE identity_code = :code');
        $stmt->execute(['code' => $code]);
        $competitor = $stmt->fetch() ?: null;

        if ($competitor === null) {
            $aliasStmt = $this->pdo->prepare('SELECT competitor_id FROM identity_code_aliases WHERE old_identity_code = :code');
            $aliasStmt->execute(['code' => $code]);
            $competitorId = $aliasStmt->fetchColumn();
            if ($competitorId === false) {
                return null;
            }
            $competitor = $this->find((string) $competitorId);
            if ($competitor === null) {
                return null;
            }
        }

        return $this->resolveToActive($competitor);
    }

    /** @param array<string, mixed> $competitor */
    private function resolveToActive(array $competitor, int $depth = 0): ?array
    {
        if ($depth > 20) {
            // Cycle guard — should be structurally impossible (a chain only ever
            // grows by pointing to a *newer* survivor), but a live query loop
            // must never spin forever on corrupted data.
            return null;
        }
        if (($competitor['status'] ?? 'active') !== 'scalony' || empty($competitor['mergedIntoCompetitorId'])) {
            return $competitor;
        }

        $next = $this->find((string) $competitor['mergedIntoCompetitorId']);
        return $next === null ? $competitor : $this->resolveToActive($next, $depth + 1);
    }

    /**
     * Switches the absorbed profile to the archival `scalony` (merged) state with a pointer
     * to the survivor — it is never deleted.
     *
     * @return array<string, mixed>|null
     */
    public function markMerged(string $absorbedId, string $survivorId): ?array
    {
        if ($this->find($absorbedId) === null) {
            return null;
        }

        $this->updateById('competitors', $absorbedId, [
            'status' => 'scalony',
            'merged_into_competitor_id' => $survivorId,
        ]);
        return $this->find($absorbedId);
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): ?array
    {
        $mapped = [];
        foreach ([
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'birthDate' => 'birth_date',
            'gender' => 'gender',
            'email' => 'email',
            'phone' => 'phone',
            'club' => 'club',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $mapped[$column] = $data[$input];
            }
        }
        $this->updateById('competitors', $id, $mapped);
        return $this->find($id);
    }

    /**
     * Self-service anonymization toggle — a Zawodnik (or
     * whoever has Opieka over them, same authorization circle routes.php
     * already uses for rotateIdentityCode()) can flip this on their own
     * profile. Mirrors regenerateIdentityCode()'s shape deliberately: a
     * single-purpose, narrowly-named method rather than routing this through
     * the generic update() above, so the one field this action actually needs
     * writable from a public-ish self-service action isn't bundled with
     * name/birth-date/contact-detail edits that need their own scrutiny.
     */
    public function setAnonymization(string $id, bool $isAnonymized): ?array
    {
        if ($this->find($id) === null) {
            return null;
        }

        $this->updateById('competitors', $id, ['is_anonymized' => $isAnonymized ? 1 : 0]);
        return $this->find($id);
    }

    /**
     * Identity code rotation on demand — the Zawodnik themselves, or the
     * Konto holding Opieka over them, may invalidate the old code at any time
     * and get a new one. Deliberately no history of old codes (no code
     * has a status) — we simply overwrite `identity_code`, and the old token
     * ceases to exist anywhere in the database; nothing else needs doing.
     *
     * Authorization (who may call this) is the caller's concern (routes.php, via
     * `CareRepository::canRegister()` — the same Opieka relation that allows
     * registering a Zawodnik for events also allows rotating their code), not this
     * repository's — mirroring how `RegistrationRepository` knows nothing about
     * the caller's permissions.
     */
    public function regenerateIdentityCode(string $id): ?array
    {
        if ($this->find($id) === null) {
            return null;
        }

        $this->updateById('competitors', $id, ['identity_code' => $this->generateIdentityCode()]);
        return $this->find($id);
    }

    /**
     * Stores the absorbed profile's old identity code as an alias
     * pointing at the survivor — see `resolveIdentityCode()` and migration
     * 059 for the full rationale (not an immediate
     * invalidation, because support initiates merges without the Zawodnik's knowledge).
     */
    public function addIdentityCodeAlias(string $oldIdentityCode, string $survivorId): void
    {
        $this->insert('identity_code_aliases', [
            'id' => $this->id(),
            'old_identity_code' => $oldIdentityCode,
            'competitor_id' => $survivorId,
        ]);
    }

    /**
     * Same construction as `generateStartCode()`
     * (`random_bytes(24)`, 192 bits of entropy, 48 hex chars) —
     * differing only in the prefix (`zqr_` instead of `pqr_`):
     * both codes have the same syntactic structure and differ only by
     * prefix, so that Biuro Zawodów can tell an identity code from a
     * start code by format alone (regex), without changing length/alphabet.
     *
     * This code belongs to the Zawodnik (a standalone entity that can exist without a Konto — see
     * `ensureForUser()`), not to a Zapis or Konto — a child added by a parent
     * without their own Konto still gets their own identity code, because it is created together
     * with the competitors row, not with authentication.
     */
    private function generateIdentityCode(): string
    {
        return QrCode::identityCode();
    }
}

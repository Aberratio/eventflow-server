<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Care;

use ZmierzymyCzas\Domain\Repository;

/**
 * Opieka: an N:M relation between a Konto and a Zawodnik, generalising the former
 * Authorizations (Konto -> Konto). A Konto with an Opieka relation to a Zawodnik
 * can register them for events.
 */
final class CareRepository extends Repository
{
    /** Zawodnicy cared for by the given Konto (who may register them). */
    public function listMyCompetitors(string $kontoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cr.id, cr.zawodnik_id AS competitorId, cr.created_at AS createdAt,
                    c.first_name AS firstName, c.last_name AS lastName, c.birth_date AS birthDate,
                    c.gender, c.email, c.phone, c.club, c.identity_code AS identityCode,
                    c.user_id AS competitorUserId, c.is_anonymized AS isAnonymized
             FROM care_relations cr
             JOIN competitors c ON c.id = cr.zawodnik_id
             WHERE cr.konto_id = :kontoId
             ORDER BY c.last_name, c.first_name'
        );
        $stmt->execute(['kontoId' => $kontoId]);
        return array_map(static function (array $row): array {
            $row['isAnonymized'] = (bool) $row['isAnonymized'];

            return $row;
        }, $stmt->fetchAll());
    }

    /** Konta that care for the given Zawodnik (and may register them). */
    public function listCaregivers(string $zawodnikId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cr.id, cr.konto_id AS kontoId, cr.created_at AS createdAt,
                    u.name AS kontoName, u.email AS kontoEmail, u.avatar AS kontoAvatar
             FROM care_relations cr
             JOIN users u ON u.id = cr.konto_id
             WHERE cr.zawodnik_id = :zawodnikId
             ORDER BY u.name'
        );
        $stmt->execute(['zawodnikId' => $zawodnikId]);
        return $stmt->fetchAll();
    }

    public function exists(string $kontoId, string $zawodnikId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM care_relations WHERE konto_id = :kontoId AND zawodnik_id = :zawodnikId'
        );
        $stmt->execute(['kontoId' => $kontoId, 'zawodnikId' => $zawodnikId]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    public function grant(string $kontoId, string $zawodnikId): array
    {
        $id = $this->id();
        $this->insert('care_relations', [
            'id' => $id,
            'konto_id' => $kontoId,
            'zawodnik_id' => $zawodnikId,
        ]);

        $stmt = $this->pdo->prepare(
            'SELECT cr.id, cr.konto_id AS kontoId, cr.zawodnik_id AS competitorId, cr.created_at AS createdAt,
                    c.first_name AS firstName, c.last_name AS lastName, c.birth_date AS birthDate
             FROM care_relations cr
             JOIN competitors c ON c.id = cr.zawodnik_id
             WHERE cr.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return (array) $stmt->fetch();
    }

    /**
     * Revokes an Opieka. Either the caregiver (konto_id) can do it, or the Zawodnik the
     * Opieka concerns, if they have their own Konto (zawodnik.user_id = kontoId) —
     * i.e. the person being cared for can also revoke it.
     */
    public function revoke(string $id, string $kontoId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE cr FROM care_relations cr
             LEFT JOIN competitors c ON c.id = cr.zawodnik_id
             WHERE cr.id = :id AND (cr.konto_id = :kontoId1 OR c.user_id = :kontoId2)'
        );
        $stmt->execute(['id' => $id, 'kontoId1' => $kontoId, 'kontoId2' => $kontoId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Competitor merge: moves every Opieka over the absorbed profile onto
     * the survivor. `care_relations` has a UNIQUE (konto_id, zawodnik_id) pair —
     * a caregiver who already cares for the survivor (common case: the same
     * confused parent created both duplicate profiles and cares for both) would
     * collide on a straight repoint, so an existing row for that pair is simply
     * dropped instead of duplicated; every other row is repointed.
     */
    public function repointForMerge(string $absorbedId, string $survivorId): void
    {
        $this->pdo->prepare(
            'DELETE FROM care_relations
             WHERE zawodnik_id = :absorbedId
               AND konto_id IN (SELECT konto_id FROM (SELECT konto_id FROM care_relations WHERE zawodnik_id = :survivorId) AS existing)'
        )->execute(['absorbedId' => $absorbedId, 'survivorId' => $survivorId]);

        $this->pdo->prepare(
            'UPDATE care_relations SET zawodnik_id = :survivorId WHERE zawodnik_id = :absorbedId'
        )->execute(['survivorId' => $survivorId, 'absorbedId' => $absorbedId]);
    }

    /** Whether the given Konto may register the given Zawodnik (via Opieka, or it is their own profile). */
    public function canRegister(string $kontoId, string $zawodnikId): bool
    {
        if ($this->exists($kontoId, $zawodnikId)) {
            return true;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM competitors WHERE id = :zawodnikId AND user_id = :kontoId');
        $stmt->execute(['zawodnikId' => $zawodnikId, 'kontoId' => $kontoId]);
        return (bool) $stmt->fetchColumn();
    }
}

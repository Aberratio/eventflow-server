<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Care;

use ZmierzymyCzas\Domain\Repository;

/**
 * Opieka: relacja N:M między Kontem a Zawodnikiem, uogólniająca dawne
 * Authorizations (Konto -> Konto). Konto z relacją Opieki nad Zawodnikiem
 * może go zapisywać na zawody.
 */
final class CareRepository extends Repository
{
    /** Zawodnicy, nad którymi opiekę sprawuje dane Konto (może ich zapisywać). */
    public function listMyCompetitors(string $kontoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cr.id, cr.zawodnik_id AS competitorId, cr.created_at AS createdAt,
                    c.first_name AS firstName, c.last_name AS lastName, c.birth_date AS birthDate,
                    c.gender, c.email, c.phone, c.club, c.user_id AS competitorUserId
             FROM care_relations cr
             JOIN competitors c ON c.id = cr.zawodnik_id
             WHERE cr.konto_id = :kontoId
             ORDER BY c.last_name, c.first_name'
        );
        $stmt->execute(['kontoId' => $kontoId]);
        return $stmt->fetchAll();
    }

    /** Konta, które sprawują opiekę nad danym Zawodnikiem (mogą go zapisywać). */
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
     * Cofa Opiekę. Może to zrobić opiekun (konto_id), albo Zawodnik, którego
     * dotyczy Opieka, jeśli ma własne Konto (zawodnik.user_id = kontoId) —
     * czyli osoba, nad którą sprawowana jest opieka, też może ją cofnąć.
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

    /** Czy dane Konto może zapisywać danego Zawodnika (opieka albo to jego własny profil). */
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

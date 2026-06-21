<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Authorizations;

use ZmierzymyCzas\Domain\Repository;

final class AuthorizationRepository extends Repository
{
    /** @return array<int, array<string, mixed>> Osoby, które upoważniły danego użytkownika (grantor → grantee=userId) */
    public function listGrantedToMe(string $granteeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pa.id, pa.grantor_id AS grantorId, pa.created_at AS createdAt,
                    u.name AS grantorName, u.email AS grantorEmail, u.avatar AS grantorAvatar
             FROM participant_authorizations pa
             JOIN users u ON u.id = pa.grantor_id
             WHERE pa.grantee_id = :granteeId
             ORDER BY u.name'
        );
        $stmt->execute(['granteeId' => $granteeId]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> Osoby, którym dany użytkownik udzielił upoważnienia */
    public function listGrantedByMe(string $grantorId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pa.id, pa.grantee_id AS granteeId, pa.created_at AS createdAt,
                    u.name AS granteeName, u.email AS granteeEmail, u.avatar AS granteeAvatar
             FROM participant_authorizations pa
             JOIN users u ON u.id = pa.grantee_id
             WHERE pa.grantor_id = :grantorId
             ORDER BY u.name'
        );
        $stmt->execute(['grantorId' => $grantorId]);
        return $stmt->fetchAll();
    }

    public function exists(string $grantorId, string $granteeId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM participant_authorizations WHERE grantor_id = :grantorId AND grantee_id = :granteeId'
        );
        $stmt->execute(['grantorId' => $grantorId, 'granteeId' => $granteeId]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    public function grant(string $grantorId, string $granteeId): array
    {
        $id = $this->id();
        $this->insert('participant_authorizations', [
            'id'         => $id,
            'grantor_id' => $grantorId,
            'grantee_id' => $granteeId,
        ]);

        $stmt = $this->pdo->prepare(
            'SELECT pa.id, pa.grantor_id AS grantorId, pa.grantee_id AS granteeId, pa.created_at AS createdAt,
                    u.name AS granteeName, u.email AS granteeEmail, u.avatar AS granteeAvatar
             FROM participant_authorizations pa
             JOIN users u ON u.id = pa.grantee_id
             WHERE pa.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return (array) $stmt->fetch();
    }

    public function revoke(string $id, string $grantorId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM participant_authorizations WHERE id = :id AND grantor_id = :grantorId'
        );
        $stmt->execute(['id' => $id, 'grantorId' => $grantorId]);
        return $stmt->rowCount() > 0;
    }

    /** Sprawdza czy grantee może rejestrować grantorId na zawody */
    public function isAuthorized(string $grantorId, string $granteeId): bool
    {
        return $this->exists($grantorId, $granteeId);
    }
}

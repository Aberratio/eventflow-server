<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Events;

use ZmierzymyCzas\Domain\Repository;

/**
 * The Co-managers of an Event. The Owner is NOT stored here - it stays
 * `events.organizer_id`. A member can be any Account, whatever its global role; only `active`
 * rows grant anything (an `invited` or `revoked` row gives no access at all).
 */
final class EventTeamRepository extends Repository
{
    /** How long an invitation link stays valid. */
    public const INVITE_TTL_DAYS = 7;

    /** True only for an `active` Co-manager row of this Event. */
    public function isActiveCoManager(string $eventId, string $userId): bool
    {
        if ($eventId === '' || $userId === '') {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM event_team_members WHERE event_id = :eventId AND user_id = :userId AND status = 'active' LIMIT 1"
        );
        $stmt->execute(['eventId' => $eventId, 'userId' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    /** True when the Account is an active Co-manager of at least one Event. */
    public function hasActiveMembership(string $userId): bool
    {
        if ($userId === '') {
            return false;
        }
        $stmt = $this->pdo->prepare("SELECT 1 FROM event_team_members WHERE user_id = :userId AND status = 'active' LIMIT 1");
        $stmt->execute(['userId' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return list<string> ids of the Events the Account actively co-manages (for the organizer panel) */
    public function eventIdsForUser(string $userId): array
    {
        if ($userId === '') {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT event_id FROM event_team_members WHERE user_id = :userId AND status = 'active' ORDER BY created_at ASC");
        $stmt->execute(['userId' => $userId]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Every membership row of the Event, newest last (the Owner is added by the caller).
     *
     * @return list<array<string, mixed>>
     */
    public function listForEvent(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.event_id AS eventId, m.user_id AS userId, m.role, m.status, m.invited_email AS email,'
            . ' m.invite_expires_at AS inviteExpiresAt, m.invited_by AS invitedBy, m.created_at AS createdAt,'
            . ' m.accepted_at AS acceptedAt, m.revoked_at AS revokedAt, u.name AS name'
            . ' FROM event_team_members m LEFT JOIN users u ON u.id = m.user_id'
            . ' WHERE m.event_id = :eventId ORDER BY m.created_at ASC, m.id ASC'
        );
        $stmt->execute(['eventId' => $eventId]);

        return array_values($stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function find(string $memberId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, event_id AS eventId, user_id AS userId, role, status, invited_email AS email,'
            . ' invite_expires_at AS inviteExpiresAt, invited_by AS invitedBy, created_at AS createdAt,'
            . ' accepted_at AS acceptedAt, revoked_at AS revokedAt FROM event_team_members WHERE id = :id'
        );
        $stmt->execute(['id' => $memberId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Adds an already-active Co-manager (no invitation round-trip). Used by fixtures and by the
     * invitation flow once an invitee accepted; idempotent per (event, user): a revoked or invited row
     * is re-activated.
     *
     * @return array<string, mixed>
     */
    public function addActive(string $eventId, string $userId, string $email, ?string $invitedBy = null): array
    {
        $existing = $this->pdo->prepare('SELECT id FROM event_team_members WHERE event_id = :eventId AND user_id = :userId');
        $existing->execute(['eventId' => $eventId, 'userId' => $userId]);
        $id = $existing->fetchColumn();
        if ($id === false) {
            $id = $this->id();
            $this->pdo->prepare(
                "INSERT INTO event_team_members (id, event_id, user_id, role, status, invited_email, invited_by, accepted_at)"
                . " VALUES (:id, :eventId, :userId, 'co_manager', 'active', :email, :invitedBy, NOW())"
            )->execute(['id' => $id, 'eventId' => $eventId, 'userId' => $userId, 'email' => $email, 'invitedBy' => $invitedBy]);
        } else {
            $this->pdo->prepare(
                "UPDATE event_team_members SET status = 'active', invite_token_hash = NULL, invite_expires_at = NULL,"
                . ' revoked_at = NULL, accepted_at = NOW() WHERE id = :id'
            )->execute(['id' => $id]);
        }

        return $this->find((string) $id) ?? [];
    }

    /**
     * Issues (or re-issues) an invitation for an e-mail address. The raw token is returned once, for
     * the mail; only its SHA-256 is stored. A revoked or still-pending row of the same address is reused (a
     * resend), an active member of the same address is a conflict.
     *
     * @return array{member: array<string, mixed>, token: string}
     * @throws \RuntimeException `team_member_active` when the address already is an active Co-manager
     */
    public function invite(string $eventId, string $email, string $invitedBy): array
    {
        $email = strtolower(trim($email));
        $token = bin2hex(random_bytes(32));
        $hash = self::hashToken($token);
        $expires = (new \DateTimeImmutable('+' . self::INVITE_TTL_DAYS . ' days'))->format('Y-m-d H:i:s');

        $existing = $this->pdo->prepare('SELECT id, status FROM event_team_members WHERE event_id = :eventId AND invited_email = :email');
        $existing->execute(['eventId' => $eventId, 'email' => $email]);
        $row = $existing->fetch();
        if ($row !== false && $row['status'] === 'active') {
            throw new \RuntimeException('team_member_active');
        }
        if ($row === false) {
            $id = $this->id();
            $this->pdo->prepare(
                "INSERT INTO event_team_members (id, event_id, user_id, role, status, invited_email, invite_token_hash, invite_expires_at, invited_by)"
                . " VALUES (:id, :eventId, NULL, 'co_manager', 'invited', :email, :hash, :expires, :invitedBy)"
            )->execute(['id' => $id, 'eventId' => $eventId, 'email' => $email, 'hash' => $hash, 'expires' => $expires, 'invitedBy' => $invitedBy]);
        } else {
            $id = (string) $row['id'];
            $this->pdo->prepare(
                "UPDATE event_team_members SET status = 'invited', user_id = NULL, invite_token_hash = :hash, invite_expires_at = :expires,"
                . ' invited_by = :invitedBy, revoked_at = NULL, accepted_at = NULL WHERE id = :id'
            )->execute(['id' => $id, 'hash' => $hash, 'expires' => $expires, 'invitedBy' => $invitedBy]);
        }

        return ['member' => $this->find($id) ?? [], 'token' => $token];
    }

    /**
     * The invitation behind a raw token, with just enough context for the public preview. Null for an unknown
     * token or one whose invitation was revoked.
     *
     * @return array<string, mixed>|null
     */
    public function findInvitation(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT m.id AS memberId, m.event_id AS eventId, m.user_id AS userId, m.status, m.invited_email AS email,'
            . ' m.invite_expires_at AS inviteExpiresAt, e.name AS eventName, e.organizer_id AS organizerId,'
            . ' e.deleted_at AS eventDeletedAt, inviter.name AS inviterName'
            . ' FROM event_team_members m'
            . ' INNER JOIN events e ON e.id = m.event_id'
            . ' LEFT JOIN users inviter ON inviter.id = m.invited_by'
            . " WHERE m.invite_token_hash = :hash AND m.status IN ('invited', 'active')"
        );
        $stmt->execute(['hash' => self::hashToken($token)]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['expired'] = $row['status'] === 'invited'
            && $row['inviteExpiresAt'] !== null
            && strtotime((string) $row['inviteExpiresAt']) < time();

        return $row;
    }

    /**
     * Binds a pending invitation to the accepting Account and activates it. Idempotent for the same Account.
     *
     * @return array<string, mixed> the member row
     * @throws \RuntimeException `invitation_not_found`, `invitation_expired`, `invitation_email_mismatch`,
     *                           `team_member_owner` (the Owner needs no invitation), `team_member_active`
     */
    public function accept(string $token, string $userId, string $userEmail): array
    {
        $invitation = $this->findInvitation($token);
        if ($invitation === null || ($invitation['eventDeletedAt'] ?? null) !== null) {
            throw new \RuntimeException('invitation_not_found');
        }
        if (strtolower(trim($userEmail)) !== strtolower((string) $invitation['email'])) {
            throw new \RuntimeException('invitation_email_mismatch');
        }
        if ($invitation['status'] === 'active') {
            if ((string) $invitation['userId'] !== $userId) {
                throw new \RuntimeException('invitation_email_mismatch');
            }

            return $this->find((string) $invitation['memberId']) ?? [];
        }
        if ($invitation['expired']) {
            throw new \RuntimeException('invitation_expired');
        }
        if ((string) $invitation['organizerId'] === $userId) {
            throw new \RuntimeException('team_member_owner');
        }
        // A leftover row of this Account for the Event (an old invitation under another address) must not
        // collide with the unique (event, user) key: an active one wins, any other is dropped.
        $other = $this->pdo->prepare('SELECT id, status FROM event_team_members WHERE event_id = :eventId AND user_id = :userId AND id <> :id');
        $other->execute(['eventId' => $invitation['eventId'], 'userId' => $userId, 'id' => $invitation['memberId']]);
        foreach ($other->fetchAll() as $stale) {
            if ($stale['status'] === 'active') {
                throw new \RuntimeException('team_member_active');
            }
            $this->pdo->prepare('DELETE FROM event_team_members WHERE id = :id')->execute(['id' => $stale['id']]);
        }
        $this->pdo->prepare(
            "UPDATE event_team_members SET status = 'active', user_id = :userId, accepted_at = NOW(), invite_expires_at = NULL"
            . " WHERE id = :id AND status = 'invited'"
        )->execute(['id' => $invitation['memberId'], 'userId' => $userId]);

        return $this->find((string) $invitation['memberId']) ?? [];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Takes the access away (status `revoked`); the row stays for the audit trail. */
    public function revoke(string $memberId): void
    {
        $this->pdo->prepare(
            "UPDATE event_team_members SET status = 'revoked', invite_token_hash = NULL, invite_expires_at = NULL,"
            . " revoked_at = NOW() WHERE id = :id AND status <> 'revoked'"
        )->execute(['id' => $memberId]);
    }
}

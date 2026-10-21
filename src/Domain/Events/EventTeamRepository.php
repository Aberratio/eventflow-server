<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Events;

use ZmierzymyCzas\Domain\Repository;

/**
 * R-01 (spec 09 pkt 2-3): the Co-managers of an Event. The Owner is NOT stored here - it stays
 * `events.organizer_id`. A member can be any Account, whatever its global role; only `active`
 * rows grant anything (an `invited` or `revoked` row gives no access at all).
 */
final class EventTeamRepository extends Repository
{
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

    /** Takes the access away (status `revoked`); the row stays for the audit trail. */
    public function revoke(string $memberId): void
    {
        $this->pdo->prepare(
            "UPDATE event_team_members SET status = 'revoked', invite_token_hash = NULL, invite_expires_at = NULL,"
            . " revoked_at = NOW() WHERE id = :id AND status <> 'revoked'"
        )->execute(['id' => $memberId]);
    }
}

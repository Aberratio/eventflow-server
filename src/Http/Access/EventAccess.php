<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Access;

use ZmierzymyCzas\Domain\Events\EventTeamRepository;

/**
 * The event permission matrix: who may see, manage, publish results for and handle money on one event.
 *
 * The event's Team is its owning organizer account (`events.organizer_id`) plus its active
 * co-managers (`event_team_members`), whatever their global role. "Organizer outside the Team"
 * is any other organizer account. `$auth` is the verified session payload (`sub`, `role`).
 */
final class EventAccess
{
    public function __construct(private readonly EventTeamRepository $eventTeam)
    {
    }

    /**
     * @param array<string, mixed>|null $event
     * @param array<string, mixed> $auth
     */
    public function canView(?array $event, array $auth, ?string $accessCode = null): bool
    {
        if (!$event) {
            return false;
        }
        $role = (string) ($auth['role'] ?? '');
        $userId = (string) ($auth['sub'] ?? '');
        $isOwner = $role === 'organizer' && $userId !== '' && $event['organizerId'] === $userId;

        if (($event['deletedAt'] ?? null) !== null) {
            return $role === 'superadmin';
        }
        // An active co-manager (any global role) is on the Team, so sees what the owner sees.
        if (!$isOwner && $userId !== '' && $this->eventTeam->isActiveCoManager((string) ($event['id'] ?? ''), $userId)) {
            $isOwner = true;
        }

        $status = (string) ($event['publicationStatus'] ?? '');
        if (in_array($status, ['published', 'finished', 'cancelled'], true)) {
            if ($isOwner || Roles::isStaff($role)) {
                return true;
            }
            if (empty($event['isPrivate'])) {
                return true; // public — visible to everyone, including guests
            }
            // Private: only via direct link (this event's id/slug), gated by the access
            // code when the organizer set one. An organizer outside the Team gets no access
            // here at all, per the matrix — only guests/participants get the link route.
            if ($role === 'organizer') {
                return false;
            }
            $requiredCode = (string) ($event['accessCode'] ?? '');
            return $requiredCode === '' || $accessCode === $requiredCode;
        }

        // Draft / Submitted: never public. Draft is Team only, plus admin/superadmin
        // read-only oversight — moderator gets none on Draft per the matrix. Submitted
        // additionally opens up to the moderator for approve/reject.
        if ($isOwner) {
            return true;
        }
        if ($status === 'submitted') {
            return Roles::isStaff($role);
        }
        return in_array($role, Roles::ADMIN, true);
    }

    /**
     * `$ownerLevel = true` asks "may this caller do what only the owner (or admin/superadmin) may do":
     * the closed owner-only list (Team roster, event payment config, delete, ownership transfer)
     * and event money. An active co-manager and a moderator fail it.
     *
     * @param array<string, mixed>|null $event
     * @param array<string, mixed> $auth
     */
    public function canManage(?array $event, array $auth, bool $ownerLevel = false): bool
    {
        if (!$event) {
            return false;
        }
        $role = (string) ($auth['role'] ?? '');
        $userId = (string) ($auth['sub'] ?? '');
        if (($event['deletedAt'] ?? null) !== null && $role !== 'superadmin') {
            return false;
        }
        if ($userId !== '' && ($event['organizerId'] ?? null) === $userId && $role === 'organizer') {
            return true;
        }
        if (Roles::isStaff($role)
            && !($ownerLevel && $role === 'moderator')
            // Staff can edit once an event has cleared moderation (Published/Finished/
            // Cancelled). Draft is Team only and Submitted is view + approve/reject —
            // neither is a general staff edit, per the permission matrix.
            && in_array((string) ($event['publicationStatus'] ?? ''), ['published', 'finished', 'cancelled'], true)) {
            return true;
        }

        // Any other account (whatever its role) is in the Team only as an active co-manager.
        return !$ownerLevel && $userId !== '' && $this->eventTeam->isActiveCoManager((string) ($event['id'] ?? ''), $userId);
    }

    /**
     * @param array<string, mixed>|null $event
     * @param array<string, mixed> $auth
     */
    public function canUploadResults(?array $event, array $auth): bool
    {
        return $this->canManage($event, $auth)
            && in_array((string) ($event['publicationStatus'] ?? ''), ['published', 'finished'], true);
    }

    /**
     * Money on an event (refund approve/complete, manual mark-paid, payment details): the owner or
     * admin/superadmin. The moderator is "admin minus money"; an active co-manager is excluded the same way.
     *
     * @param array<string, mixed>|null $event
     * @param array<string, mixed> $auth
     */
    public function canManageMoney(?array $event, array $auth): bool
    {
        return $this->canManage($event, $auth, true) && (string) ($auth['role'] ?? '') !== 'moderator';
    }
}

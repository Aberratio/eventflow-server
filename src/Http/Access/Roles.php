<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Access;

/** Global account roles grouped the way route guards ask about them. */
final class Roles
{
    public const ADMIN = ['admin', 'superadmin'];
    public const STAFF = ['admin', 'superadmin', 'moderator'];
    public const EVENT_MANAGER = ['organizer', 'admin', 'superadmin', 'moderator'];
    public const PUBLISHER = ['admin', 'superadmin'];

    public static function isStaff(string $role): bool
    {
        return in_array($role, self::STAFF, true);
    }
}

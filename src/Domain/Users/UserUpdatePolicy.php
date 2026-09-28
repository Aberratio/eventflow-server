<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Users;

/**
 * PP.1: pure whitelist policy for `PATCH /users/{id}`. No DB access — the HTTP
 * handler in routes.php owns loading the target user and deciding `$asManager`.
 *
 * Self-editing an Account is restricted to the fields the three client profile
 * forms actually send (never `role`/`status`/`eventLimit`/`organizerPackageCredits`/
 * `password*`/`id` — those go through the dedicated staff endpoints instead). An
 * account manager (admin/superadmin) editing someone else's Account additionally
 * gets `email`/`avatar`.
 */
final class UserUpdatePolicy
{
    /** Fields any Account may change on itself (every field the client profile forms send, minus e-mail). */
    public const SELF_EDITABLE = [
        'name', 'phone', 'address', 'birthDate', 'birthYear', 'gender', 'club', 'sosContacts',
        'companyName', 'nip', 'website', 'description',
    ];

    /** Account manager (admin/superadmin) editing ANOTHER account additionally gets these. */
    public const MANAGER_EXTRA = ['email', 'avatar'];

    /**
     * @param array<string, mixed> $payload
     * @return array{allowed: array<string, mixed>, rejected: list<string>}
     */
    public static function filter(array $payload, bool $asManager, string $currentEmail): array
    {
        $allowedKeys = $asManager ? [...self::SELF_EDITABLE, ...self::MANAGER_EXTRA] : self::SELF_EDITABLE;

        $allowed = [];
        $rejected = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, $allowedKeys, true)) {
                $allowed[$key] = $value;
                continue;
            }
            // Self e-mail change: silently drop when it matches the current e-mail
            // (stale client tolerance), reject only when it is actually different.
            if (!$asManager && $key === 'email') {
                $normalizedCurrent = strtolower(trim($currentEmail));
                $normalizedNext = strtolower(trim((string) $value));
                if ($normalizedNext === $normalizedCurrent) {
                    continue;
                }
            }
            $rejected[] = (string) $key;
        }

        return ['allowed' => $allowed, 'rejected' => $rejected];
    }
}

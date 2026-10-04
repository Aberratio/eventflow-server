<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Auth;

/**
 * The password rule shared by every flow that sets a new password (PP.45 change,
 * PP.7 reset). Codes and messages are API contract: the client maps them to fields.
 */
final class PasswordRule
{
    public const MIN_LENGTH = 8;

    /** bcrypt (PASSWORD_DEFAULT) silently truncates beyond 72 bytes. */
    public const MAX_BYTES = 72;

    /**
     * @param string|null $old the current password, or null when there is none to compare with (reset)
     * @return array{code: string, message: string}|null null when the password is acceptable
     */
    public static function violation(string $new, ?string $old): ?array
    {
        if (mb_strlen($new) < self::MIN_LENGTH) {
            return ['code' => 'password_too_short', 'message' => 'Nowe hasło musi mieć co najmniej 8 znaków.'];
        }
        if (strlen($new) > self::MAX_BYTES) {
            return ['code' => 'password_too_long', 'message' => 'Nowe hasło jest za długie.'];
        }
        if ($old !== null && hash_equals($old, $new)) {
            return ['code' => 'password_unchanged', 'message' => 'Nowe hasło musi różnić się od obecnego.'];
        }

        return null;
    }
}

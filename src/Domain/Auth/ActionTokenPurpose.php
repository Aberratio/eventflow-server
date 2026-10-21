<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Auth;

/** Purposes and lifetimes of one-time action tokens (user_action_tokens.purpose). */
final class ActionTokenPurpose
{
    public const PASSWORD_RESET = 'password_reset';
    public const PASSWORD_RESET_TTL = 1800;
    /** A password-set link for an account an admin created; consumed like a reset link. */
    public const ACCOUNT_SETUP_TTL = 259200;

    public const EMAIL_VERIFICATION = 'email_verification';
    public const EMAIL_VERIFICATION_TTL = 86400;

    public const EMAIL_CHANGE = 'email_change';
    public const EMAIL_CHANGE_TTL = 86400;
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Auth;

/**
 * Server-side check of an already signature-verified session token
 * (token version, revoked jti, user still exists and is active).
 */
interface SessionGuard
{
    /** @param array<string, mixed> $claims */
    public function isSessionValid(array $claims): bool;
}

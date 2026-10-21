<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CentralMailer;

/**
 * Central Mailer returned 409: the `Idempotency-Key` sent was already used for a
 * message whose request content hashes differently.
 * This is not a transient failure — retrying with the same key and the
 * same (deterministic) HTML should never hit this; seeing it means either the
 * caller's rendering was not actually deterministic, or the key was reused
 * across two logically different messages.
 */
final class IdempotencyConflictException extends \RuntimeException
{
}

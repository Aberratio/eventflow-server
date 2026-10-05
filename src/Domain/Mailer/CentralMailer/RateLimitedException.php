<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CentralMailer;

/**
 * Central Mailer answered HTTP 429 (intake rate limit or queue capacity). The message
 * was not accepted; the caller should wait (Retry-After when sent) and try again.
 */
final class RateLimitedException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}

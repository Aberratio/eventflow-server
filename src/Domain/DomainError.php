<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain;

/**
 * A deliberate domain failure. The message stays the snake_case error code, so existing
 * `catch (\RuntimeException $e)` handlers that read `getMessage()` keep working; the category
 * subclass says which HTTP status the code belongs to.
 */
abstract class DomainError extends \RuntimeException
{
    /** @param array<string, mixed> $details extra payload for the error response */
    public function __construct(
        public readonly string $errorCode,
        public readonly array $details = [],
    ) {
        parent::__construct($errorCode);
    }

    abstract public function httpStatus(): int;
}

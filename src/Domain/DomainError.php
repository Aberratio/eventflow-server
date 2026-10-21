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
    /**
     * @param array<string, mixed> $details extra payload for the error response
     * @param ?\Throwable $previous the lower-level failure this error translates, kept for logs
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 0, $previous);
    }

    abstract public function httpStatus(): int;
}

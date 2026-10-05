<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support;

/**
 * PP.76: a client-supplied field is missing or has the wrong type/value.
 * JsonErrorMiddleware renders it as 422 validation_error with details.field.
 *
 * Extends DomainException on purpose (not InvalidArgumentException / RuntimeException) so
 * existing catches of those in route handlers and DomainErrorCode do not swallow it.
 */
final class InputValidationException extends \DomainException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}

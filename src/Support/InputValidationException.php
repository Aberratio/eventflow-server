<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support;

/**
 * A client-supplied field is missing or has the wrong type/value.
 * JsonErrorMiddleware renders it as 422 (code validation_error unless an error code is given) with details.field.
 *
 * Extends DomainException on purpose (not InvalidArgumentException / RuntimeException) so
 * existing catches of those in route handlers and DomainErrorCode do not swallow it.
 */
final class InputValidationException extends \DomainException
{
    /**
     * @param string $errorCode machine-readable code rendered as `error.code`; the default keeps the generic
     *                          `validation_error`. Give a specific one when the client must show the
     *                          server's own message (its adapter maps `validation_error` to a generic text).
     */
    public function __construct(string $message, public readonly string $field, public readonly string $errorCode = 'validation_error')
    {
        parent::__construct($message);
    }
}

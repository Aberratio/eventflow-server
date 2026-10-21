<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\FormFields;

/**
 * A change to a field definition (type / options) that collides with answers already
 * stored on Zapisy. The machine-readable code goes into the 409 response.
 */
final class FormFieldConflictException extends \RuntimeException
{
    public const HAS_ANSWERS = 'form_field_has_answers';
    public const OPTION_IN_USE = 'form_field_option_in_use';
    public const OVERRIDE_EXISTS = 'form_field_override_exists';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

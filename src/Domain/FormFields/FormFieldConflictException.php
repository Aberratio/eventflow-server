<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\FormFields;

/**
 * Zmiana definicji pola (typ / warianty) kolidująca z odpowiedziami już
 * zapisanymi w Zapisach. Kod maszynowy trafia do odpowiedzi 409.
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

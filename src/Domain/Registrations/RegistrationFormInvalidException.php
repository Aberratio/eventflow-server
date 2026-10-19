<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

/**
 * B-07: the form answers / identity values sent with `POST /registrations` failed
 * validation. Carries one entry per offending field so the HTTP layer can answer 422
 * with `error.fields[]` (the same shape PATCH /registrations/{id} uses for `field_not_editable`).
 */
final class RegistrationFormInvalidException extends \RuntimeException
{
    /** @param list<array{fieldId: ?string, fieldKey: ?string, code: string, message: string}> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('registration_form_invalid');
    }

    /** @return list<array{fieldId: ?string, fieldKey: ?string, code: string, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }
}

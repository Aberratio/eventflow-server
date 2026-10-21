<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Errors;

use ZmierzymyCzas\Domain\DomainError;

/** The input is invalid or incomplete (422). */
final class InvalidInput extends DomainError
{
    public function httpStatus(): int
    {
        return 422;
    }
}

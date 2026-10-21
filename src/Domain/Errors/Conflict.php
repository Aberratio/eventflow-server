<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Errors;

use ZmierzymyCzas\Domain\DomainError;

/** The request clashes with the current state of the data (409). */
final class Conflict extends DomainError
{
    public function httpStatus(): int
    {
        return 409;
    }
}

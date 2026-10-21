<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Errors;

use ZmierzymyCzas\Domain\DomainError;

/** The caller may not do this (403). */
final class Forbidden extends DomainError
{
    public function httpStatus(): int
    {
        return 403;
    }
}

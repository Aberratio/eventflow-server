<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Errors;

use ZmierzymyCzas\Domain\DomainError;

/** An unexpected server-side fault or missing prerequisite (500). */
final class Internal extends DomainError
{
    public function httpStatus(): int
    {
        return 500;
    }
}

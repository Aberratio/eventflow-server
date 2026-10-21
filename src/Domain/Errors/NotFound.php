<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Errors;

use ZmierzymyCzas\Domain\DomainError;

/** The addressed record does not exist (404). */
final class NotFound extends DomainError
{
    public function httpStatus(): int
    {
        return 404;
    }
}

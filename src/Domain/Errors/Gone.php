<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Errors;

use ZmierzymyCzas\Domain\DomainError;

/** The resource existed but is no longer available (410). */
final class Gone extends DomainError
{
    public function httpStatus(): int
    {
        return 410;
    }
}

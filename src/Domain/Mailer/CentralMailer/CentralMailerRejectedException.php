<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CentralMailer;

/**
 * Central Mailer rejected the request permanently (HTTP 400 validation, 413 body too
 * large). Retrying the same payload cannot succeed.
 */
final class CentralMailerRejectedException extends \RuntimeException
{
}

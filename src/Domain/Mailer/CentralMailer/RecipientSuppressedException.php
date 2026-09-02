<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CentralMailer;

/**
 * Central Mailer returned 422: the recipient is on its global suppression list
 * (kontrakt-central-mailer.md §1.2 — a discrepancy from its own OpenAPI, which
 * does not document this code). The message was not queued at all.
 */
final class RecipientSuppressedException extends \RuntimeException
{
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

/** The requested start number is already held by another registration of the same event (HTTP 409). */
final class BibNumberTakenException extends \DomainException
{
    public function __construct(public readonly string $bibNumber, public readonly string $heldByRegistrationId)
    {
        parent::__construct('Numer startowy ' . $bibNumber . ' jest już przypisany innemu uczestnikowi.');
    }
}

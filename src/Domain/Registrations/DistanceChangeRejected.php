<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

/**
 * A change of Dystans/Wariant the caller may not (or cannot) make right now. The reason is the
 * error code of the HTTP contract (409 unless `status` says otherwise), `details` its extra payload.
 */
final class DistanceChangeRejected extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $details = [],
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }
}

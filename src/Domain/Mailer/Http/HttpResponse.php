<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\Http;

/**
 * Minimal transport-agnostic HTTP response — mirrors
 * ZmierzymyCzas\Domain\Payments\Http\HttpResponse exactly (see that class for the
 * rationale: no PSR-7 dependency exists in this repo, and this module only ever
 * talks to Central Mailer's own REST API). Kept as its own copy rather than a
 * shared dependency so the Mailer integration stays self-contained the same way
 * Payments is, per the established pattern (each external-service module owns its
 * own Http seam).
 */
final class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = []
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
    }
}

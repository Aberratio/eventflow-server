<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Bz\Http;

/**
 * Minimal transport-agnostic HTTP response — mirrors
 * ZmierzymyCzas\Domain\Mailer\Http\HttpResponse and
 * ZmierzymyCzas\Domain\Payments\Http\HttpResponse exactly. Kept as its own copy
 * rather than a shared dependency: this repo's established pattern is that each
 * external-service module (Payments, Mailer, and now Bz) owns its own Http seam
 * (see Mailer\Http\HttpResponse's doc comment for the original rationale) —
 * there is no PSR-7 client in this codebase, and Bz's outbound webhook is a
 * single, simple POST, not worth pulling in a shared kernel module for.
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

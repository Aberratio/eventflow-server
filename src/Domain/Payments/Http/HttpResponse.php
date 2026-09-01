<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments\Http;

/**
 * Minimal transport-agnostic HTTP response — just enough for the Tpay gateway
 * client and notification-signature verifier below (status, raw body, and the
 * handful of response headers they read). Deliberately not a full PSR-7 message:
 * this module only ever talks to Tpay's REST API and fetches its own signing
 * certificates, so a small value object keeps the seam (HttpClientInterface)
 * trivial to fake in tests without pulling in a PSR-18/Guzzle dependency this
 * repo doesn't otherwise have.
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

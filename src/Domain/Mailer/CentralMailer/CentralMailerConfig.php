<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CentralMailer;

use ZmierzymyCzas\Support\Env;

/**
 * Every Central Mailer-specific value this integration needs, read once from the
 * environment (`.env` / real env vars — see `.env.example`). E1.9: this platform
 * gets its own `X-API-Key`, distinct from every other application talking to the
 * same Central Mailer install (source doc §1.1 — the key maps 1:1 to `sourceApp`
 * and is the hard visibility boundary between applications' mail).
 *
 * `apiKey`/`baseUrl` are placeholders in dev/test (empty string) — see
 * `.env.example` for what production still needs done manually before launch
 * (kontrakt-central-mailer.md §9 point 9: provisioning a second API key in
 * Central Mailer's own `.env` is a deployment task, not something this codebase
 * can do).
 */
final class CentralMailerConfig
{
    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            apiKey: (string) Env::get('CENTRAL_MAILER_API_KEY', ''),
            baseUrl: rtrim((string) Env::get('CENTRAL_MAILER_BASE_URL', ''), '/'),
        );
    }

    /**
     * False whenever this deployment has no real Central Mailer credentials yet
     * (local dev, CI, this repo's own test suite — none of them set
     * CENTRAL_MAILER_API_KEY/CENTRAL_MAILER_BASE_URL). Callers should treat "not
     * configured" as "do not attempt to send" rather than making a request that
     * could only ever fail, exactly like TpayConfig::isConfigured()/
     * PayNowConfig::isConfigured().
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->baseUrl !== '';
    }
}

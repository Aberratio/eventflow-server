<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Auth;

use ZmierzymyCzas\Support\Env;

/**
 * E3.1: "Klucz API per aplikacja" (wzorzec Central Mailera) — static API keys for
 * external applications calling INTO this platform (Biuro Zawodów first, more
 * later), read once from the environment (`.env` / real env vars — see
 * `.env.example`). Deliberately generic: adding a second calling application
 * means adding one more "application=key" pair to `INCOMING_API_KEYS`, never
 * touching this class.
 *
 * This is the mirror image of TpayConfig/PayNowConfig/CentralMailerConfig,
 * which configure keys this platform sends on OUTGOING calls. Those treat
 * "unset" as "skip the call" — safe, because nothing happens. This class gates
 * INCOMING access, so "unset" must mean "nothing is authorized": with no pairs
 * configured, resolveApplication() rejects every presented key instead of
 * accepting everything. Get this backwards and local/CI/staging with an empty
 * `.env` would silently accept any caller.
 *
 * Not a replacement for JwtService — that authenticates a human user's session;
 * this authenticates a calling application that carries no user identity.
 */
final class ApplicationApiKeyConfig
{
    /** @param array<string, string> $keysByApplication application name => configured key */
    public function __construct(
        private readonly array $keysByApplication,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(self::parse((string) Env::get('INCOMING_API_KEYS', '')));
    }

    /**
     * Resolves which configured application presented `$key`, using a
     * timing-safe comparison (`hash_equals`, never `==`/`===`) against every
     * configured key so the check takes the same time regardless of which
     * application (if any) matches. Returns null for an empty, malformed, or
     * unknown key, and null for every key when no applications are configured
     * at all — fail closed, the opposite of TpayConfig/PayNowConfig/
     * CentralMailerConfig's "unset = skip" idiom (see class docblock).
     */
    public function resolveApplication(string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        $resolved = null;
        foreach ($this->keysByApplication as $application => $configuredKey) {
            if (hash_equals($configuredKey, $key)) {
                $resolved = $application;
            }
        }

        return $resolved;
    }

    /**
     * @return array<string, string>
     */
    private static function parse(string $raw): array
    {
        $keys = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || !str_contains($entry, '=')) {
                continue;
            }

            [$application, $key] = explode('=', $entry, 2);
            $application = trim($application);
            $key = trim($key);
            if ($application === '' || $key === '') {
                continue;
            }

            $keys[$application] = $key;
        }

        return $keys;
    }
}

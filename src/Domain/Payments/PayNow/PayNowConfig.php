<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments\PayNow;

use ZmierzymyCzas\Support\Env;

/**
 * Every PayNow-specific value this integration needs, read once from the
 * environment (`.env` / real env vars — see `.env.example`). The api key /
 * signature key are placeholders in dev/test (empty string, per
 * PAYNOW_API_KEY/PAYNOW_SIGNATURE_KEY being unset) — swap only these values
 * for real credentials from the PayNow Merchant Panel (Ustawienia > Sklepy i
 * kasy > Autoryzacja) to actually exercise the gateway; nothing about the
 * request-building/signature code changes between sandbox and production,
 * only these values and the base URL PAYNOW_SANDBOX picks between.
 *
 * Unlike Tpay, PayNow's v1 API has no per-request notification-url field —
 * the callback URL is configured once, in the Merchant Panel itself, against
 * the point-of-sale the api key belongs to. `notificationUrl` is kept here
 * only so ops has one place documenting what that Merchant Panel setting
 * should point at (see .env.example) — it is never sent in a request body.
 */
final class PayNowConfig
{
    private const PRODUCTION_API_BASE = 'https://api.paynow.pl';
    private const SANDBOX_API_BASE = 'https://api.sandbox.paynow.pl';

    public function __construct(
        public readonly string $apiKey,
        public readonly string $signatureKey,
        public readonly bool $sandbox,
        public readonly string $notificationUrl,
        public readonly string $continueUrl,
    ) {
    }

    public static function fromEnv(): self
    {
        $appUrl = rtrim((string) Env::get('APP_URL', 'http://localhost:8081'), '/');

        return new self(
            apiKey: (string) Env::get('PAYNOW_API_KEY', ''),
            signatureKey: (string) Env::get('PAYNOW_SIGNATURE_KEY', ''),
            sandbox: Env::getBool('PAYNOW_SANDBOX', true),
            notificationUrl: (string) Env::get('PAYNOW_NOTIFICATION_URL', $appUrl . '/api/v1/webhooks/paynow'),
            continueUrl: (string) Env::get('PAYNOW_CONTINUE_URL', $appUrl . '/platnosc/sukces'),
        );
    }

    public function apiBaseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_API_BASE : self::PRODUCTION_API_BASE;
    }

    /**
     * False whenever this deployment has no real PayNow credentials yet (local
     * dev, CI, this repo's own test suite — none of them set
     * PAYNOW_API_KEY/PAYNOW_SIGNATURE_KEY). RegistrationRepository treats "not
     * configured" as "skip starting a gateway session" rather than attempting
     * a request that could only ever fail — see routes.php's wiring.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->signatureKey !== '';
    }
}

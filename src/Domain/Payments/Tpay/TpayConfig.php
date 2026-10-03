<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments\Tpay;

use ZmierzymyCzas\Support\Env;

/**
 * Every Tpay-specific value this integration needs, read once from the environment
 * (`.env` / real env vars — see `.env.example`). The merchant id/client id/secret
 * are placeholders in dev/test (TPAY_CLIENT_ID=sandbox-placeholder, etc.) — swap
 * only these values for production credentials from the Tpay Merchant Panel
 * (Integration > API); nothing about the request-building/signature-verification
 * code below changes between sandbox and production, only these values and the
 * base URL that TPAY_SANDBOX picks between.
 */
final class TpayConfig
{
    private const PRODUCTION_API_BASE = 'https://api.tpay.com';
    private const SANDBOX_API_BASE = 'https://openapi.sandbox.tpay.com';
    private const PRODUCTION_CERT_HOSTNAME = 'secure.tpay.com';
    private const SANDBOX_CERT_HOSTNAME = 'secure.sandbox.tpay.com';

    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $merchantId,
        public readonly bool $sandbox,
        public readonly string $notificationUrl,
        public readonly string $successUrl,
        public readonly string $errorUrl,
    ) {
    }

    public static function fromEnv(): self
    {
        $appUrl = rtrim((string) Env::get('APP_URL', 'http://localhost:8081'), '/');

        return new self(
            clientId: (string) Env::get('TPAY_CLIENT_ID', ''),
            clientSecret: (string) Env::get('TPAY_CLIENT_SECRET', ''),
            merchantId: (string) Env::get('TPAY_MERCHANT_ID', ''),
            sandbox: Env::getBool('TPAY_SANDBOX', true),
            notificationUrl: (string) Env::get('TPAY_NOTIFICATION_URL', $appUrl . '/api/v1/webhooks/tpay'),
            successUrl: (string) Env::get('TPAY_SUCCESS_URL', $appUrl . '/platnosc/sukces'),
            errorUrl: (string) Env::get('TPAY_ERROR_URL', $appUrl . '/platnosc/blad'),
        );
    }

    public function apiBaseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_API_BASE : self::PRODUCTION_API_BASE;
    }

    /** Bare hostname every notification's JWS `x5u` must use — exact match, see TpayCertificateUrlPolicy. */
    public function trustedCertHostname(): string
    {
        return $this->sandbox ? self::SANDBOX_CERT_HOSTNAME : self::PRODUCTION_CERT_HOSTNAME;
    }

    /** Trusted origin of Tpay's certificate host (config-derived, not attacker input); used for the root CA URL. */
    public function trustedCertHost(): string
    {
        return 'https://' . $this->trustedCertHostname();
    }

    public function rootCaCertUrl(): string
    {
        return $this->trustedCertHost() . '/x509/tpay-jws-root.pem';
    }

    /**
     * False whenever this deployment has no real Tpay credentials yet (local dev,
     * CI, this repo's own test suite — none of them set TPAY_CLIENT_ID/SECRET).
     * RegistrationRepository treats "not configured" as "skip starting a gateway
     * session" rather than attempting a request that would only fail against
     * Tpay's real API — see routes.php's wiring.
     */
    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }
}

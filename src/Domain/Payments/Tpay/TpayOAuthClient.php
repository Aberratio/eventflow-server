<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments\Tpay;

use ZmierzymyCzas\Domain\Errors\Internal;
use ZmierzymyCzas\Domain\Payments\Http\HttpClientInterface;

/**
 * OAuth2 client-credentials token acquisition for the Tpay Transactions API
 * (POST {base}/oauth/auth with client_id/client_secret/grant_type=client_credentials,
 * form-encoded — see https://docs-api.tpay.com/en/first-steps/authorization/).
 * Tokens are cached in-memory for the process lifetime of this instance (keyed off
 * the `expires_in` the token response carries), since every request in a single PHP
 * request lifecycle that needs a token can reuse the same one — there is no
 * cross-request cache here because this repo has no shared cache layer to hang one
 * off (Redis/APCu); a bad trade would be adding one just for this.
 */
final class TpayOAuthClient
{
    private ?string $cachedToken = null;
    private int $cachedTokenExpiresAt = 0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly TpayConfig $config
    ) {
    }

    public function accessToken(): string
    {
        if ($this->cachedToken !== null && time() < $this->cachedTokenExpiresAt) {
            return $this->cachedToken;
        }

        $body = http_build_query([
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
            'grant_type' => 'client_credentials',
        ]);

        $response = $this->http->request('POST', $this->config->apiBaseUrl() . '/oauth/auth', [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], $body);

        if (!$response->isSuccessful()) {
            throw new \RuntimeException('tpay_oauth_failed: HTTP ' . $response->status);
        }

        $decoded = $response->json();
        $token = (string) ($decoded['access_token'] ?? '');
        if ($token === '') {
            throw new Internal('tpay_oauth_missing_access_token');
        }

        $this->cachedToken = $token;
        // 60s safety margin so a token never gets used right as it expires.
        $this->cachedTokenExpiresAt = time() + max(0, (int) ($decoded['expires_in'] ?? 0) - 60);

        return $token;
    }
}

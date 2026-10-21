<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments\Http;

/**
 * The seam every real gateway HTTP call goes through — TpayOAuthClient, TpayGateway
 * and TpayNotificationVerifier (fetching Tpay's signing certificates) all depend on
 * this interface, never on curl directly. Tests inject a fake implementation (see
 * tests/Unit/Payments/FakeHttpClient.php) so nothing in this repo's test suite ever
 * opens a real socket to an external host, per the rule "mock the HTTP layer,
 * don't fake the integration logic" requirement.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}

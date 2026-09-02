<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\Http;

/**
 * The seam every real Central Mailer HTTP call goes through — CentralMailerClient
 * depends on this interface, never on curl directly. Tests inject a fake
 * implementation (see tests/Support/FakeCentralMailerHttpClient.php) so nothing in
 * this repo's test suite ever opens a real socket to Central Mailer, mirroring
 * ZmierzymyCzas\Domain\Payments\Http\HttpClientInterface's role for Tpay/PayNow.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}

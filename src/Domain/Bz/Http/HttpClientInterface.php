<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Bz\Http;

/**
 * The seam every real outbound call to Biuro Zawodów's webhook receiver goes
 * through — BzWebhookClient depends on this interface, never on curl directly.
 * Tests inject a fake implementation (tests/Support/FakeBzHttpClient.php) so
 * nothing in this repo's test suite ever opens a real socket — there is no live
 * BZ receiver to call anyway (see BzWebhookClient's doc comment).
 */
interface HttpClientInterface
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}

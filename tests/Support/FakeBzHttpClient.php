<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Support;

use ZmierzymyCzas\Domain\Bz\Http\HttpClientInterface;
use ZmierzymyCzas\Domain\Bz\Http\HttpResponse;

/**
 * Test double for Domain\Bz\Http\HttpClientInterface — queues canned
 * HttpResponses (in call order) and records every request made, so
 * BzWebhookClient can be exercised without ever opening a real socket. There
 * is no live BZ receiver to call anyway (see BzWebhookClient's doc comment).
 * Mirrors tests/Support/FakeCentralMailerHttpClient.php exactly.
 */
final class FakeBzHttpClient implements HttpClientInterface
{
    /** @var array<int, HttpResponse> */
    private array $queue = [];

    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $calls = [];

    public function queueResponse(HttpResponse $response): void
    {
        $this->queue[] = $response;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        $response = array_shift($this->queue);
        if ($response === null) {
            throw new \RuntimeException('FakeBzHttpClient: no queued response for ' . $method . ' ' . $url);
        }

        return $response;
    }
}

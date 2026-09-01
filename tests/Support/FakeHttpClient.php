<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Support;

use ZmierzymyCzas\Domain\Payments\Http\HttpClientInterface;
use ZmierzymyCzas\Domain\Payments\Http\HttpResponse;

/**
 * Test double for HttpClientInterface — queues canned HttpResponses (in call
 * order) and records every request made, so integration logic (OAuth token
 * fetch, transaction creation, refund, certificate fetch) can be exercised
 * without ever opening a real socket to Tpay, per the ticket's testing
 * requirement.
 */
final class FakeHttpClient implements HttpClientInterface
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
            throw new \RuntimeException('FakeHttpClient: no queued response for ' . $method . ' ' . $url);
        }

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Bz;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Bz\BzWebhookClient;
use ZmierzymyCzas\Domain\Bz\BzWebhookConfig;
use ZmierzymyCzas\Domain\Bz\Http\HttpResponse;
use ZmierzymyCzas\Tests\Support\FakeBzHttpClient;

final class BzWebhookClientTest extends TestCase
{
    private FakeBzHttpClient $http;
    private BzWebhookClient $client;

    protected function setUp(): void
    {
        $this->http = new FakeBzHttpClient();
        $config = new BzWebhookConfig(baseUrl: 'https://bz.example.test');
        $this->client = new BzWebhookClient($this->http, $config);
    }

    public function testPushBuildsRequestPerContract(): void
    {
        $this->http->queueResponse(new HttpResponse(202, '{}'));

        $this->client->push([
            'eventId' => 'evt-1',
            'registrationId' => 'reg-1',
            'changeType' => 'cancellation',
            'occurredAt' => '2026-01-01T00:00:00+00:00',
            'qrCode' => 'QR-123',
        ], 'org-token-abc', 'bz-push:reg-1:cancellation');

        self::assertCount(1, $this->http->calls);
        $call = $this->http->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://bz.example.test/webhooks/critical-changes', $call['url']);
        self::assertSame('org-token-abc', $call['headers']['X-BZ-Organization-Token']);
        self::assertSame('bz-push:reg-1:cancellation', $call['headers']['Idempotency-Key']);

        $sent = json_decode((string) $call['body'], true);
        self::assertSame('evt-1', $sent['eventId']);
        self::assertSame('reg-1', $sent['registrationId']);
        self::assertSame('cancellation', $sent['changeType']);
        self::assertSame('2026-01-01T00:00:00+00:00', $sent['occurredAt']);
        self::assertSame('QR-123', $sent['qrCode']);

        // Money-leakage discipline: the payload passed straight
        // through is never inspected/augmented by the client, but this
        // regression pins the exact key set so a future caller accidentally
        // adding e.g. 'amount' would fail this test, not silently ship.
        self::assertSame(['eventId', 'registrationId', 'changeType', 'occurredAt', 'qrCode'], array_keys($sent));
    }

    public function testPushThrowsOnNonSuccessfulResponse(): void
    {
        $this->http->queueResponse(new HttpResponse(500, 'server error'));

        $this->expectException(\RuntimeException::class);

        $this->client->push([
            'eventId' => 'evt-1',
            'registrationId' => 'reg-1',
            'changeType' => 'cancellation',
            'occurredAt' => '2026-01-01T00:00:00+00:00',
        ], 'org-token-abc', 'bz-push:reg-1:cancellation');
    }
}

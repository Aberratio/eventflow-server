<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Payments;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Payments\Http\HttpResponse;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayConfig;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayOAuthClient;
use ZmierzymyCzas\Tests\Support\FakeHttpClient;

final class TpayOAuthClientTest extends TestCase
{
    private function config(): TpayConfig
    {
        return new TpayConfig(
            clientId: 'client-123',
            clientSecret: 'secret-456',
            merchantId: 'merchant-789',
            sandbox: true,
            notificationUrl: 'https://example.test/webhooks/tpay',
            successUrl: 'https://example.test/success',
            errorUrl: 'https://example.test/error',
        );
    }

    public function testFetchesTokenFromSandboxOauthEndpointWithClientCredentials(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(new HttpResponse(200, json_encode(['access_token' => 'tok-1', 'expires_in' => 3600])));

        $client = new TpayOAuthClient($http, $this->config());
        $token = $client->accessToken();

        self::assertSame('tok-1', $token);
        self::assertCount(1, $http->calls);
        self::assertSame('POST', $http->calls[0]['method']);
        self::assertSame('https://openapi.sandbox.tpay.com/oauth/auth', $http->calls[0]['url']);
        self::assertSame('application/x-www-form-urlencoded', $http->calls[0]['headers']['Content-Type']);
        parse_str((string) $http->calls[0]['body'], $sent);
        self::assertSame('client-123', $sent['client_id']);
        self::assertSame('secret-456', $sent['client_secret']);
        self::assertSame('client_credentials', $sent['grant_type']);
    }

    public function testCachesTokenAcrossCallsWithinExpiry(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(new HttpResponse(200, json_encode(['access_token' => 'tok-1', 'expires_in' => 3600])));

        $client = new TpayOAuthClient($http, $this->config());
        self::assertSame('tok-1', $client->accessToken());
        self::assertSame('tok-1', $client->accessToken());
        self::assertCount(1, $http->calls, 'a cached, still-valid token must not trigger a second oauth request');
    }

    public function testRequestsProductionEndpointWhenNotSandbox(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(new HttpResponse(200, json_encode(['access_token' => 'tok-prod', 'expires_in' => 3600])));

        $config = new TpayConfig('id', 'secret', 'merchant', false, 'https://x/n', 'https://x/s', 'https://x/e');
        $client = new TpayOAuthClient($http, $config);
        $client->accessToken();

        self::assertSame('https://api.tpay.com/oauth/auth', $http->calls[0]['url']);
    }

    public function testThrowsWhenOauthRequestFails(): void
    {
        $http = new FakeHttpClient();
        $http->queueResponse(new HttpResponse(401, 'invalid_client'));

        $client = new TpayOAuthClient($http, $this->config());

        $this->expectException(\RuntimeException::class);
        $client->accessToken();
    }
}

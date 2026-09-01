<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Payments;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Payments\Http\HttpResponse;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayConfig;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayGateway;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayOAuthClient;
use ZmierzymyCzas\Tests\Support\FakeHttpClient;

final class TpayGatewayTest extends TestCase
{
    private FakeHttpClient $http;
    private TpayGateway $gateway;
    private TpayConfig $config;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->config = new TpayConfig(
            clientId: 'client-123',
            clientSecret: 'secret-456',
            merchantId: 'merchant-789',
            sandbox: true,
            notificationUrl: 'https://example.test/api/v1/webhooks/tpay',
            successUrl: 'https://example.test/success',
            errorUrl: 'https://example.test/error',
        );
        $this->gateway = new TpayGateway($this->http, $this->config, new TpayOAuthClient($this->http, $this->config));
    }

    public function testStartSessionBuildsRequestPerTpayTransactionsApiContractAndReturnsRedirect(): void
    {
        $this->http->queueResponse(new HttpResponse(200, json_encode(['access_token' => 'tok-1', 'expires_in' => 3600])));
        $this->http->queueResponse(new HttpResponse(200, json_encode([
            'transactionId' => 'TX-1',
            'transactionPaymentUrl' => 'https://secure.sandbox.tpay.com/pay/TX-1',
        ])));

        $result = $this->gateway->startSession('reg-1', 123.45, 'Zapis: Bieg', 'runner@example.test', 'Jan Kowalski');

        self::assertSame(['transactionId' => 'TX-1', 'redirectUrl' => 'https://secure.sandbox.tpay.com/pay/TX-1'], $result);

        self::assertCount(2, $this->http->calls);
        $createCall = $this->http->calls[1];
        self::assertSame('POST', $createCall['method']);
        self::assertSame('https://openapi.sandbox.tpay.com/transactions', $createCall['url']);
        self::assertSame('Bearer tok-1', $createCall['headers']['Authorization']);

        $sent = json_decode((string) $createCall['body'], true);
        // The amount sent to Tpay must be exactly what the caller (RegistrationRepository,
        // always the frozen Rozliczenie total) passed in — never re-derived here.
        self::assertSame(123.45, $sent['amount']);
        self::assertSame('reg-1', $sent['hiddenDescription']);
        self::assertSame('runner@example.test', $sent['payer']['email']);
        self::assertSame('Jan Kowalski', $sent['payer']['name']);
        self::assertSame('https://example.test/api/v1/webhooks/tpay', $sent['callbacks']['notification']['url']);
        self::assertSame('https://example.test/success', $sent['callbacks']['payerUrls']['success']);
        self::assertSame('https://example.test/error', $sent['callbacks']['payerUrls']['error']);
    }

    public function testStartSessionThrowsOnUnsuccessfulResponse(): void
    {
        $this->http->queueResponse(new HttpResponse(200, json_encode(['access_token' => 'tok-1', 'expires_in' => 3600])));
        $this->http->queueResponse(new HttpResponse(422, json_encode(['error' => 'invalid_payer'])));

        $this->expectException(\RuntimeException::class);
        $this->gateway->startSession('reg-1', 10.0, 'desc', 'a@b.test', 'A B');
    }

    public function testStartSessionThrowsOnMalformedResponse(): void
    {
        $this->http->queueResponse(new HttpResponse(200, json_encode(['access_token' => 'tok-1', 'expires_in' => 3600])));
        $this->http->queueResponse(new HttpResponse(200, json_encode(['something' => 'else'])));

        $this->expectException(\RuntimeException::class);
        $this->gateway->startSession('reg-1', 10.0, 'desc', 'a@b.test', 'A B');
    }

    public function testRefundPostsToTransactionRefundsEndpointWithAmount(): void
    {
        $this->http->queueResponse(new HttpResponse(200, json_encode(['access_token' => 'tok-1', 'expires_in' => 3600])));
        $this->http->queueResponse(new HttpResponse(200, json_encode(['status' => 'ok'])));

        $this->gateway->refund('TX-1', 50.5);

        $refundCall = $this->http->calls[1];
        self::assertSame('https://openapi.sandbox.tpay.com/transactions/TX-1/refunds', $refundCall['url']);
        $sent = json_decode((string) $refundCall['body'], true);
        self::assertSame(50.5, $sent['amount']);
    }

    public function testRefundThrowsOnFailure(): void
    {
        $this->http->queueResponse(new HttpResponse(200, json_encode(['access_token' => 'tok-1', 'expires_in' => 3600])));
        $this->http->queueResponse(new HttpResponse(500, 'server error'));

        $this->expectException(\RuntimeException::class);
        $this->gateway->refund('TX-1', 10.0);
    }
}

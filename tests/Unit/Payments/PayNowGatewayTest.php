<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Payments;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Payments\Http\HttpResponse;
use ZmierzymyCzas\Domain\Payments\PayNow\PayNowConfig;
use ZmierzymyCzas\Domain\Payments\PayNow\PayNowGateway;
use ZmierzymyCzas\Domain\Payments\PayNow\PayNowSignature;
use ZmierzymyCzas\Tests\Support\FakeHttpClient;

final class PayNowGatewayTest extends TestCase
{
    private FakeHttpClient $http;
    private PayNowGateway $gateway;
    private PayNowConfig $config;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->config = new PayNowConfig(
            apiKey: 'api-key-123',
            signatureKey: 'signature-key-456',
            sandbox: true,
            notificationUrl: 'https://example.test/api/v1/webhooks/paynow',
            continueUrl: 'https://example.test/success',
        );
        $this->gateway = new PayNowGateway($this->http, $this->config);
    }

    public function testStartSessionBuildsRequestPerPaynowPaymentsApiContractAndReturnsRedirect(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode([
            'paymentId' => 'PN-1',
            'redirectUrl' => 'https://api.sandbox.paynow.pl/redirect/PN-1',
            'status' => 'NEW',
        ])));

        $result = $this->gateway->startSession('reg-1', 123.45, 'Zapis: Bieg', 'runner@example.test', 'Jan Kowalski');

        self::assertSame(['transactionId' => 'PN-1', 'redirectUrl' => 'https://api.sandbox.paynow.pl/redirect/PN-1'], $result);

        self::assertCount(1, $this->http->calls);
        $call = $this->http->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://api.sandbox.paynow.pl/v1/payments', $call['url']);
        self::assertSame('api-key-123', $call['headers']['Api-Key']);
        self::assertNotEmpty($call['headers']['Idempotency-Key']);

        $sent = json_decode((string) $call['body'], true);
        // Amount converted to grosze (smallest currency unit) — PayNow's documented
        // integer unit, unlike Tpay's decimal PLN float.
        self::assertSame(12345, $sent['amount']);
        self::assertSame('reg-1', $sent['externalId']);
        self::assertSame('runner@example.test', $sent['buyer']['email']);
        self::assertSame('Jan', $sent['buyer']['firstName']);
        self::assertSame('Kowalski', $sent['buyer']['lastName']);
        self::assertSame('https://example.test/success', $sent['continueUrl']);

        // The signature header must be exactly HMAC-SHA256(body, signatureKey), base64.
        self::assertSame(
            PayNowSignature::compute((string) $call['body'], 'signature-key-456'),
            $call['headers']['Signature']
        );
    }

    public function testStartSessionThrowsOnUnsuccessfulResponse(): void
    {
        $this->http->queueResponse(new HttpResponse(422, json_encode(['error' => 'invalid_buyer'])));

        $this->expectException(\RuntimeException::class);
        $this->gateway->startSession('reg-1', 10.0, 'desc', 'a@b.test', 'A B');
    }

    public function testStartSessionThrowsOnMalformedResponse(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['something' => 'else'])));

        $this->expectException(\RuntimeException::class);
        $this->gateway->startSession('reg-1', 10.0, 'desc', 'a@b.test', 'A B');
    }

    public function testRefundPostsToPaymentRefundsEndpointWithGroszeAmount(): void
    {
        $this->http->queueResponse(new HttpResponse(201, json_encode(['refundId' => 'REFX-1', 'status' => 'PENDING'])));

        $this->gateway->refund('PN-1', 50.5);

        $refundCall = $this->http->calls[0];
        self::assertSame('https://api.sandbox.paynow.pl/v1/payments/PN-1/refunds', $refundCall['url']);
        $sent = json_decode((string) $refundCall['body'], true);
        self::assertSame(5050, $sent['amount']);
    }

    public function testRefundThrowsOnFailure(): void
    {
        $this->http->queueResponse(new HttpResponse(500, 'server error'));

        $this->expectException(\RuntimeException::class);
        $this->gateway->refund('PN-1', 10.0);
    }

    public function testRequestsProductionEndpointWhenNotSandbox(): void
    {
        $config = new PayNowConfig('key', 'secret', false, 'https://x/n', 'https://x/c');
        $gateway = new PayNowGateway($this->http, $config);
        $this->http->queueResponse(new HttpResponse(201, json_encode(['paymentId' => 'PN-2', 'redirectUrl' => 'https://x/pay'])));

        $gateway->startSession('reg-2', 10.0, 'desc', 'a@b.test', 'A');

        self::assertSame('https://api.paynow.pl/v1/payments', $this->http->calls[0]['url']);
    }
}

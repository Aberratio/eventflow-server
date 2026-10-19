<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * G-06: GET /shop/payment-methods lists the methods the shop can really take.
 * Bank transfer is always offered; card and BLIK are online-gateway methods and
 * appear only when Tpay or PayNow is configured in the environment.
 */
final class ShopPaymentMethodsContractTest extends HttpTestCase
{
    private const KEYS = ['TPAY_CLIENT_ID', 'TPAY_CLIENT_SECRET', 'PAYNOW_API_KEY', 'PAYNOW_SIGNATURE_KEY'];

    protected function setUp(): void
    {
        // Gateway config is read when the routes are registered, so set it before the app is built.
        foreach (self::KEYS as $key) {
            Env::set($key, '');
        }
        if (str_contains($this->name(), 'Tpay')) {
            Env::set('TPAY_CLIENT_ID', 'phpunit-id');
            Env::set('TPAY_CLIENT_SECRET', 'phpunit-secret');
        }
        if (str_contains($this->name(), 'PayNow')) {
            Env::set('PAYNOW_API_KEY', 'phpunit-key');
            Env::set('PAYNOW_SIGNATURE_KEY', 'phpunit-sig');
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            Env::restore($key);
        }
    }

    /** @return list<string> */
    private function ids(): array
    {
        $response = $this->request('GET', '/api/v1/shop/payment-methods');
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        foreach ($data as $method) {
            self::assertSame(['id', 'name'], array_keys($method));
        }
        return array_column($data, 'id');
    }

    public function testWithoutAnyGatewayOnlyBankTransferIsOffered(): void
    {
        self::assertSame(['bank'], $this->ids());
    }

    public function testWithTpayConfiguredOnlineMethodsAreOffered(): void
    {
        self::assertSame(['card', 'blik', 'bank'], $this->ids());
    }

    public function testWithPayNowConfiguredOnlineMethodsAreOffered(): void
    {
        self::assertSame(['card', 'blik', 'bank'], $this->ids());
    }
}

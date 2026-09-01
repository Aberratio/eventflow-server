<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Payments;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Payments\PayNow\PayNowConfig;
use ZmierzymyCzas\Domain\Payments\PayNow\PayNowNotificationVerifier;

/**
 * Exercises the real HMAC-SHA256 verification mechanism against a real,
 * locally-generated signature key — not a mock of the crypto itself. Unlike
 * Tpay's JWS verifier, PayNow's is a plain shared-secret HMAC with no network
 * call involved at all, so this test needs no HTTP fake.
 */
final class PayNowNotificationVerifierTest extends TestCase
{
    private const SIGNATURE_KEY = 'a-real-throwaway-signature-key-for-tests';

    private PayNowConfig $config;

    protected function setUp(): void
    {
        $this->config = new PayNowConfig('api-key', self::SIGNATURE_KEY, true, 'https://x/n', 'https://x/c');
    }

    private function sign(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, self::SIGNATURE_KEY, true));
    }

    public function testAcceptsAValidSignatureComputedWithTheRealSignatureKey(): void
    {
        $body = (string) json_encode(['paymentId' => 'PN-1', 'externalId' => 'reg-1', 'status' => 'CONFIRMED', 'modifiedAt' => '2026-06-15T12:00:00']);
        $verifier = new PayNowNotificationVerifier($this->config);

        self::assertTrue($verifier->verify($body, $this->sign($body)));
    }

    public function testRejectsWhenBodyWasTamperedAfterSigning(): void
    {
        $signedBody = (string) json_encode(['paymentId' => 'PN-1', 'status' => 'CONFIRMED']);
        $signature = $this->sign($signedBody);
        $tamperedBody = (string) json_encode(['paymentId' => 'PN-1', 'status' => 'REJECTED']);

        $verifier = new PayNowNotificationVerifier($this->config);
        self::assertFalse($verifier->verify($tamperedBody, $signature));
    }

    public function testRejectsASignatureComputedWithTheWrongKey(): void
    {
        $body = (string) json_encode(['paymentId' => 'PN-1', 'status' => 'CONFIRMED']);
        $wrongSignature = base64_encode(hash_hmac('sha256', $body, 'not-the-real-key', true));

        $verifier = new PayNowNotificationVerifier($this->config);
        self::assertFalse($verifier->verify($body, $wrongSignature));
    }

    public function testRejectsAnEmptySignatureHeader(): void
    {
        $verifier = new PayNowNotificationVerifier($this->config);
        self::assertFalse($verifier->verify('{}', ''));
        self::assertFalse($verifier->verify('{}', '   '));
    }

    public function testRejectsWhenNoSignatureKeyIsConfigured(): void
    {
        $config = new PayNowConfig('api-key', '', true, 'https://x/n', 'https://x/c');
        $verifier = new PayNowNotificationVerifier($config);

        $body = '{}';
        $signature = base64_encode(hash_hmac('sha256', $body, '', true));

        self::assertFalse($verifier->verify($body, $signature));
    }
}

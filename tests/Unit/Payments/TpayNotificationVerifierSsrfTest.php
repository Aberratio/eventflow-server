<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Payments;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Payments\Http\HttpResponse;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayConfig;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayNotificationVerifier;
use ZmierzymyCzas\Tests\Support\FakeHttpClient;

/**
 * SSRF regression guard: a notification whose JWS `x5u` is not exactly Tpay's
 * certificate URL must be rejected without ANY outbound HTTP call. Needs no
 * openssl: the signature segment is a dummy, because the policy runs before the
 * certificate is even fetched. Uses only hard-coded host strings and existing
 * APIs so it can also run against code that predates the fix.
 */
final class TpayNotificationVerifierSsrfTest extends TestCase
{
    private const BODY = '{"transactionId":"TX-1","transactionStatus":"correct"}';

    private static function config(bool $sandbox): TpayConfig
    {
        return new TpayConfig('id', 'secret', 'merchant', $sandbox, 'https://x/n', 'https://x/s', 'https://x/e');
    }

    private static function b64(string $v): string
    {
        return rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    }

    private static function jws(mixed $x5u): string
    {
        return self::b64((string) json_encode(['alg' => 'RS256', 'x5u' => $x5u])) . '.' . self::b64(self::BODY) . '.' . self::b64('x');
    }

    /** @return array<string, array{string}> */
    public static function rejectedSandboxX5uProvider(): array
    {
        $h = 'secure.sandbox.tpay.com';

        return [
            'userinfo host trick' => ["https://{$h}@10.0.0.1/x509/notifications-jws.pem"],
            'userinfo password' => ["https://{$h}:pw@10.0.0.1/x509/a.pem"],
            'suffix domain' => ["https://{$h}.evil.test/x509/a.pem"],
            'prefix domain' => ["https://evil{$h}/x509/a.pem"],
            'http scheme' => ["http://{$h}/x509/a.pem"],
            'port 443' => ["https://{$h}:443/x509/a.pem"],
            'port 8443' => ["https://{$h}:8443/x509/a.pem"],
            'uppercase host' => ['https://SECURE.SANDBOX.TPAY.COM/x509/a.pem'],
            'trailing dot' => ["https://{$h}./x509/a.pem"],
            'query' => ["https://{$h}/x509/a.pem?x=1"],
            'fragment' => ["https://{$h}/x509/a.pem#f"],
            'dot dot' => ["https://{$h}/x509/../internal.pem"],
            'encoded dot dot' => ["https://{$h}/x509/%2e%2e/a.pem"],
            'backslash at' => ["https://{$h}\\@10.0.0.1/x509/a.pem"],
            'double slash path' => ["https://{$h}//x509/a.pem"],
            'other path' => ["https://{$h}/other/a.pem"],
            'wrong extension' => ["https://{$h}/x509/a.pem.txt"],
            'trailing newline' => ["https://{$h}/x509/a.pem\n"],
            'foreign host' => ['https://attacker.example.com/x509/a.pem'],
            'ip host' => ['https://10.0.0.1/x509/a.pem'],
        ];
    }

    #[DataProvider('rejectedSandboxX5uProvider')]
    public function testRejectedX5uNeverReachesTheHttpClient(string $x5u): void
    {
        $http = new FakeHttpClient();
        $verifier = new TpayNotificationVerifier($http, self::config(true));

        self::assertFalse($verifier->verify(self::BODY, self::jws($x5u)));
        self::assertCount(0, $http->calls);
    }

    public function testProductionConfigRejectsSandboxCertHostWithoutFetching(): void
    {
        $http = new FakeHttpClient();
        $verifier = new TpayNotificationVerifier($http, self::config(false));

        self::assertFalse($verifier->verify(self::BODY, self::jws('https://secure.sandbox.tpay.com/x509/notifications-jws.pem')));
        self::assertCount(0, $http->calls);
    }

    public function testSandboxConfigRejectsProductionCertHostWithoutFetching(): void
    {
        $http = new FakeHttpClient();
        $verifier = new TpayNotificationVerifier($http, self::config(true));

        self::assertFalse($verifier->verify(self::BODY, self::jws('https://secure.tpay.com/x509/notifications-jws.pem')));
        self::assertCount(0, $http->calls);
    }

    public function testAcceptedX5uFetchesTheCanonicalUrlThenRootCa(): void
    {
        $config = self::config(true);
        $http = new FakeHttpClient();
        $http->queueResponse(new HttpResponse(200, 'not a cert', []));
        $http->queueResponse(new HttpResponse(200, 'not a cert', []));
        $verifier = new TpayNotificationVerifier($http, $config);

        self::assertFalse($verifier->verify(self::BODY, self::jws('https://secure.sandbox.tpay.com/x509/notifications-jws.pem')));
        self::assertCount(2, $http->calls);
        self::assertSame('https://secure.sandbox.tpay.com/x509/notifications-jws.pem', $http->calls[0]['url']);
        self::assertSame($config->rootCaCertUrl(), $http->calls[1]['url']);
    }

    public function testNonStringX5uIsRejectedWithoutFetching(): void
    {
        $http = new FakeHttpClient();
        $verifier = new TpayNotificationVerifier($http, self::config(true));
        $warnings = [];
        set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
            $warnings[] = $str;
            return true;
        });
        try {
            $result = $verifier->verify(self::BODY, self::jws(['https://secure.sandbox.tpay.com/x509/a.pem']));
        } finally {
            restore_error_handler();
        }

        self::assertFalse($result);
        self::assertCount(0, $http->calls);
        self::assertSame([], $warnings);
    }
}

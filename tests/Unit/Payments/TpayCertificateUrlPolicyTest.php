<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Payments;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayCertificateUrlPolicy;

final class TpayCertificateUrlPolicyTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function acceptedProvider(): array
    {
        return [
            'production leaf' => ['https://secure.tpay.com/x509/notifications-jws.pem', 'secure.tpay.com'],
            'production root' => ['https://secure.tpay.com/x509/tpay-jws-root.pem', 'secure.tpay.com'],
            'sandbox leaf' => ['https://secure.sandbox.tpay.com/x509/notifications-jws.pem', 'secure.sandbox.tpay.com'],
        ];
    }

    #[DataProvider('acceptedProvider')]
    public function testAcceptsCanonicalUrls(string $url, string $host): void
    {
        self::assertSame($url, TpayCertificateUrlPolicy::canonicalize($url, $host));
    }

    /** @return array<string, array{string, string}> */
    public static function rejectedProvider(): array
    {
        $prod = 'secure.tpay.com';
        $cases = [
            'userinfo host trick' => 'https://secure.tpay.com@10.0.0.1/x509/notifications-jws.pem',
            'userinfo with password' => 'https://secure.tpay.com:pw@10.0.0.1/x509/a.pem',
            'userinfo on real host' => 'https://user@secure.tpay.com/x509/a.pem',
            'suffix domain' => 'https://secure.tpay.com.evil.test/x509/notifications-jws.pem',
            'prefix domain' => 'https://evilsecure.tpay.com/x509/a.pem',
            'http scheme' => 'http://secure.tpay.com/x509/notifications-jws.pem',
            'port 443' => 'https://secure.tpay.com:443/x509/a.pem',
            'port 8443' => 'https://secure.tpay.com:8443/x509/a.pem',
            'uppercase scheme' => 'HTTPS://secure.tpay.com/x509/a.pem',
            'uppercase host' => 'https://SECURE.TPAY.COM/x509/a.pem',
            'trailing dot host' => 'https://secure.tpay.com./x509/a.pem',
            'cyrillic a' => "https://secure.tp\u{0430}y.com/x509/a.pem",
            'punycode host' => 'https://xn--secure-tpy-9cb.com/x509/a.pem',
            'query' => 'https://secure.tpay.com/x509/a.pem?x=1',
            'fragment' => 'https://secure.tpay.com/x509/a.pem#f',
            'dot dot' => 'https://secure.tpay.com/x509/../internal.pem',
            'encoded dot dot' => 'https://secure.tpay.com/x509/%2e%2e/a.pem',
            'encoded nul' => 'https://secure.tpay.com/x509/a.pem%00',
            'backslash at' => 'https://secure.tpay.com\\@10.0.0.1/x509/a.pem',
            'double backslash' => 'https://secure.tpay.com\\\\.evil.test/x509/a.pem',
            'scheme relative' => '//secure.tpay.com/x509/a.pem',
            'single slash scheme' => 'https:/secure.tpay.com/x509/a.pem',
            'triple slash' => 'https:///secure.tpay.com/x509/a.pem',
            'double slash path' => 'https://secure.tpay.com//x509/a.pem',
            'other path' => 'https://secure.tpay.com/other/a.pem',
            'wrong extension' => 'https://secure.tpay.com/x509/a.pem.txt',
            'no file' => 'https://secure.tpay.com/x509/',
            'empty stem' => 'https://secure.tpay.com/x509/.pem',
            'trailing newline' => "https://secure.tpay.com/x509/a.pem\n",
            'leading space' => ' https://secure.tpay.com/x509/a.pem',
            'ipv4 host' => 'https://10.0.0.1/x509/a.pem',
            'ipv6 host' => 'https://[::1]/x509/a.pem',
            'sandbox host in production mode' => 'https://secure.sandbox.tpay.com/x509/a.pem',
            'empty' => '',
            'too long' => 'https://secure.tpay.com/x509/' . str_repeat('a', 600) . '.pem',
        ];
        $out = [];
        foreach ($cases as $name => $url) {
            $out[$name] = [$url, $prod];
        }
        $out['production host in sandbox mode'] = ['https://secure.tpay.com/x509/a.pem', 'secure.sandbox.tpay.com'];

        return $out;
    }

    #[DataProvider('rejectedProvider')]
    public function testRejects(string $url, string $host): void
    {
        self::assertNull(TpayCertificateUrlPolicy::canonicalize($url, $host));
    }
}

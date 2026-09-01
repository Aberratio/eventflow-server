<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Payments;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Payments\Http\HttpResponse;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayConfig;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayNotificationVerifier;
use ZmierzymyCzas\Tests\Support\FakeHttpClient;

/**
 * Exercises the real JWS verification mechanism (RFC 7515, RS256, x5u chain to a
 * root CA) against a real, freshly generated RSA key pair and certificate chain —
 * not a mock of the crypto itself, only of the two HTTP fetches (leaf cert, root
 * CA cert) the verifier makes, per the ticket's "mock the HTTP layer, not the
 * integration logic" requirement.
 */
final class TpayNotificationVerifierTest extends TestCase
{
    private const TRUSTED_HOST = 'https://secure.sandbox.tpay.com';
    private const X5U = self::TRUSTED_HOST . '/x509/notifications-jws.pem';

    private TpayConfig $config;

    private string $opensslConfig = '';

    protected function setUp(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('openssl extension not available');
        }
        $this->config = new TpayConfig('id', 'secret', 'merchant', true, 'https://x/n', 'https://x/s', 'https://x/e');

        // Generating a real, throwaway CA + leaf certificate chain (rather than
        // faking the crypto) needs an openssl.cnf; PHP-on-Windows builds don't
        // always have OPENSSL_CONF pointed at one. Probe a few well-known
        // locations and skip (never fail) if this machine genuinely has none.
        $candidates = array_filter([
            getenv('OPENSSL_CONF') ?: null,
            'C:\\xampp\\php\\extras\\openssl\\openssl.cnf',
            'C:\\xampp\\php\\extras\\ssl\\openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
            '/usr/local/etc/openssl/openssl.cnf',
        ]);
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $this->opensslConfig = $candidate;
                break;
            }
        }
        if ($this->opensslConfig === '') {
            self::markTestSkipped('No openssl.cnf found on this machine — cannot generate a test certificate chain.');
        }
    }

    /** @return array{key: \OpenSSLAsymmetricKey, cert: \OpenSSLCertificate, pem: string} */
    private function generateCa(): array
    {
        $opts = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $this->opensslConfig];
        $key = openssl_pkey_new($opts);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'Test Tpay Root CA'], $key, $opts);
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256', 'config' => $this->opensslConfig]);
        self::assertNotFalse($cert);
        openssl_x509_export($cert, $pem);

        return ['key' => $key, 'cert' => $cert, 'pem' => $pem];
    }

    /** @param array{key: \OpenSSLAsymmetricKey, cert: \OpenSSLCertificate, pem: string} $issuer @return array{key: \OpenSSLAsymmetricKey, pem: string} */
    private function generateLeafSignedBy(array $issuer): array
    {
        $opts = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $this->opensslConfig];
        $key = openssl_pkey_new($opts);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'secure.sandbox.tpay.com'], $key, $opts);
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, $issuer['cert'], $issuer['key'], 365, ['digest_alg' => 'sha256', 'config' => $this->opensslConfig]);
        self::assertNotFalse($cert);
        openssl_x509_export($cert, $pem);

        return ['key' => $key, 'pem' => $pem];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @param \OpenSSLAsymmetricKey $signingKey */
    private function buildJws(string $rawBody, string $x5u, $signingKey, string $alg = 'RS256'): string
    {
        $header = $this->base64Url((string) json_encode(['alg' => $alg, 'x5u' => $x5u]));
        $payload = $this->base64Url($rawBody);
        openssl_sign($header . '.' . $payload, $signature, $signingKey, OPENSSL_ALGO_SHA256);

        return $header . '.' . $payload . '.' . $this->base64Url($signature);
    }

    public function testAcceptsAValidSignatureFromACertificateChainingToTheTrustedRoot(): void
    {
        $root = $this->generateCa();
        $leaf = $this->generateLeafSignedBy($root);
        $rawBody = (string) json_encode(['transactionId' => 'TX-1', 'transactionStatus' => 'correct']);
        $jws = $this->buildJws($rawBody, self::X5U, $leaf['key']);

        $http = new FakeHttpClient();
        $http->queueResponse(new HttpResponse(200, $leaf['pem'])); // x5u fetch
        $http->queueResponse(new HttpResponse(200, $root['pem'])); // root CA fetch

        $verifier = new TpayNotificationVerifier($http, $this->config);
        self::assertTrue($verifier->verify($rawBody, $jws));
    }

    public function testRejectsWhenBodyWasTamperedAfterSigning(): void
    {
        $root = $this->generateCa();
        $leaf = $this->generateLeafSignedBy($root);
        $signedBody = (string) json_encode(['transactionId' => 'TX-1', 'transactionStatus' => 'correct']);
        $jws = $this->buildJws($signedBody, self::X5U, $leaf['key']);

        $http = new FakeHttpClient();
        $verifier = new TpayNotificationVerifier($http, $this->config);

        $tamperedBody = (string) json_encode(['transactionId' => 'TX-1', 'transactionStatus' => 'correct', 'transactionAmount' => 999999]);
        self::assertFalse($verifier->verify($tamperedBody, $jws));
        self::assertCount(0, $http->calls, 'a body/payload mismatch must be caught before any certificate is fetched');
    }

    public function testRejectsAnX5uOutsideTheTrustedTpayHostWithoutFetchingAnything(): void
    {
        $root = $this->generateCa();
        $leaf = $this->generateLeafSignedBy($root);
        $rawBody = (string) json_encode(['transactionId' => 'TX-1']);
        $attackerX5u = 'https://attacker.example.com/cert.pem';
        $jws = $this->buildJws($rawBody, $attackerX5u, $leaf['key']);

        $http = new FakeHttpClient();
        $verifier = new TpayNotificationVerifier($http, $this->config);

        self::assertFalse($verifier->verify($rawBody, $jws));
        self::assertCount(0, $http->calls);
    }

    public function testRejectsALeafCertificateNotSignedByTheTrustedRoot(): void
    {
        $realRoot = $this->generateCa();
        $attackerRoot = $this->generateCa();
        $rogueLeaf = $this->generateLeafSignedBy($attackerRoot);
        $rawBody = (string) json_encode(['transactionId' => 'TX-1']);
        $jws = $this->buildJws($rawBody, self::X5U, $rogueLeaf['key']);

        $http = new FakeHttpClient();
        $http->queueResponse(new HttpResponse(200, $rogueLeaf['pem']));
        $http->queueResponse(new HttpResponse(200, $realRoot['pem']));

        $verifier = new TpayNotificationVerifier($http, $this->config);
        self::assertFalse($verifier->verify($rawBody, $jws));
    }

    public function testRejectsAWrongSignatureEvenWithACorrectlyChainedCertificate(): void
    {
        $root = $this->generateCa();
        $leaf = $this->generateLeafSignedBy($root);
        $otherLeaf = $this->generateLeafSignedBy($root);
        $rawBody = (string) json_encode(['transactionId' => 'TX-1']);
        // Signed with a *different* (but still root-chained) key than the one whose
        // certificate is advertised at x5u.
        $jws = $this->buildJws($rawBody, self::X5U, $otherLeaf['key']);

        $http = new FakeHttpClient();
        $http->queueResponse(new HttpResponse(200, $leaf['pem']));
        $http->queueResponse(new HttpResponse(200, $root['pem']));

        $verifier = new TpayNotificationVerifier($http, $this->config);
        self::assertFalse($verifier->verify($rawBody, $jws));
    }

    public function testRejectsMalformedHeaderValue(): void
    {
        $http = new FakeHttpClient();
        $verifier = new TpayNotificationVerifier($http, $this->config);

        self::assertFalse($verifier->verify('{}', 'not-a-jws'));
        self::assertFalse($verifier->verify('{}', ''));
    }
}

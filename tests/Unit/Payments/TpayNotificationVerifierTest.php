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
 * CA cert) the verifier makes, per the "mock the HTTP layer, not the
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

    /**
     * Returns the [tag, headerLen, contentLen, totalLen] of the DER element (TLV)
     * starting at $offset. Only handles the short/long-form length encodings that
     * real X.509 certificates from openssl_csr_sign() actually produce.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function derElement(string $der, int $offset): array
    {
        $tag = ord($der[$offset]);
        $first = ord($der[$offset + 1]);
        if (($first & 0x80) === 0) {
            return [$tag, 2, $first, 2 + $first];
        }
        $numBytes = $first & 0x7F;
        $len = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            $len = ($len << 8) | ord($der[$offset + 2 + $i]);
        }
        return [$tag, 2 + $numBytes, $len, 2 + $numBytes + $len];
    }

    private function derEncodeLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function pemToDer(string $pem): string
    {
        $lines = array_filter(explode("\n", trim($pem)), fn (string $l): bool => !str_contains($l, '-----'));

        return (string) base64_decode(implode('', $lines), true);
    }

    private function derToPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    /**
     * Replaces the (UTCTime-encoded) validFrom/validTo of a leaf certificate with
     * new dates of the identical string length, then re-signs the TBSCertificate
     * with the issuing CA's private key so the result is a real certificate whose
     * signature verifies — the only thing that changed is its validity window.
     *
     * This is how testRejectsANotYetValidCertificate() gets a certificate dated in
     * the future: PHP's openssl_csr_sign() always pins notBefore to "now" with no
     * way to move it forward, so the only way to produce a genuinely-signed,
     * future-dated certificate is to patch the signed bytes and re-sign them
     * ourselves, exactly as a real CA's signing process would have produced.
     *
     * @param array{key: \OpenSSLAsymmetricKey, cert: \OpenSSLCertificate, pem: string} $issuer
     */
    private function withPatchedValidity(string $leafPem, array $issuer, \DateTimeImmutable $notBefore, \DateTimeImmutable $notAfter): string
    {
        $parsed = openssl_x509_parse($leafPem);
        self::assertIsArray($parsed);

        $der = $this->pemToDer($leafPem);
        [, $outerHeaderLen] = $this->derElement($der, 0);
        [, $tbsHeaderLen, $tbsContentLen, $tbsTotalLen] = $this->derElement($der, $outerHeaderLen);
        $tbsElem = substr($der, $outerHeaderLen, $tbsTotalLen);

        $newNotBefore = $notBefore->setTimezone(new \DateTimeZone('UTC'))->format('ymdHis') . 'Z';
        $newNotAfter = $notAfter->setTimezone(new \DateTimeZone('UTC'))->format('ymdHis') . 'Z';
        $tbsElem = $this->patchUtcTime($tbsElem, (string) $parsed['validFrom'], $newNotBefore);
        $tbsElem = $this->patchUtcTime($tbsElem, (string) $parsed['validTo'], $newNotAfter);

        openssl_sign($tbsElem, $newSignature, $issuer['key'], OPENSSL_ALGO_SHA256);

        $sigAlgOffset = $outerHeaderLen + $tbsTotalLen;
        [, , , $sigAlgTotalLen] = $this->derElement($der, $sigAlgOffset);
        $sigAlgElem = substr($der, $sigAlgOffset, $sigAlgTotalLen);

        $sigOffset = $sigAlgOffset + $sigAlgTotalLen;
        [$sigTag] = $this->derElement($der, $sigOffset);

        $newSigContent = "\x00" . $newSignature; // BIT STRING "unused bits" byte + signature
        $newSigElem = chr($sigTag) . $this->derEncodeLength(strlen($newSigContent)) . $newSigContent;

        $newBody = $tbsElem . $sigAlgElem . $newSigElem;
        $newDer = "\x30" . $this->derEncodeLength(strlen($newBody)) . $newBody;

        return $this->derToPem($newDer);
    }

    private function patchUtcTime(string $haystack, string $oldValue, string $newValue): string
    {
        self::assertSame(strlen($oldValue), strlen($newValue), 'replacement UTCTime must keep the same encoded length');
        $needle = "\x17" . chr(strlen($oldValue)) . $oldValue;
        $replacement = "\x17" . chr(strlen($newValue)) . $newValue;
        self::assertSame(1, substr_count($haystack, $needle), 'expected exactly one UTCTime field matching the old value');

        return str_replace($needle, $replacement, $haystack);
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

    public function testRejectsAnAlreadyExpiredCertificate(): void
    {
        $root = $this->generateCa();
        // notBefore = -30 days, notAfter = -5 days: a real, correctly-signed
        // certificate that is simply expired. Built via withPatchedValidity()
        // (the same patch-and-resign path as the not-yet-valid / accept tests)
        // rather than a negative $days straight out of openssl_csr_sign():
        // PHP's OpenSSL binding on CI (8.2.33) returns false for a negative
        // $days there, while a locally installed OpenSSL/PHP build may still
        // accept it — an environment difference in openssl_csr_sign() itself,
        // not in TpayNotificationVerifier under test.
        $leaf = $this->generateLeafSignedBy($root);
        $expiredLeafPem = $this->withPatchedValidity(
            $leaf['pem'],
            $root,
            new \DateTimeImmutable('-30 days'),
            new \DateTimeImmutable('-5 days')
        );

        $rawBody = (string) json_encode(['transactionId' => 'TX-1']);
        // Still signed with the original leaf key — withPatchedValidity() only
        // patches and re-signs the certificate itself, not the JWS.
        $jws = $this->buildJws($rawBody, self::X5U, $leaf['key']);

        $http = new FakeHttpClient();
        $http->queueResponse(new HttpResponse(200, $expiredLeafPem));
        $http->queueResponse(new HttpResponse(200, $root['pem']));

        $verifier = new TpayNotificationVerifier($http, $this->config);
        self::assertFalse($verifier->verify($rawBody, $jws), 'an expired-but-correctly-signed certificate must be rejected');
    }

    public function testRejectsANotYetValidCertificate(): void
    {
        $root = $this->generateCa();
        $leaf = $this->generateLeafSignedBy($root);
        $futureLeafPem = $this->withPatchedValidity(
            $leaf['pem'],
            $root,
            (new \DateTimeImmutable('+30 days')),
            (new \DateTimeImmutable('+395 days'))
        );

        $rawBody = (string) json_encode(['transactionId' => 'TX-1']);
        $jws = $this->buildJws($rawBody, self::X5U, $leaf['key']);

        $http = new FakeHttpClient();
        $http->queueResponse(new HttpResponse(200, $futureLeafPem));
        $http->queueResponse(new HttpResponse(200, $root['pem']));

        $verifier = new TpayNotificationVerifier($http, $this->config);
        self::assertFalse($verifier->verify($rawBody, $jws), 'a not-yet-valid certificate must be rejected even though its signature chain is genuinely valid');
    }

    public function testAcceptsACurrentlyValidCertificateWithAnExplicitValidityWindow(): void
    {
        $root = $this->generateCa();
        $leaf = $this->generateLeafSignedBy($root);
        // Re-sign through the exact same patch-and-resign path as the expiry tests,
        // but with a window that safely brackets "now" — proving the new validity
        // check doesn't just always return false, and that withPatchedValidity()
        // itself produces an accepted certificate when the dates are sane.
        $currentLeafPem = $this->withPatchedValidity(
            $leaf['pem'],
            $root,
            (new \DateTimeImmutable('-1 day')),
            (new \DateTimeImmutable('+1 day'))
        );

        $rawBody = (string) json_encode(['transactionId' => 'TX-1']);
        $jws = $this->buildJws($rawBody, self::X5U, $leaf['key']);

        $http = new FakeHttpClient();
        $http->queueResponse(new HttpResponse(200, $currentLeafPem));
        $http->queueResponse(new HttpResponse(200, $root['pem']));

        $verifier = new TpayNotificationVerifier($http, $this->config);
        self::assertTrue($verifier->verify($rawBody, $jws));
    }

    public function testRejectsMalformedHeaderValue(): void
    {
        $http = new FakeHttpClient();
        $verifier = new TpayNotificationVerifier($http, $this->config);

        self::assertFalse($verifier->verify('{}', 'not-a-jws'));
        self::assertFalse($verifier->verify('{}', ''));
    }
}

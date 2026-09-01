<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments\Tpay;

use ZmierzymyCzas\Domain\Payments\Http\HttpClientInterface;

/**
 * Verifies the `X-JWS-Signature` header Tpay attaches to every webhook notification
 * (RFC 7515 JSON Web Signature), per https://docs-api.tpay.com/en/webhooks/:
 *
 *  1. The header is a compact JWS: base64url(header) . '.' . base64url(payload) . '.' . base64url(signature).
 *     The payload is NOT detached — it is exactly base64url(the raw POST body).
 *  2. The JOSE header carries an `x5u` pointing at the certificate that signed it;
 *     that URL must start with Tpay's own trusted host (secure.tpay.com in
 *     production, secure.sandbox.tpay.com in sandbox — TpayConfig::trustedCertHost())
 *     or the notification is rejected outright, before any network call is made.
 *  3. The certificate fetched from `x5u` must itself chain to Tpay's published root
 *     CA (`{trustedCertHost}/x509/tpay-jws-root.pem`).
 *  4. The signature is verified as RS256 (SHA-256 with RSA) over
 *     `header_segment . '.' . payload_segment` using the leaf certificate's public key.
 *
 * Never trust the notification body until every one of these steps passes — this is
 * the whole point of the ticket's "nigdy nie ufaj nagłówkom/body bez weryfikacji".
 */
final class TpayNotificationVerifier
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly TpayConfig $config
    ) {
    }

    public function verify(string $rawBody, string $jwsHeaderValue): bool
    {
        $segments = explode('.', trim($jwsHeaderValue));
        if (count($segments) !== 3) {
            return false;
        }
        [$headerSegment, $payloadSegment, $signatureSegment] = $segments;

        $header = json_decode((string) $this->base64UrlDecode($headerSegment), true);
        if (!is_array($header)) {
            return false;
        }
        if ((string) ($header['alg'] ?? '') !== 'RS256') {
            return false;
        }
        $x5u = (string) ($header['x5u'] ?? '');
        if ($x5u === '' || !str_starts_with($x5u, $this->config->trustedCertHost())) {
            return false;
        }

        // The payload segment must exactly equal base64url(raw body) — this is what
        // "not detached" means here: the notification's own body IS the signed
        // payload, so anyone tampering with the body without re-signing it would
        // produce a payload segment that no longer matches what we just received.
        if (!hash_equals($this->base64UrlEncode($rawBody), $payloadSegment)) {
            return false;
        }

        $signature = $this->base64UrlDecode($signatureSegment);
        if ($signature === null) {
            return false;
        }

        $leafCertPem = $this->fetchPem($x5u);
        $rootCaCertPem = $this->fetchPem($this->config->rootCaCertUrl());
        if ($leafCertPem === null || $rootCaCertPem === null) {
            return false;
        }

        if (!$this->certificateIsSignedByRoot($leafCertPem, $rootCaCertPem)) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($leafCertPem);
        if ($publicKey === false) {
            return false;
        }

        $signingInput = $headerSegment . '.' . $payloadSegment;
        $result = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    private function certificateIsSignedByRoot(string $leafCertPem, string $rootCaCertPem): bool
    {
        $leaf = openssl_x509_read($leafCertPem);
        $root = openssl_x509_read($rootCaCertPem);
        if ($leaf === false || $root === false) {
            return false;
        }
        $rootPublicKey = openssl_pkey_get_public($root);
        if ($rootPublicKey === false) {
            return false;
        }

        // openssl_x509_verify: 1 = signature verified, 0 = verification failed,
        // -1 = error. Only 1 counts as "this leaf certificate was really issued by
        // Tpay's root CA".
        return openssl_x509_verify($leaf, $rootPublicKey) === 1;
    }

    private function fetchPem(string $url): ?string
    {
        try {
            $response = $this->http->request('GET', $url, ['Accept' => 'application/x-pem-file, */*']);
        } catch (\Throwable) {
            return null;
        }
        if (!$response->isSuccessful() || trim($response->body) === '') {
            return null;
        }

        return $response->body;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);
        return $decoded === false ? null : $decoded;
    }
}

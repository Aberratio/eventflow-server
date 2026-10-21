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
 *     that URL must pass TpayCertificateUrlPolicy (exact https host for the
 *     configured mode — secure.tpay.com in production, secure.sandbox.tpay.com in
 *     sandbox — and path /x509/<file>.pem) or the notification is rejected
 *     outright, before any network call is made. The fetch uses the policy's
 *     rebuilt canonical URL, never the raw header string.
 *  3. The certificate fetched from `x5u` must itself chain to Tpay's published root
 *     CA (`{trustedCertHost}/x509/tpay-jws-root.pem`) AND must currently be within
 *     its validFrom/validTo window — a correctly-chained but expired (or
 *     not-yet-valid) certificate is rejected just the same.
 *  4. The signature is verified as RS256 (SHA-256 with RSA) over
 *     `header_segment . '.' . payload_segment` using the leaf certificate's public key.
 *
 * Order: every check that needs no key (3 segments, header JSON, alg, x5u policy,
 * payload == base64url(body), signature decoding) runs before the first fetch. The
 * signature itself can only be checked after fetching, because the certificate
 * holds the key.
 *
 * Never trust the notification body until every one of these steps passes — this is
 * the whole point of the rule "never trust headers/body without verification".
 */
final class TpayNotificationVerifier
{
    private const MAX_PEM_BYTES = 16384;

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
        $x5u = $header['x5u'] ?? null;
        if (!is_string($x5u)) {
            return false;
        }
        $certUrl = TpayCertificateUrlPolicy::canonicalize($x5u, $this->config->trustedCertHostname());
        if ($certUrl === null) {
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

        $leafCertPem = $this->fetchPem($certUrl);
        $rootCaCertPem = $this->fetchPem($this->config->rootCaCertUrl());
        if ($leafCertPem === null || $rootCaCertPem === null) {
            return false;
        }

        // Chaining to the trusted root and being within its validity period are two
        // separate properties of the leaf certificate — certificateIsSignedByRoot()
        // only proves the former. An expired (or not-yet-valid) certificate can still
        // be correctly signed by the root, so both checks must pass independently.
        if (!$this->certificateIsCurrentlyValid($leafCertPem)) {
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

    /**
     * Rejects an expired or not-yet-valid leaf certificate. A correctly signed
     * certificate that is outside its validFrom/validTo window must not be trusted
     * — this mirrors the `time() < $expiresAt` style check TpayOAuthClient uses for
     * its cached token, just against openssl_x509_parse()'s validFrom_time_t /
     * validTo_time_t instead of an API-supplied expiry.
     *
     * Fails closed: any parse failure or missing/non-integer field is treated as
     * "not valid" rather than being skipped.
     */
    private function certificateIsCurrentlyValid(string $leafCertPem): bool
    {
        $parsed = openssl_x509_parse($leafCertPem, false);
        if ($parsed === false) {
            return false;
        }

        $validFrom = $parsed['validFrom_time_t'] ?? null;
        $validTo = $parsed['validTo_time_t'] ?? null;
        if (!is_int($validFrom) || !is_int($validTo)) {
            return false;
        }

        $now = time();
        return $now >= $validFrom && $now <= $validTo;
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
        if (strlen($response->body) > self::MAX_PEM_BYTES || !str_contains($response->body, '-----BEGIN CERTIFICATE-----')) {
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

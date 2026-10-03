<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments\Tpay;

/**
 * Strict policy for the JWS `x5u` certificate URL of a Tpay webhook notification.
 * The `x5u` header is attacker-controlled until the signature is verified, so it
 * must never reach an outbound HTTP client unless it passes this policy (SSRF).
 *
 * Accepted shape, nothing else: `https://<expected hostname>/x509/<file>.pem` —
 * exact lowercase scheme and host (chosen by TPAY_SANDBOX mode only), no userinfo,
 * no port (not even :443 — Tpay never sends one), no query, no fragment, no
 * percent-encoding, no non-ASCII (IDN homoglyphs are rejected raw, punycode forms
 * by the exact host match). Uppercase scheme/host is rejected on purpose: Tpay
 * sends lowercase and strictness costs nothing.
 *
 * The final step requires the raw string to equal the URL rebuilt from the
 * validated parts, so parse_url()/libcurl parser differentials (`https://a@b`,
 * `https:///host`, backslashes) are structurally impossible. Callers must fetch
 * the returned canonical URL, never the raw header value.
 * Pure: performs no I/O.
 */
final class TpayCertificateUrlPolicy
{
    private const MAX_LENGTH = 512;
    /** Tpay publishes JWS certificates only under /x509/ (e.g. /x509/notifications-jws.pem, /x509/tpay-jws-root.pem). */
    private const PATH_PATTERN = '#^/x509/[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.pem$#D';

    /**
     * Returns the canonical URL to fetch (rebuilt from validated parts), or null
     * when $x5u must be rejected.
     */
    public static function canonicalize(string $x5u, string $expectedHostname): ?string
    {
        if ($x5u === '' || strlen($x5u) > self::MAX_LENGTH) {
            return null;
        }
        // Whitespace, control characters, any non-ASCII byte.
        if (preg_match('/[^\x21-\x7E]/', $x5u) === 1) {
            return null;
        }
        // Userinfo, backslash parser tricks, query, fragment, any percent-encoding.
        if (strpbrk($x5u, '@\?#%') !== false) {
            return null;
        }

        $parts = parse_url($x5u);
        if (!is_array($parts)) {
            return null;
        }
        // Rejects port, user, pass, query and fragment.
        if (array_diff(array_keys($parts), ['scheme', 'host', 'path']) !== []) {
            return null;
        }
        if (($parts['scheme'] ?? null) !== 'https') {
            return null;
        }
        if (($parts['host'] ?? null) !== $expectedHostname) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        if (preg_match(self::PATH_PATTERN, $path) !== 1) {
            return null;
        }

        $canonical = 'https://' . $expectedHostname . $path;

        return hash_equals($canonical, $x5u) ? $canonical : null;
    }
}

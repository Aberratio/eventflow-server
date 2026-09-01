<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments\PayNow;

/**
 * PayNow's request/response signing scheme (https://docs.paynow.pl/docs/v1/integration,
 * "Autoryzacja żądań"): the `Signature` header — on every outbound request this
 * integration makes, and on every webhook notification PayNow sends back — is
 * simply base64(HMAC-SHA256(exact raw JSON body, Signature-Key)). Unlike Tpay's
 * JWS/x5u/root-CA chain (RSA, asymmetric, needs fetching a certificate), this is
 * a single shared-secret HMAC computed entirely locally — no network call is
 * ever needed to verify or produce it.
 */
final class PayNowSignature
{
    public static function compute(string $rawBody, string $signatureKey): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $signatureKey, true));
    }

    /** Constant-time comparison — never `===` a signature against user input. */
    public static function matches(string $rawBody, string $signatureKey, string $providedSignature): bool
    {
        return hash_equals(self::compute($rawBody, $signatureKey), $providedSignature);
    }
}

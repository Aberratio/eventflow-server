<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments\PayNow;

/**
 * Verifies the `Signature` header PayNow attaches to every webhook notification
 * (https://docs.paynow.pl/docs/v1/integration): base64(HMAC-SHA256(exact raw
 * POST body, Signature-Key)), compared in constant time. Simpler than Tpay's
 * RFC 7515 JWS/x5u/root-CA chain (asymmetric, needs two outbound HTTPS fetches
 * to verify) — PayNow's is a single shared-secret HMAC, verifiable entirely
 * locally with no network call at all, per PayNow's own "DO NOT PROCESS that
 * message if the calculated signature doesn't correspond to the value present
 * in the Signature header" instruction.
 *
 * Never trust the notification body until this passes — the whole point of the
 * ticket's "nigdy nie ufaj nagłówkom/body bez weryfikacji".
 */
final class PayNowNotificationVerifier
{
    public function __construct(private readonly PayNowConfig $config)
    {
    }

    public function verify(string $rawBody, string $signatureHeaderValue): bool
    {
        $signatureHeaderValue = trim($signatureHeaderValue);
        if ($signatureHeaderValue === '' || $this->config->signatureKey === '') {
            return false;
        }

        return PayNowSignature::matches($rawBody, $this->config->signatureKey, $signatureHeaderValue);
    }
}

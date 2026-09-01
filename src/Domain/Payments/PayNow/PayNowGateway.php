<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments\PayNow;

use ZmierzymyCzas\Domain\Payments\Http\HttpClientInterface;
use ZmierzymyCzas\Domain\Payments\Http\HttpResponse;
use ZmierzymyCzas\Domain\Payments\PaymentGatewayInterface;

/**
 * Real PayNow v1 Payments API client (https://docs.paynow.pl/docs/v1/payments,
 * https://docs.paynow.pl/docs/reference/v1/send-payment-request,
 * https://docs.paynow.pl/docs/reference/v1/send-refund-request). Every call here
 * is a genuine HTTP request built and parsed against that documented contract;
 * only the credentials (PayNowConfig, from env) are placeholders in dev/test.
 *
 * Auth model differs from Tpay: no OAuth token exchange exists — every request
 * simply carries the merchant's `Api-Key` plus a `Signature` header computed
 * over the exact JSON body (PayNowSignature), and an `Idempotency-Key` PayNow
 * uses to de-duplicate a retried request on their side.
 */
final class PayNowGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly PayNowConfig $config
    ) {
    }

    /**
     * POST {base}/v1/payments. `externalId` carries our own registration id
     * back to us on the notification (`externalId`) — the link between "which
     * Zapis does this webhook belong to" that doesn't rely on trusting
     * anything else in the payload before the signature is verified.
     *
     * PayNow's amount is an integer in the smallest currency unit (grosze for
     * PLN, per the docs' "1.00 PLN and 1,000,000.00 PLN" range note) — unlike
     * Tpay's decimal PLN float, so `$amount` (always the frozen Rozliczenie
     * total in PLN) is converted here, once, at the API boundary.
     *
     * @return array{transactionId: string, redirectUrl: string}
     */
    public function startSession(
        string $registrationId,
        float $amount,
        string $description,
        string $payerEmail,
        string $payerName
    ): array {
        $payload = [
            'amount' => self::toGrosze($amount),
            'externalId' => $registrationId,
            'description' => $description,
            'buyer' => [
                'email' => $payerEmail,
                // PayNow has no single "full name" field, only optional
                // firstName/lastName (≤50 chars each) — best-effort split of
                // whatever single name string the caller passed in. Not a
                // faithful parse of every name format; fine for the payer
                // identification PayNow displays, never used for anything
                // that needs to be exact.
                ...self::splitName($payerName),
            ],
            'continueUrl' => $this->config->continueUrl,
        ];

        $response = $this->request('POST', '/v1/payments', $payload);

        if (!$response->isSuccessful()) {
            throw new \RuntimeException('paynow_start_session_failed: HTTP ' . $response->status . ' ' . $response->body);
        }

        $decoded = $response->json();
        $transactionId = (string) ($decoded['paymentId'] ?? '');
        $redirectUrl = (string) ($decoded['redirectUrl'] ?? '');
        if ($transactionId === '' || $redirectUrl === '') {
            throw new \RuntimeException('paynow_start_session_malformed_response');
        }

        return ['transactionId' => $transactionId, 'redirectUrl' => $redirectUrl];
    }

    /**
     * POST {base}/v1/payments/{paymentId}/refunds — the real gateway-side
     * refund call that RegistrationRepository::initiateGatewayRefund() was left
     * as an empty seam for (E1.5). PayNow confirms the refund asynchronously
     * (its own `refundId`/status lifecycle, separate from the payment
     * notification), which is out of this method's job — it only starts the
     * refund, it does not wait for or apply its outcome.
     */
    public function refund(string $transactionId, float $amount): void
    {
        $payload = ['amount' => self::toGrosze($amount)];

        $response = $this->request('POST', '/v1/payments/' . rawurlencode($transactionId) . '/refunds', $payload);

        if (!$response->isSuccessful()) {
            throw new \RuntimeException('paynow_refund_failed: HTTP ' . $response->status . ' ' . $response->body);
        }
    }

    /** @param array<string, mixed> $payload */
    private function request(string $method, string $path, array $payload): HttpResponse
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->http->request($method, $this->config->apiBaseUrl() . $path, [
            'Api-Key' => $this->config->apiKey,
            'Signature' => PayNowSignature::compute($body, $this->config->signatureKey),
            'Idempotency-Key' => bin2hex(random_bytes(16)),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $body);
    }

    /** PLN (float) -> grosze (int), rounded to the nearest cent first so float noise never leaks in. */
    private static function toGrosze(float $amountInPln): int
    {
        return (int) round($amountInPln * 100);
    }

    /** @return array{firstName?: string, lastName?: string} */
    private static function splitName(string $payerName): array
    {
        $trimmed = trim($payerName);
        if ($trimmed === '') {
            return [];
        }
        $parts = preg_split('/\s+/', $trimmed, 2) ?: [$trimmed];
        return isset($parts[1])
            ? ['firstName' => $parts[0], 'lastName' => $parts[1]]
            : ['firstName' => $parts[0]];
    }
}

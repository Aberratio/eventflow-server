<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments\Tpay;

use ZmierzymyCzas\Domain\Payments\Http\HttpClientInterface;
use ZmierzymyCzas\Domain\Payments\PaymentGatewayInterface;

/**
 * Real Tpay Transactions API client (https://docs-api.tpay.com/, transaction
 * request/response shapes per Tpay's own tpay-openapi-php SDK examples —
 * TransactionsApi/CardGateExtended.php). Every call here is a genuine HTTP
 * request built and parsed against that documented contract; only the
 * credentials (TpayConfig, from env) are placeholders in dev/test.
 */
final class TpayGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly TpayConfig $config,
        private readonly TpayOAuthClient $oauth
    ) {
    }

    /**
     * POST {base}/transactions. `hiddenDescription` carries our own registration
     * id back to us on the notification (`transactionHiddenDescription`) — the
     * link between "which Zapis does this webhook belong to" that doesn't rely on
     * trusting anything else in the payload before the signature is verified.
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
            'amount' => round($amount, 2),
            'description' => $description,
            'hiddenDescription' => $registrationId,
            'payer' => [
                'email' => $payerEmail,
                'name' => $payerName,
            ],
            'lang' => 'pl',
            'callbacks' => [
                'notification' => [
                    'url' => $this->config->notificationUrl,
                ],
                'payerUrls' => [
                    'success' => $this->config->successUrl,
                    'error' => $this->config->errorUrl,
                ],
            ],
        ];

        $response = $this->http->request(
            'POST',
            $this->config->apiBaseUrl() . '/transactions',
            [
                'Authorization' => 'Bearer ' . $this->oauth->accessToken(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Merchant-Id' => $this->config->merchantId,
            ],
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if (!$response->isSuccessful()) {
            throw new \RuntimeException('tpay_start_session_failed: HTTP ' . $response->status . ' ' . $response->body);
        }

        $decoded = $response->json();
        $transactionId = (string) ($decoded['transactionId'] ?? '');
        $redirectUrl = (string) ($decoded['transactionPaymentUrl'] ?? '');
        if ($transactionId === '' || $redirectUrl === '') {
            throw new \RuntimeException('tpay_start_session_malformed_response');
        }

        return ['transactionId' => $transactionId, 'redirectUrl' => $redirectUrl];
    }

    /**
     * POST {base}/transactions/{transactionId}/refunds — the real gateway-side
     * refund call that RegistrationRepository::initiateGatewayRefund() was left as
     * an empty seam for. Tpay confirms the refund asynchronously via the
     * same notification webhook as a payment (a `chargeback`/refund status), which
     * is out of this method's job — it only starts the refund, it does not wait
     * for or apply its outcome.
     */
    public function refund(string $transactionId, float $amount): void
    {
        $payload = ['amount' => round($amount, 2)];

        $response = $this->http->request(
            'POST',
            $this->config->apiBaseUrl() . '/transactions/' . rawurlencode($transactionId) . '/refunds',
            [
                'Authorization' => 'Bearer ' . $this->oauth->accessToken(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if (!$response->isSuccessful()) {
            throw new \RuntimeException('tpay_refund_failed: HTTP ' . $response->status . ' ' . $response->body);
        }
    }
}

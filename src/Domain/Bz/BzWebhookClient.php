<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Bz;

use ZmierzymyCzas\Domain\Bz\Http\HttpClientInterface;

/**
 * Real HTTP client for BZ's critical-change webhook — every call here is a
 * genuine outbound POST; only the destination (BzWebhookConfig::baseUrl, from
 * env) is a deployment setting (see BzWebhookClientInterface's doc comment).
 *
 * Payload discipline (issue 12 §5, "bez kwot"): this class never adds anything
 * to the payload beyond what BzWebhookOutboxService already built — no
 * settlement amounts, no prices, ever. It is a pure transport, not a place that
 * decides what data to send.
 */
final class BzWebhookClient implements BzWebhookClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly BzWebhookConfig $config,
    ) {
    }

    public function push(array $payload, string $organizationToken, string $idempotencyKey): void
    {
        $headers = [
            'X-BZ-Organization-Token' => $organizationToken,
            'Idempotency-Key' => $idempotencyKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $response = $this->http->request(
            'POST',
            $this->config->baseUrl . '/webhooks/critical-changes',
            $headers,
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if (!$response->isSuccessful()) {
            throw new \RuntimeException('bz_webhook_push_failed: HTTP ' . $response->status . ' ' . $response->body);
        }
    }
}

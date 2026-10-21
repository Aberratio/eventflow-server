<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CentralMailer;

use ZmierzymyCzas\Domain\Errors\Internal;
use ZmierzymyCzas\Domain\Mailer\Http\HttpClientInterface;
use ZmierzymyCzas\Domain\Mailer\Http\HttpResponse;

/**
 * Real Central Mailer client-zone API client,
 * built against its reverse-engineered contract — every call here is
 * a genuine HTTP request to Central Mailer's `/emails*` endpoints; only the
 * credentials (CentralMailerConfig, from env) are placeholders in dev/test.
 *
 * Scope: single-message enqueue plus the two read endpoints needed for
 * reconciliation. `/emails/batch` is deliberately not implemented (out of scope).
 */
final class CentralMailerClient implements CentralMailerClientInterface
{
    private const VALID_PRIORITIES = ['normal', 'high', 'technical'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CentralMailerConfig $config,
    ) {
    }

    public function enqueue(array $email, ?string $idempotencyKey = null): array
    {
        $priority = (string) ($email['priority'] ?? 'normal');
        if (!in_array($priority, self::VALID_PRIORITIES, true)) {
            throw new \InvalidArgumentException('central_mailer_invalid_priority: ' . $priority);
        }

        $payload = [
            'to' => (string) $email['to'],
            'subject' => (string) $email['subject'],
            'html' => (string) $email['html'],
            'priority' => $priority,
        ];

        if (isset($email['text']) && $email['text'] !== null) {
            $payload['text'] = (string) $email['text'];
        }

        // Category is forced to `transactional` server-side for
        // priority=technical regardless of what is sent — made explicit here too
        // so the outbox records exactly what will actually apply.
        $category = $email['category'] ?? null;
        if ($priority === 'technical') {
            $category = 'transactional';
        }
        if ($category !== null) {
            $payload['category'] = (string) $category;
        }

        if (!empty($email['contextId'])) {
            $payload['contextId'] = (string) $email['contextId'];
        }
        if (!empty($email['metadata'])) {
            $payload['metadata'] = $email['metadata'];
        }
        if (!empty($email['attachments'])) {
            $payload['attachments'] = $email['attachments'];
        }
        // Per-message Reply-To; Central Mailer falls back to its global one when absent.
        $replyToEmail = trim((string) ($email['replyTo']['email'] ?? ''));
        if ($replyToEmail !== '') {
            $payload['replyTo'] = ['email' => $replyToEmail];
            $replyToName = trim((string) ($email['replyTo']['name'] ?? ''));
            if ($replyToName !== '') {
                $payload['replyTo']['name'] = $replyToName;
            }
        }

        $headers = [
            'X-API-Key' => $this->config->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->http->request(
            'POST',
            $this->config->baseUrl . '/emails',
            $headers,
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->throwOnClientErrors($response);

        if (!$response->isSuccessful()) {
            throw new \RuntimeException('central_mailer_enqueue_failed: HTTP ' . $response->status . ' ' . $response->body);
        }

        $decoded = $response->json();
        $id = (string) ($decoded['id'] ?? '');
        $status = (string) ($decoded['status'] ?? '');
        if ($id === '') {
            throw new Internal('central_mailer_enqueue_malformed_response');
        }

        return ['id' => $id, 'status' => $status];
    }

    public function getStatus(string $centralMailerEmailId): array
    {
        $response = $this->http->request('GET', $this->config->baseUrl . '/emails/' . rawurlencode($centralMailerEmailId), [
            'X-API-Key' => $this->config->apiKey,
            'Accept' => 'application/json',
        ]);

        if (!$response->isSuccessful()) {
            throw new \RuntimeException('central_mailer_get_status_failed: HTTP ' . $response->status . ' ' . $response->body);
        }

        return $response->json();
    }

    public function getStatusByContext(string $contextId, int $limit = 500, int $offset = 0): array
    {
        $query = http_build_query(['limit' => $limit, 'offset' => $offset]);

        $response = $this->http->request(
            'GET',
            $this->config->baseUrl . '/emails/context/' . rawurlencode($contextId) . '?' . $query,
            [
                'X-API-Key' => $this->config->apiKey,
                'Accept' => 'application/json',
            ]
        );

        if (!$response->isSuccessful()) {
            throw new \RuntimeException('central_mailer_get_status_by_context_failed: HTTP ' . $response->status . ' ' . $response->body);
        }

        return $response->json();
    }

    private function throwOnClientErrors(HttpResponse $response): void
    {
        if ($response->status === 409) {
            throw new IdempotencyConflictException('central_mailer_idempotency_conflict: ' . $response->body);
        }
        if ($response->status === 422) {
            throw new RecipientSuppressedException('central_mailer_recipient_suppressed: ' . $response->body);
        }
        if ($response->status === 429) {
            throw new RateLimitedException('central_mailer_rate_limited: HTTP 429', self::retryAfterSeconds($response->headers));
        }
        if ($response->status === 400 || $response->status === 413) {
            throw new CentralMailerRejectedException('central_mailer_rejected: HTTP ' . $response->status . ' ' . mb_substr($response->body, 0, 300));
        }
    }

    /**
     * Seconds from a Retry-After header (delta-seconds or HTTP-date); header names are
     * matched case-insensitively. Null when missing or unparseable.
     *
     * @param array<string, string> $headers
     */
    private static function retryAfterSeconds(array $headers): ?int
    {
        $value = null;
        foreach ($headers as $name => $headerValue) {
            if (strtolower((string) $name) === 'retry-after') {
                $value = trim((string) $headerValue);
                break;
            }
        }
        if ($value === null || $value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }
}

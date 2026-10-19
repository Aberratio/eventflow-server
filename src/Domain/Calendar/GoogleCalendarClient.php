<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Calendar;

use Firebase\JWT\JWT;
use ZmierzymyCzas\Domain\Bz\Http\HttpClientInterface;

/**
 * Real HTTP client: OAuth2 JWT bearer grant for a service account (RS256), then the Calendar API.
 * Never logs or returns the key, the assertion or the access token.
 */
final class GoogleCalendarClient implements GoogleCalendarClientInterface
{
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.events';
    public const API_BASE = 'https://www.googleapis.com/calendar/v3';

    private ?string $accessToken = null;
    private int $accessTokenExpiresAt = 0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly GoogleCalendarConfig $config,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function createEvent(string $calendarId, array $event): string
    {
        $response = $this->api('POST', $this->eventsUrl($calendarId), $event);
        $id = (string) ($response['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('google_calendar_missing_event_id');
        }

        return $id;
    }

    public function updateEvent(string $calendarId, string $googleEventId, array $event): void
    {
        $this->api('PUT', $this->eventsUrl($calendarId) . '/' . rawurlencode($googleEventId), $event);
    }

    public function deleteEvent(string $calendarId, string $googleEventId): void
    {
        // 404 / 410: the entry is already gone (removed by hand or by an earlier attempt) - the goal is reached.
        $this->api('DELETE', $this->eventsUrl($calendarId) . '/' . rawurlencode($googleEventId), null, [404, 410]);
    }

    private function eventsUrl(string $calendarId): string
    {
        return self::API_BASE . '/calendars/' . rawurlencode($calendarId) . '/events';
    }

    /**
     * @param array<string, mixed>|null $body
     * @param list<int> $acceptedStatuses non-2xx statuses that are not an error for this call
     * @return array<string, mixed>
     */
    private function api(string $method, string $url, ?array $body, array $acceptedStatuses = []): array
    {
        $response = $this->http->request($method, $url, [
            'Authorization' => 'Bearer ' . $this->token(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $body !== null ? (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null);
        if (in_array($response->status, $acceptedStatuses, true)) {
            return [];
        }
        if (!$response->isSuccessful()) {
            throw new \RuntimeException('google_calendar_request_failed: HTTP ' . $response->status . ' ' . substr($response->body, 0, 300));
        }
        $decoded = json_decode($response->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function token(): string
    {
        $now = $this->clock !== null ? (int) ($this->clock)() : time();
        if ($this->accessToken !== null && $now < $this->accessTokenExpiresAt - 60) {
            return $this->accessToken;
        }

        $assertion = JWT::encode([
            'iss' => $this->config->clientEmail,
            'scope' => self::SCOPE,
            'aud' => $this->config->tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $this->config->privateKey, 'RS256');

        $response = $this->http->request('POST', $this->config->tokenUri, [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]));
        if (!$response->isSuccessful()) {
            throw new \RuntimeException('google_calendar_token_failed: HTTP ' . $response->status);
        }
        $data = json_decode($response->body, true);
        $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
        if ($token === '') {
            throw new \RuntimeException('google_calendar_token_missing');
        }
        $this->accessToken = $token;
        $this->accessTokenExpiresAt = $now + (int) ($data['expires_in'] ?? 3600);

        return $token;
    }
}

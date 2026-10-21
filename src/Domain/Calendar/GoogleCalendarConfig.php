<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Calendar;

use ZmierzymyCzas\Support\Env;

/**
 * The Google service-account key. The ONLY accepted form is a path to the key's JSON file
 * (GOOGLE_CALENDAR_SERVICE_ACCOUNT_FILE) kept outside the web root — never in the database, never in an
 * API response. Without a readable, well-formed key the integration is inactive and nothing is sent.
 */
final class GoogleCalendarConfig
{
    public const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    public function __construct(
        public readonly string $clientEmail,
        public readonly string $privateKey,
        public readonly string $tokenUri = self::DEFAULT_TOKEN_URI,
    ) {
    }

    public static function fromEnv(): ?self
    {
        $path = trim((string) Env::get('GOOGLE_CALENDAR_SERVICE_ACCOUNT_FILE', ''));
        if ($path === '' || !is_readable($path)) {
            return null;
        }

        return self::fromJson((string) file_get_contents($path));
    }

    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $email = trim((string) ($data['client_email'] ?? ''));
        $key = (string) ($data['private_key'] ?? '');
        if ($email === '' || $key === '') {
            return null;
        }
        $tokenUri = trim((string) ($data['token_uri'] ?? ''));

        return new self($email, $key, $tokenUri !== '' ? $tokenUri : self::DEFAULT_TOKEN_URI);
    }
}

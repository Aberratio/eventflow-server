<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Config;

use ZmierzymyCzas\Support\Env;

/**
 * Validated, immutable application configuration.
 *
 * {@see fromEnvironment()} is the boot guard: it parses AND validates raw
 * environment values and throws {@see ConfigurationException} listing every
 * violation at once, so the application never boots with an insecure
 * configuration (no JWT secret fallback, no default wildcard CORS, no debug
 * mode or payment sandbox left on in production).
 */
final class AppConfig
{
    public const ENV_LOCAL = 'local';
    public const ENV_STAGING = 'staging';
    public const ENV_PRODUCTION = 'production';

    public const KEYS = [
        'APP_ENV',
        'APP_DEBUG',
        'APP_CORS_ORIGIN',
        'JWT_SECRET',
        'JWT_TTL_SECONDS',
        'TPAY_SANDBOX',
        'PAYNOW_SANDBOX',
    ];

    public const MIN_JWT_SECRET_BYTES = 32;

    /**
     * Same list as .env.example today — used only when APP_ENV=local and
     * APP_CORS_ORIGIN is unset.
     *
     * @var list<string>
     */
    public const LOCAL_DEV_ORIGINS = [
        'http://localhost:8080',
        'http://127.0.0.1:8080',
        'http://[::1]:8080',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://[::1]:5173',
    ];

    /**
     * Refused in staging/production (exact match, plus any value starting
     * with "change-me", case-insensitive).
     *
     * @var list<string>
     */
    private const PLACEHOLDER_SECRETS = [
        'change-me-please-32-bytes-min!!!',
        'change-me-in-production-min-32-bytes',
    ];

    /**
     * @param list<string> $corsOrigins either ['*'] or a list of explicit origins
     */
    private function __construct(
        public readonly string $appEnv,
        public readonly bool $debug,
        public readonly string $jwtSecret,
        public readonly int $jwtTtlSeconds,
        public readonly array $corsOrigins,
        public readonly bool $tpaySandbox,
        public readonly bool $paynowSandbox,
    ) {
    }

    /**
     * @param array<string, string|null> $env raw values; missing key, null and '' all mean "unset"
     * @throws ConfigurationException listing every violation
     */
    public static function fromEnvironment(array $env): self
    {
        $errors = [];

        $rawAppEnv = self::str($env, 'APP_ENV');
        $appEnv = $rawAppEnv ?? self::ENV_PRODUCTION;
        $validEnvs = [self::ENV_LOCAL, self::ENV_STAGING, self::ENV_PRODUCTION];
        if (!in_array($appEnv, $validEnvs, true)) {
            $errors[] = sprintf(
                "APP_ENV must be one of local, staging, production (got '%s').",
                $appEnv
            );
            // Apply the strictest (production) rules for the remaining checks.
            $appEnv = self::ENV_PRODUCTION;
        }
        $isLocal = $appEnv === self::ENV_LOCAL;
        $isProduction = $appEnv === self::ENV_PRODUCTION;

        $debug = self::parseBool($env, 'APP_DEBUG', false, $errors);
        $tpaySandbox = self::parseBool($env, 'TPAY_SANDBOX', true, $errors);
        $paynowSandbox = self::parseBool($env, 'PAYNOW_SANDBOX', true, $errors);

        $jwtSecret = self::str($env, 'JWT_SECRET');
        if ($jwtSecret === null || $jwtSecret === '') {
            $errors[] = 'JWT_SECRET is not set. Generate one with: php -r "echo bin2hex(random_bytes(32));"';
            $jwtSecret = '';
        } else {
            $length = strlen($jwtSecret);
            if ($length < self::MIN_JWT_SECRET_BYTES) {
                $errors[] = sprintf('JWT_SECRET must be at least %d bytes (got %d).', self::MIN_JWT_SECRET_BYTES, $length);
            } elseif (!$isLocal && self::isPlaceholderSecret($jwtSecret)) {
                $errors[] = sprintf('JWT_SECRET is a placeholder value; generate a real secret for APP_ENV=%s.', $appEnv);
            }
        }

        $jwtTtlSeconds = self::parsePositiveInt($env, 'JWT_TTL_SECONDS', 86400, $errors);

        $corsOrigins = self::parseCorsOrigins($env, $appEnv, $isLocal, $isProduction, $errors);

        if ($isProduction) {
            if ($debug) {
                $errors[] = 'APP_DEBUG=true is not allowed when APP_ENV=production.';
            }
            if ($tpaySandbox) {
                $errors[] = 'TPAY_SANDBOX must be explicitly false when APP_ENV=production (unset defaults to true).';
            }
            if ($paynowSandbox) {
                $errors[] = 'PAYNOW_SANDBOX must be explicitly false when APP_ENV=production (unset defaults to true).';
            }
        }

        if ($errors !== []) {
            throw new ConfigurationException($errors);
        }

        return new self(
            appEnv: $appEnv,
            debug: $debug,
            jwtSecret: $jwtSecret,
            jwtTtlSeconds: $jwtTtlSeconds,
            corsOrigins: $corsOrigins,
            tpaySandbox: $tpaySandbox,
            paynowSandbox: $paynowSandbox,
        );
    }

    /** Reads self::KEYS through Env::get() (so .env precedence stays exactly as today) and delegates. */
    public static function fromProcessEnv(): self
    {
        $env = [];
        foreach (self::KEYS as $key) {
            $v = Env::get($key);
            $env[$key] = $v === null ? null : (string) $v;
        }

        return self::fromEnvironment($env);
    }

    public function isProduction(): bool
    {
        return $this->appEnv === self::ENV_PRODUCTION;
    }

    /** For CorsMiddleware's current string constructor. */
    public function corsOriginsHeaderValue(): string
    {
        return implode(',', $this->corsOrigins);
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function str(array $env, string $key): ?string
    {
        $value = $env[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, string|null> $env
     * @param list<string> $errors
     */
    private static function parseBool(array $env, string $key, bool $default, array &$errors): bool
    {
        $raw = self::str($env, $key);
        if ($raw === null) {
            return $default;
        }

        $parsed = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            $errors[] = sprintf('%s must be a boolean (true/false).', $key);
            return $default;
        }

        return $parsed;
    }

    /**
     * @param array<string, string|null> $env
     * @param list<string> $errors
     */
    private static function parsePositiveInt(array $env, string $key, int $default, array &$errors): int
    {
        $raw = self::str($env, $key);
        if ($raw === null) {
            return $default;
        }

        if (!ctype_digit($raw) || (int) $raw <= 0) {
            $errors[] = sprintf('%s must be a positive integer.', $key);
            return $default;
        }

        return (int) $raw;
    }

    private static function isPlaceholderSecret(string $secret): bool
    {
        foreach (self::PLACEHOLDER_SECRETS as $placeholder) {
            if (strcasecmp($secret, $placeholder) === 0) {
                return true;
            }
        }

        return stripos($secret, 'change-me') === 0;
    }

    /**
     * @param array<string, string|null> $env
     * @param list<string> $errors
     * @return list<string>
     */
    private static function parseCorsOrigins(array $env, string $appEnv, bool $isLocal, bool $isProduction, array &$errors): array
    {
        $raw = self::str($env, 'APP_CORS_ORIGIN');

        if ($raw === null) {
            if ($isLocal) {
                return self::LOCAL_DEV_ORIGINS;
            }

            $errors[] = sprintf('APP_CORS_ORIGIN is required when APP_ENV=%s (comma-separated list of front-end origins).', $appEnv);
            return [];
        }

        $entries = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $e): bool => $e !== ''));

        if (in_array('*', $entries, true)) {
            if (!$isLocal) {
                $errors[] = sprintf("APP_CORS_ORIGIN must not contain '*' when APP_ENV=%s.", $appEnv);
                return [];
            }

            return ['*'];
        }

        $originPattern = '~^https?://[^/\s?#]+$~';
        $valid = [];
        foreach ($entries as $entry) {
            if (!preg_match($originPattern, $entry)) {
                $errors[] = sprintf(
                    "APP_CORS_ORIGIN entry '%s' is not a valid origin (expected scheme://host[:port], no path or trailing slash).",
                    $entry
                );
                continue;
            }

            if ($isProduction && !str_starts_with($entry, 'https://')) {
                $errors[] = sprintf("APP_CORS_ORIGIN entry '%s' must use https when APP_ENV=production.", $entry);
                continue;
            }

            $valid[] = $entry;
        }

        return $valid;
    }
}

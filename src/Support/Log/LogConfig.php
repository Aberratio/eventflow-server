<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support\Log;

use Monolog\Level;
use ZmierzymyCzas\Support\Env;

/**
 * PP.20: lenient logging configuration. Never throws: invalid values fall back to the
 * default and add a warning (key names only, never values) that the factory logs once.
 */
final class LogConfig
{
    private const KEYS = ['APP_ENV', 'LOG_DIR', 'LOG_LEVEL', 'LOG_MAX_FILES', 'ALERT_EMAIL', 'ALERT_THROTTLE_SECONDS'];

    /** @param list<string> $warnings */
    private function __construct(
        public readonly string $appEnv,
        public readonly ?string $logDir,
        public readonly Level $level,
        public readonly int $maxFiles,
        public readonly ?string $alertEmail,
        public readonly int $alertThrottleSeconds,
        public readonly array $warnings,
    ) {
    }

    public static function fromEnv(): self
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $value = Env::get($key);
            if ($value !== null) {
                $values[$key] = (string) $value;
            }
        }

        return self::fromArray($values);
    }

    /** @param array<string, string> $env */
    public static function fromArray(array $env): self
    {
        $warnings = [];
        $get = static fn (string $key): ?string => isset($env[$key]) && trim($env[$key]) !== '' ? trim($env[$key]) : null;

        $appEnv = $get('APP_ENV') ?? 'production';

        $logDir = $get('LOG_DIR');
        $apiRoot = dirname(__DIR__, 3);
        if ($logDir === null) {
            $logDir = $appEnv === 'local' ? null : $apiRoot . '/storage/logs';
        } elseif (!self::isAbsolute($logDir)) {
            $logDir = $apiRoot . '/' . $logDir;
        }

        $level = Level::Info;
        $rawLevel = $get('LOG_LEVEL');
        if ($rawLevel !== null) {
            $map = ['debug' => Level::Debug, 'info' => Level::Info, 'notice' => Level::Notice, 'warning' => Level::Warning, 'error' => Level::Error];
            if (isset($map[strtolower($rawLevel)])) {
                $level = $map[strtolower($rawLevel)];
            } else {
                $warnings[] = 'LOG_LEVEL is invalid, using info';
            }
        }

        $maxFiles = self::intInRange($get('LOG_MAX_FILES'), 1, 365, 30, 'LOG_MAX_FILES', $warnings);
        $throttle = self::intInRange($get('ALERT_THROTTLE_SECONDS'), 60, 86400, 900, 'ALERT_THROTTLE_SECONDS', $warnings);

        $alertEmail = $get('ALERT_EMAIL');
        if ($alertEmail !== null && filter_var($alertEmail, FILTER_VALIDATE_EMAIL) === false) {
            $warnings[] = 'ALERT_EMAIL is not a valid address, alerts are off';
            $alertEmail = null;
        }

        return new self($appEnv, $logDir, $level, $maxFiles, $alertEmail, $throttle, $warnings);
    }

    /** @param list<string> $warnings */
    private static function intInRange(?string $raw, int $min, int $max, int $default, string $key, array &$warnings): int
    {
        if ($raw === null) {
            return $default;
        }
        if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < $min || (int) $raw > $max) {
            $warnings[] = $key . ' is invalid (expected ' . $min . '-' . $max . '), using ' . $default;

            return $default;
        }

        return (int) $raw;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}

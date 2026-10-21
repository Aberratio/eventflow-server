<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support\Log;

use Throwable;

/**
 * The single PII/secret redaction rule set for everything that is logged or alerted.
 *
 * Order: C (Throwable to summary) then A (key denylist) then B (string scrubbing).
 * Call sites must still never put request bodies/headers/query strings into a log context;
 * this class is the safety net under that rule, not a replacement for it.
 */
final class PiiRedactor
{
    public const REDACTED = '[redacted]';

    private const MAX_DEPTH = 8;
    private const MAX_TRACE_FRAMES = 15;
    private const MAX_PREVIOUS_DEPTH = 3;

    /** Normalized (lowercase, no `_`/`-`/space) keys redacted on exact match. */
    private const EXACT_KEYS = [
        'email', 'useremail', 'contactemail', 'phone', 'phonenumber', 'telephone', 'mobile',
        'firstname', 'lastname', 'name', 'username', 'fullname', 'birthdate', 'dateofbirth', 'dob',
        'pesel', 'address', 'street', 'city', 'postalcode', 'zip', 'iban', 'accountnumber', 'card',
        'cardnumber', 'payload', 'body', 'rawbody', 'headers', 'cookie', 'cookies', 'fields', 'value',
        'incomingvalue', 'currentvalue', 'old', 'new', 'reason', 'description',
    ];

    /** Normalized keys redacted when they merely contain one of these. */
    private const KEY_SUBSTRINGS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'signature', 'apikey', 'jws', 'jwt',
        'cookie', 'email', 'phone', 'birth',
    ];

    /**
     * Full rule set for one context/extra array.
     *
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    public static function redactContext(array $context): array
    {
        return self::redactArray($context, 0);
    }

    public static function scrubString(string $value): string
    {
        $patterns = [
            // 1. JWT-shaped
            ['/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/', '[jwt]'],
            // 2. Bearer
            ['/\bBearer\s+\S+/i', 'Bearer [redacted]'],
            // 3. e-mail
            ['/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]'],
            // 4. SQL literal leaks
            ["/Duplicate entry '.*?' for key/s", "Duplicate entry '[redacted]' for key"],
            ["/(Incorrect \\w+ value|Data too long for column|value): '.*?'/s", "\$1: '[redacted]'"],
            // 5. PESEL
            ['/(?<![\w-])\d{11}(?![\w-])/', '[pesel]'],
            // 6. phone
            ['/(?<![\w-])(?:\+?48[ -]?)?\d{3}[ -]?\d{3}[ -]?\d{3}(?![\w-])/', '[phone]'],
            // 7. key=value secrets
            ['/\b(password|token|secret|api[_-]?key|signature)=([^\s&,;]+)/i', '$1=[redacted]'],
        ];

        foreach ($patterns as [$pattern, $replacement]) {
            $result = preg_replace($pattern, $replacement, $value);
            if ($result !== null) {
                $value = $result;
            }
        }

        return $value;
    }

    /**
     * Class, scrubbed message, code, relative file:line and an argument-free trace.
     * Never uses getTraceAsString(), which can embed argument values.
     *
     * @return array<string, mixed>
     */
    public static function summarizeThrowable(Throwable $e, int $depth = 0): array
    {
        $trace = [];
        foreach (array_slice($e->getTrace(), 0, self::MAX_TRACE_FRAMES) as $frame) {
            $where = isset($frame['file']) ? self::relativePath($frame['file']) . ':' . ($frame['line'] ?? 0) : '[internal]';
            $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '()';
            $trace[] = $where . ' ' . $call;
        }

        $summary = [
            'class' => $e::class,
            'message' => self::scrubString($e->getMessage()),
            'code' => $e->getCode(),
            'file' => self::relativePath($e->getFile()) . ':' . $e->getLine(),
            'trace' => $trace,
        ];

        $previous = $e->getPrevious();
        if ($previous !== null && $depth < self::MAX_PREVIOUS_DEPTH) {
            $summary['previous'] = self::summarizeThrowable($previous, $depth + 1);
        }

        return $summary;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function redactArray(array $data, int $depth): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $out[$key] = self::REDACTED;
                continue;
            }

            $out[$key] = self::redactValue($value, $depth + 1);
        }

        return $out;
    }

    private static function redactValue(mixed $value, int $depth): mixed
    {
        if ($value instanceof Throwable) {
            // C, then A and B over the summary.
            return self::redactArray(self::summarizeThrowable($value), $depth);
        }
        if (is_array($value)) {
            return $depth > self::MAX_DEPTH ? self::REDACTED : self::redactArray($value, $depth);
        }
        if (is_string($value)) {
            return self::scrubString($value);
        }
        if (is_object($value)) {
            return '[object ' . $value::class . ']';
        }
        if (is_resource($value)) {
            return '[resource]';
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['_', '-', ' '], '', $key));
        if (in_array($normalized, self::EXACT_KEYS, true)) {
            return true;
        }
        foreach (self::KEY_SUBSTRINGS as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function relativePath(string $path): string
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 3));
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : basename($path);
    }
}

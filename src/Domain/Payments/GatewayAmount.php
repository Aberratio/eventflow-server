<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments;

/**
 * Money parsing for gateway amounts: everything is compared as integer grosze,
 * never as floats.
 */
final class GatewayAmount
{
    /**
     * Parses a PLN amount (gateway notification field or DECIMAL(10,2) column value)
     * into integer grosze. Returns null for anything that is not a non-negative
     * amount with at most 2 decimal places — callers treat null as "no trustworthy
     * amount".
     */
    public static function toGrosze(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value * 100 : null;
        }
        if (is_float($value)) {
            if (!is_finite($value) || $value < 0) {
                return null;
            }
            $grosze = (int) round($value * 100);
            // More than 2 decimal places (or float noise beyond rounding) is not a money amount.
            return abs($value * 100 - $grosze) > 1e-6 ? null : $grosze;
        }
        if (is_string($value)) {
            if (preg_match('/^(0|[1-9]\d{0,9})(?:\.(\d{1,2}))?$/D', trim($value), $m) !== 1) {
                return null;
            }
            $fraction = str_pad($m[2] ?? '', 2, '0');

            return (int) $m[1] * 100 + (int) $fraction;
        }

        return null;
    }
}

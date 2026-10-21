<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Auth;

use ZmierzymyCzas\Support\Env;

/** Limits of the login throttle. Every value has a safe default and can be overridden in the environment. */
final class LoginThrottleConfig
{
    /** @param list<int> $lockoutSeconds growing lockout per consecutive lockout; the last entry repeats */
    public function __construct(
        public readonly int $accountMax = 5,
        public readonly int $ipMax = 20,
        public readonly int $windowSeconds = 900,
        public readonly array $lockoutSeconds = [60, 300, 900],
    ) {
    }

    public static function fromEnv(): self
    {
        $positive = static function (string $key, int $default): int {
            $value = Env::get($key);
            return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
        };

        $lockouts = [];
        foreach (explode(',', (string) Env::get('LOGIN_THROTTLE_LOCKOUT_SECONDS', '')) as $part) {
            if (is_numeric(trim($part)) && (int) trim($part) > 0) {
                $lockouts[] = (int) trim($part);
            }
        }

        return new self(
            $positive('LOGIN_THROTTLE_ACCOUNT_MAX', 5),
            $positive('LOGIN_THROTTLE_IP_MAX', 20),
            $positive('LOGIN_THROTTLE_WINDOW_SECONDS', 900),
            $lockouts !== [] ? $lockouts : [60, 300, 900],
        );
    }
}

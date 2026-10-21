<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Auth;

/**
 * R-10: pure throttle arithmetic (no I/O, the clock is a parameter).
 *
 * State of one subject: failures counted in the current window, when that window began, how many
 * lockouts in a row it already earned (drives the growing lockout) and until when it is blocked.
 *
 * @phpstan-type State array{failures: int, windowStart: int, level: int, lockedUntil: int}
 */
final class LoginThrottlePolicy
{
    public function __construct(private readonly LoginThrottleConfig $config)
    {
    }

    /** @return State */
    public static function emptyState(): array
    {
        return ['failures' => 0, 'windowStart' => 0, 'level' => 0, 'lockedUntil' => 0];
    }

    /** @param State $state */
    public function retryAfter(array $state, int $now): int
    {
        return max(0, $state['lockedUntil'] - $now);
    }

    /**
     * @param State $state
     * @return array{state: State, lockedNow: bool}
     */
    public function afterFailure(array $state, int $max, int $now): array
    {
        // A failure while blocked is not counted: the attempt was rejected before the password was checked.
        if ($state['lockedUntil'] > $now) {
            return ['state' => $state, 'lockedNow' => false];
        }

        // A quiet period longer than the window after the last lockout forgets the lockout level.
        if ($state['level'] > 0 && $now - $state['lockedUntil'] > $this->config->windowSeconds) {
            $state['level'] = 0;
        }
        if ($state['windowStart'] === 0 || $now - $state['windowStart'] >= $this->config->windowSeconds) {
            $state['failures'] = 0;
            $state['windowStart'] = $now;
        }

        $state['failures']++;
        if ($state['failures'] < $max) {
            return ['state' => $state, 'lockedNow' => false];
        }

        $steps = $this->config->lockoutSeconds;
        $duration = $steps[min($state['level'], count($steps) - 1)];

        return [
            'state' => [
                'failures' => 0,
                'windowStart' => 0,
                'level' => $state['level'] + 1,
                'lockedUntil' => $now + $duration,
            ],
            'lockedNow' => true,
        ];
    }
}

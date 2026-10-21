<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Auth;

/**
 * R-10: brute-force protection for POST /auth/login, per account (normalised e-mail, existing or not)
 * and per IP. Blocked attempts are rejected before the password is checked; a successful login resets
 * only the account counter.
 */
final class LoginThrottle
{
    private readonly LoginThrottlePolicy $policy;

    /** @var callable(): int */
    private $clock;

    /** @param callable(): int $clock unix seconds */
    public function __construct(
        private readonly LoginThrottleRepository $repository,
        private readonly LoginThrottleConfig $config,
        callable $clock,
    ) {
        $this->policy = new LoginThrottlePolicy($config);
        $this->clock = $clock;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** @return int seconds the caller must wait, 0 when the attempt may proceed */
    public function retryAfter(string $email, ?string $ip): int
    {
        $now = ($this->clock)();
        $wait = $this->policy->retryAfter(
            $this->repository->load(LoginThrottleRepository::SCOPE_ACCOUNT, self::accountKey($email)),
            $now
        );
        if ($ip !== null && $ip !== '') {
            $wait = max($wait, $this->policy->retryAfter(
                $this->repository->load(LoginThrottleRepository::SCOPE_IP, self::ipKey($ip)),
                $now
            ));
        }

        return $wait;
    }

    /**
     * @return list<string> scopes ('account' / 'ip') that were locked by this very failure
     */
    public function recordFailure(string $email, ?string $ip): array
    {
        $now = ($this->clock)();
        $this->repository->purgeOld($now);
        $locked = [];
        $subjects = [[LoginThrottleRepository::SCOPE_ACCOUNT, self::accountKey($email), $this->config->accountMax]];
        if ($ip !== null && $ip !== '') {
            $subjects[] = [LoginThrottleRepository::SCOPE_IP, self::ipKey($ip), $this->config->ipMax];
        }
        foreach ($subjects as [$scope, $hash, $max]) {
            $lockedNow = false;
            $this->repository->update($scope, $hash, $now, function (array $state) use ($max, $now, &$lockedNow): array {
                $result = $this->policy->afterFailure($state, $max, $now);
                $lockedNow = $result['lockedNow'];
                return $result['state'];
            });
            if ($lockedNow) {
                $locked[] = $scope;
            }
        }

        return $locked;
    }

    public function recordSuccess(string $email): void
    {
        $this->repository->forget(LoginThrottleRepository::SCOPE_ACCOUNT, self::accountKey($email));
    }

    private static function accountKey(string $email): string
    {
        return hash('sha256', self::normalizeEmail($email));
    }

    private static function ipKey(string $ip): string
    {
        return hash('sha256', $ip);
    }
}

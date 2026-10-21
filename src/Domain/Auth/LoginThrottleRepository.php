<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Auth;

use PDO;

/**
 * R-10: persistence of {@see LoginThrottlePolicy} state, one row per (scope, key hash).
 *
 * @phpstan-import-type State from LoginThrottlePolicy
 */
class LoginThrottleRepository
{
    public const SCOPE_ACCOUNT = 'account';
    public const SCOPE_IP = 'ip';

    /** Rows untouched this long are irrelevant to any window or lockout and are deleted. */
    private const RETENTION_SECONDS = 86400;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return State */
    public function load(string $scope, string $keyHash): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT failures, window_start, lockout_level, locked_until FROM login_throttle WHERE scope = :scope AND key_hash = :hash'
        );
        $stmt->execute(['scope' => $scope, 'hash' => $keyHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->toState($row) : LoginThrottlePolicy::emptyState();
    }

    /**
     * Read-modify-write under a row lock so concurrent failures cannot undercount.
     *
     * @param callable(State): State $mutate
     * @return State the stored state
     */
    public function update(string $scope, string $keyHash, int $now, callable $mutate): array
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('INSERT IGNORE INTO login_throttle (scope, key_hash, updated_at) VALUES (:scope, :hash, :now)')
                ->execute(['scope' => $scope, 'hash' => $keyHash, 'now' => $now]);
            $stmt = $this->pdo->prepare(
                'SELECT failures, window_start, lockout_level, locked_until FROM login_throttle WHERE scope = :scope AND key_hash = :hash FOR UPDATE'
            );
            $stmt->execute(['scope' => $scope, 'hash' => $keyHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $next = $mutate(is_array($row) ? $this->toState($row) : LoginThrottlePolicy::emptyState());
            $this->pdo->prepare(
                'UPDATE login_throttle SET failures = :f, window_start = :w, lockout_level = :l, locked_until = :u, updated_at = :now
                 WHERE scope = :scope AND key_hash = :hash'
            )->execute([
                'f' => $next['failures'], 'w' => $next['windowStart'], 'l' => $next['level'], 'u' => $next['lockedUntil'],
                'now' => $now, 'scope' => $scope, 'hash' => $keyHash,
            ]);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                try {
                    $this->pdo->rollBack();
                } catch (\PDOException) {
                    // the transaction is already gone (e.g. a failed commit); the original error matters
                }
            }
            throw $e;
        }

        return $next;
    }

    public function forget(string $scope, string $keyHash): void
    {
        $this->pdo->prepare('DELETE FROM login_throttle WHERE scope = :scope AND key_hash = :hash')
            ->execute(['scope' => $scope, 'hash' => $keyHash]);
    }

    public function purgeOld(int $now): void
    {
        $this->pdo->prepare('DELETE FROM login_throttle WHERE updated_at < :cutoff AND locked_until < :now LIMIT 200')
            ->execute(['cutoff' => $now - self::RETENTION_SECONDS, 'now' => $now]);
    }

    /**
     * @param array<string, mixed> $row
     * @return State
     */
    private function toState(array $row): array
    {
        return [
            'failures' => (int) $row['failures'],
            'windowStart' => (int) $row['window_start'],
            'level' => (int) $row['lockout_level'],
            'lockedUntil' => (int) $row['locked_until'],
        ];
    }
}

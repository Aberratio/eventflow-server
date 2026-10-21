<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Support;

use PDO;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Support\Env;

/**
 * A real second connection to the same test database (built
 * the same way `Connection::fromEnv()` does — this cannot reuse the caller's
 * existing PDO object, PDO has no "wrap this connection" mode), which throws
 * on demand for exactly one SQL statement: the `approveRefund()` UPDATE
 * against `registration_refunds` for one specific registration id.
 *
 * Exists to simulate CompetitorMergeService::merge()'s documented failure
 * scenario for the post-commit refund/cancel loop ("concurrent state change
 * on that registration, a DB hiccup, anything") without actually needing a
 * second concurrent process — PDO's own idempotency guards
 * (requestRefund()/approveRefund() both no-op on a repeat call) make that
 * failure impossible to trigger honestly through the public repository API
 * alone, so this fault injection is the least invasive way to prove the
 * fix's behaviour under it.
 */
final class FaultInjectingRefundPdo extends PDO
{
    private bool $armed = true;

    public function __construct(private readonly string $targetRegistrationId)
    {
        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $database = Env::get('DB_DATABASE', 'zmierzymy_czas');
        $username = Env::get('DB_USERNAME', 'root');
        $password = Env::get('DB_PASSWORD', '');

        parent::__construct(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            (string) $username,
            (string) $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        Connection::applySessionSqlMode($this);
    }

    /** @param array<int|string, mixed> $options */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if ($this->armed && str_contains($query, 'registration_refunds') && str_contains($query, "'approved'")) {
            $this->armed = false; // once is enough to prove the point; do not wedge every later query.
            throw new \RuntimeException('simulated_refund_gateway_failure for ' . $this->targetRegistrationId);
        }

        return parent::prepare($query, $options);
    }
}

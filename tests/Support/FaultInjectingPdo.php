<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Support;

use PDO;
use ZmierzymyCzas\Support\Env;

/**
 * PP.20/PP.51: a real connection to the test database (built the way
 * `Connection::fromEnv()` builds one) whose prepare()/query() throw a given
 * PDOException whenever the SQL contains a given needle. Lets a test simulate a
 * database failure at exactly one statement without touching the real schema.
 *
 * Generic sibling of FaultInjectingRefundPdo, which stays untouched.
 */
final class FaultInjectingPdo extends PDO
{
    public function __construct(private readonly string $needle, private readonly \PDOException $toThrow)
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
    }

    /** @param array<int|string, mixed> $options */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->maybeThrow($query);

        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $this->maybeThrow($query);

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    private function maybeThrow(string $query): void
    {
        if (str_contains($query, $this->needle)) {
            throw $this->toThrow;
        }
    }
}

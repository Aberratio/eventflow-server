<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Support;

use PDO;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Support\Env;

/**
 * PP.18: a real connection to the test database whose FIRST prepare() of a statement containing
 * the needle throws the given exception; every later one behaves normally. Lets a batch test
 * make exactly one row fail.
 */
final class FailOncePdo extends PDO
{
    private bool $fired = false;

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
        Connection::applySessionSqlMode($this);
    }

    /** @param array<int|string, mixed> $options */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if (!$this->fired && str_contains($query, $this->needle)) {
            $this->fired = true;
            throw $this->toThrow;
        }

        return parent::prepare($query, $options);
    }
}

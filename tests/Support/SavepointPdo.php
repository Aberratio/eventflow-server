<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Support;

use PDO;
use ZmierzymyCzas\Support\Env;

/**
 * PP.4: a real connection to the test database that lets a test run arbitrary HTTP
 * requests with zero side effects. A test opens one outer transaction (beginOuter),
 * builds its fixtures inside it, and wraps every request in a named savepoint it can
 * roll back (savepoint / rollbackTo). While the outer transaction is open, the
 * application's own beginTransaction()/commit()/rollBack() become nested savepoints,
 * so even "committed" application writes disappear with rollbackTo()/rollbackOuter().
 *
 * No request-time DDL exists in src/ (it would implicitly commit), and the application
 * uses a single connection, so nothing can escape the rollback.
 */
final class SavepointPdo extends PDO
{
    private bool $outer = false;

    /** @var list<string> */
    private array $stack = [];

    private int $appDepth = 0;

    public function __construct()
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
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
    }

    public function beginOuter(): void
    {
        parent::beginTransaction();
        $this->outer = true;
        $this->stack = [];
        $this->appDepth = 0;
    }

    public function savepoint(string $name): void
    {
        $this->exec('SAVEPOINT ' . $name);
        $this->stack[] = $name;
    }

    /** Rolls back to the named test savepoint and discards it (and every savepoint above it). */
    public function rollbackTo(string $name): void
    {
        $index = array_search($name, $this->stack, true);
        if ($index === false) {
            throw new \LogicException("Unknown savepoint {$name}");
        }
        $this->stack = array_slice($this->stack, 0, (int) $index);
        $this->exec('ROLLBACK TO SAVEPOINT ' . $name);
        $this->exec('RELEASE SAVEPOINT ' . $name);
        $this->appDepth = count(array_filter($this->stack, static fn (string $s): bool => str_starts_with($s, 'zc_app_')));
    }

    public function rollbackOuter(): void
    {
        if (parent::inTransaction()) {
            parent::rollBack();
        }
        $this->outer = false;
        $this->stack = [];
        $this->appDepth = 0;
    }

    public function beginTransaction(): bool
    {
        if (!$this->outer) {
            return parent::beginTransaction();
        }
        $this->savepoint('zc_app_' . ++$this->appDepth);

        return true;
    }

    public function commit(): bool
    {
        if (!$this->outer) {
            return parent::commit();
        }
        if ($this->appDepth === 0) {
            throw new \PDOException('There is no active transaction');
        }
        $name = 'zc_app_' . $this->appDepth;
        $this->exec('RELEASE SAVEPOINT ' . $name);
        $this->popApp($name);

        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->outer) {
            return parent::rollBack();
        }
        if ($this->appDepth === 0) {
            throw new \PDOException('There is no active transaction');
        }
        $name = 'zc_app_' . $this->appDepth;
        $this->exec('ROLLBACK TO SAVEPOINT ' . $name);
        $this->exec('RELEASE SAVEPOINT ' . $name);
        $this->popApp($name);

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->outer ? $this->appDepth > 0 : parent::inTransaction();
    }

    private function popApp(string $name): void
    {
        $index = array_search($name, $this->stack, true);
        if ($index !== false) {
            $this->stack = array_slice($this->stack, 0, (int) $index);
        }
        $this->appDepth--;
    }
}

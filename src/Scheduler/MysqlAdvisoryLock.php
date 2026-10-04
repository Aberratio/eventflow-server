<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler;

use PDO;

/**
 * PP.18: MySQL/MariaDB advisory lock (GET_LOCK). Session-scoped, released automatically when the
 * connection or process dies, needs no table or privileges. Namespaced per database because
 * staging and production can share a MySQL server.
 */
final class MysqlAdvisoryLock implements SchedulerLock
{
    private bool $held = false;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function lockNameFor(PDO $pdo): string
    {
        $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        // GET_LOCK names are limited to 64 characters.
        return 'zc-scheduler:' . substr(hash('sha256', $dbName), 0, 32);
    }

    public function acquire(): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $stmt->execute(['name' => self::lockNameFor($this->pdo)]);
        $this->held = (int) $stmt->fetchColumn() === 1;

        return $this->held;
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }
        $this->held = false;
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute(['name' => self::lockNameFor($this->pdo)]);
            $stmt->fetchColumn();
        } catch (\Throwable) {
            // The lock dies with the connection anyway.
        }
    }
}

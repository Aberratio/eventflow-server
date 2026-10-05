<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;
use ZmierzymyCzas\Support\Env;

final class Connection
{
    public static function fromEnv(): PDO
    {
        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $database = Env::get('DB_DATABASE', 'zmierzymy_czas');
        $username = Env::get('DB_USERNAME', 'root');
        $password = Env::get('DB_PASSWORD', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        $pdo = new PDO($dsn, (string) $username, (string) $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        self::applySessionSqlMode($pdo);

        return $pdo;
    }

    /**
     * PP.76: optional per-session sql_mode, set only when DB_SESSION_SQL_MODE is configured.
     * Tests set it (tests/Support/TestDatabase::applySessionSqlMode) so local MariaDB runs with
     * MySQL 8.4's strict defaults and NOT NULL / ENUM / numeric violations fail locally the same
     * way they fail in production. Production does not set it: the server default applies.
     */
    public static function applySessionSqlMode(PDO $pdo): void
    {
        $mode = Env::get('DB_SESSION_SQL_MODE');
        if (!is_string($mode) || trim($mode) === '') {
            return;
        }
        $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote(trim($mode)));
    }
}

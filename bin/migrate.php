<?php

declare(strict_types=1);

use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$pdo = Connection::fromEnv();

$sql = (string) file_get_contents(__DIR__ . '/../database/migrations/001_init.sql');
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $pdo->exec($statement);
}

echo "Migrations applied.\n";

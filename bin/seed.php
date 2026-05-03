<?php

declare(strict_types=1);

use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$pdo = Connection::fromEnv();

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
foreach ($tables as $table) {
    $tableName = str_replace('`', '``', (string) $table[0]);
    $pdo->exec("DROP TABLE IF EXISTS `{$tableName}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

foreach ([
    __DIR__ . '/../database/migrations/001_init.sql',
    __DIR__ . '/../database/seeders/001_demo.sql',
] as $file) {
    $sql = (string) file_get_contents($file);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }
}

echo "Demo data seeded.\n";

<?php
declare(strict_types=1);

use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Support\Env;

require __DIR__ . '/../vendor/autoload.php';
Env::load(__DIR__ . '/../.env');

$file = $argv[1] ?? __DIR__ . '/../database/seeders/002_test_events.sql';

if (!file_exists($file)) {
    echo "Plik nie istnieje: $file\n";
    exit(1);
}

$pdo = Connection::fromEnv();
$sql = (string) file_get_contents($file);
$statements = array_filter(array_map('trim', explode(';', $sql)));

$ok = 0;
$err = 0;
foreach ($statements as $stmt) {
    try {
        $pdo->exec($stmt);
        $ok++;
    } catch (Exception $e) {
        echo 'ERR: ' . $e->getMessage() . "\n  SQL: " . substr($stmt, 0, 120) . "\n";
        $err++;
    }
}

echo "Gotowe: $ok instrukcji OK, $err błędów.\n";

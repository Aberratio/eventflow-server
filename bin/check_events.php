<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Database\Connection;
Env::load(__DIR__ . '/../.env');
$pdo = Connection::fromEnv();

$total = $pdo->query("SELECT COUNT(*) FROM events WHERE status IN ('active','past','archived') AND is_private=0")->fetchColumn();
echo "Publicznych eventów (active/past/archived): $total\n\n";

$rows = $pdo->query("SELECT id, name, status, date, organizer_id FROM events WHERE status IN ('active','past','archived') AND is_private=0 ORDER BY date ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo $r['date'] . ' | ' . $r['status'] . ' | ' . $r['organizer_id'] . ' | ' . $r['name'] . PHP_EOL;
}

<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Database\Connection;
Env::load(__DIR__ . '/../.env');
$pdo = Connection::fromEnv();

$total = $pdo->query("SELECT COUNT(*) FROM events WHERE publication_status IN ('published','finished','cancelled') AND is_private=0")->fetchColumn();
echo "Publicznych eventów (published/finished/cancelled): $total\n\n";

$rows = $pdo->query("SELECT id, name, publication_status, date, organizer_id FROM events WHERE publication_status IN ('published','finished','cancelled') AND is_private=0 ORDER BY date ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo $r['date'] . ' | ' . $r['publication_status'] . ' | ' . $r['organizer_id'] . ' | ' . $r['name'] . PHP_EOL;
}

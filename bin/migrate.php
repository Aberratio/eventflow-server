<?php

declare(strict_types=1);

use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\DemoData;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Database\SeedManifest;
use ZmierzymyCzas\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$pdo = Connection::fromEnv();
$env = (string) Env::get('APP_ENV', 'production');

$applied = Migrator::applyAll($pdo);
echo 'Migrations applied: ' . count($applied) . " file(s).\n";

// Deploying to production must leave no demo data behind, whatever state the database
// was in beforehand. Seeding is a separate, explicit command that refuses to run here.
if ($env === 'production') {
    $purged = SeedManifest::purge($pdo);
    $legacy = DemoData::pruneLegacyAccounts($pdo);

    $removed = $purged;
    foreach ($legacy as $table => $count) {
        $removed[$table] = ($removed[$table] ?? 0) + $count;
    }

    if ($removed === []) {
        echo "Production check: no demo data present.\n";
    } else {
        echo "Production check: removed demo rows —\n";
        foreach ($removed as $table => $count) {
            echo "  {$table}: {$count}\n";
        }
    }
}

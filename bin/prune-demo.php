<?php

declare(strict_types=1);

use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\DemoData;
use ZmierzymyCzas\Database\SeedManifest;
use ZmierzymyCzas\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Removes demo data from any environment, production included.
 *
 *   php bin/prune-demo.php --dry-run   report what would be deleted
 *   php bin/prune-demo.php --force     delete it
 *
 * `bin/migrate.php` already does this automatically when APP_ENV=production, so this
 * command is for cleaning up out of band — for instance a staging database that was
 * promoted, or a production database seeded by accident.
 */

Env::load(__DIR__ . '/../.env');

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$force = in_array('--force', $args, true);

if ($dryRun === $force) {
    fwrite(STDERR, "Usage: php bin/prune-demo.php (--dry-run | --force)\n");
    exit(1);
}

$pdo = Connection::fromEnv();
$env = (string) Env::get('APP_ENV', 'production');
$database = (string) Env::get('DB_DATABASE', 'zmierzymy_czas');
echo "Environment: {$env} | database: {$database}\n";

$tracked = SeedManifest::count($pdo);

if ($dryRun) {
    echo "Tracked demo rows in `" . SeedManifest::TABLE . "`: {$tracked}\n";

    $legacyIds = implode(', ', DemoData::LEGACY_USER_IDS);
    $placeholders = implode(', ', array_fill(0, count(DemoData::LEGACY_USER_IDS), '?'));
    $stmt = $pdo->prepare("SELECT id, email, role FROM users WHERE id IN ({$placeholders})");
    $stmt->execute(DemoData::LEGACY_USER_IDS);
    $legacy = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo 'Legacy seed accounts present: ' . count($legacy) . " (looked for: {$legacyIds})\n";
    foreach ($legacy as $row) {
        echo "  {$row['id']} | {$row['role']} | {$row['email']}\n";
    }

    echo "Nothing was deleted. Re-run with --force to apply.\n";
    exit(0);
}

$removed = SeedManifest::purge($pdo);
foreach (DemoData::pruneLegacyAccounts($pdo) as $table => $count) {
    $removed[$table] = ($removed[$table] ?? 0) + $count;
}

if ($removed === []) {
    echo "No demo data found.\n";
    exit(0);
}

echo "Deleted:\n";
foreach ($removed as $table => $count) {
    echo "  {$table}: {$count}\n";
}
echo 'Total: ' . array_sum($removed) . " row(s).\n";

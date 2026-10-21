<?php

declare(strict_types=1);

use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\DemoAccountPasswords;
use ZmierzymyCzas\Database\DemoData;
use ZmierzymyCzas\Database\EventCoherence;
use ZmierzymyCzas\Database\EventFixtureImporter;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Database\SeedManifest;
use ZmierzymyCzas\Database\SeriesLinker;
use ZmierzymyCzas\Database\ShopFixtureImporter;
use ZmierzymyCzas\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Demo data loader.
 *
 *   php bin/seed.php            refresh demo data (purge previous, re-apply seeders)
 *   php bin/seed.php --purge    remove demo data, leave everything else untouched
 *   php bin/seed.php --fresh    drop the schema, migrate, seed  (APP_ENV=local only)
 *
 * Never runs against production — deploys there apply migrations only.
 */

Env::load(__DIR__ . '/../.env');

$env = (string) Env::get('APP_ENV', 'production');
$args = array_slice($argv, 1);
$fresh = in_array('--fresh', $args, true);
$purgeOnly = in_array('--purge', $args, true);

$unknown = array_values(array_diff($args, ['--fresh', '--purge']));
if ($unknown !== []) {
    fwrite(STDERR, 'Unknown option(s): ' . implode(', ', $unknown) . "\n");
    fwrite(STDERR, "Usage: php bin/seed.php [--purge | --fresh]\n");
    exit(1);
}

if ($fresh && $purgeOnly) {
    fwrite(STDERR, "--fresh and --purge are mutually exclusive.\n");
    exit(1);
}

// ── Guards ──────────────────────────────────────────────────────────────────────
// The environment, not discipline, is what keeps demo data off production.
if ($env === 'production') {
    fwrite(STDERR, "Refusing to run: APP_ENV=production.\n");
    fwrite(STDERR, "Production applies migrations only. To clean demo data there, use bin/prune-demo.php.\n");
    exit(1);
}

if ($fresh && $env !== 'local') {
    fwrite(STDERR, "Refusing to run --fresh: APP_ENV={$env}, expected local.\n");
    fwrite(STDERR, "Dropping the schema is a local-only operation; use a plain seed run instead.\n");
    exit(1);
}

$pdo = Connection::fromEnv();
$database = (string) Env::get('DB_DATABASE', 'zmierzymy_czas');
echo "Environment: {$env} | database: {$database}\n";

// ── Purge ───────────────────────────────────────────────────────────────────────
if ($fresh) {
    $dropped = Migrator::dropAllTables($pdo);
    echo "Dropped {$dropped} table(s).\n";
} else {
    $tracked = SeedManifest::count($pdo);
    $removed = SeedManifest::purge($pdo);
    $legacy = DemoData::pruneLegacyAccounts($pdo);
    foreach ($legacy as $table => $count) {
        $removed[$table] = ($removed[$table] ?? 0) + $count;
    }

    $total = array_sum($removed);
    echo $tracked === 0 && $total === 0
        ? "No previously seeded data to remove.\n"
        : "Removed {$total} demo row(s) from " . count($removed) . " table(s).\n";
}

if ($purgeOnly) {
    echo "Purge complete.\n";
    exit(0);
}

// ── Schema ──────────────────────────────────────────────────────────────────────
$applied = Migrator::applyAll($pdo);
echo 'Schema up to date (' . count($applied) . " migration file(s)).\n";

// ── Seeders ─────────────────────────────────────────────────────────────────────
$seedersDir = __DIR__ . '/../database/seeders';
$generatedDir = $seedersDir . '/.generated';

if (!is_dir($generatedDir) && !mkdir($generatedDir, 0777, true) && !is_dir($generatedDir)) {
    fwrite(STDERR, "Could not create {$generatedDir}.\n");
    exit(1);
}

// The bulk fixtures are generated rather than committed: the generator is the single
// place that has to keep up with schema changes.
$generatedEvents = $generatedDir . '/002_test_events.sql';
$generator = escapeshellarg($seedersDir . '/generate_events.php');
$out = escapeshellarg('--out=' . $generatedEvents);
exec(escapeshellarg(PHP_BINARY) . " {$generator} {$out} 2>&1", $generatorOutput, $generatorStatus);

if ($generatorStatus !== 0) {
    fwrite(STDERR, "generate_events.php failed:\n" . implode("\n", $generatorOutput) . "\n");
    exit(1);
}
echo "Generated bulk fixtures: " . basename($generatedEvents) . "\n";

$files = [
    $seedersDir . '/001_demo.sql',
    $generatedEvents,
];

$before = SeedManifest::snapshot($pdo);

foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Missing seeder: {$file}\n");
        exit(1);
    }

    $statements = 0;
    try {
        foreach (Migrator::statements((string) file_get_contents($file)) as $statement) {
            $pdo->exec($statement);
            $statements++;
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            fwrite(STDERR, "\n" . basename($file) . " violated a constraint:\n");
            fwrite(STDERR, '  ' . $e->getMessage() . "\n\n");

            // Two unrelated causes wear the same SQLSTATE, and the advice for one is
            // useless for the other, so read the duplicated value before giving any.
            if (str_contains($e->getMessage(), "Duplicate entry ''")) {
                // The seeder colliding with itself: it leaves a NOT NULL UNIQUE column out
                // of its INSERT, and this MariaDB has no STRICT_TRANS_TABLES, so instead of
                // failing, every row of that INSERT silently gets the empty string.
                fwrite(STDERR, "The duplicated value is the empty string: that seeder omits a column that is\n");
                fwrite(STDERR, "NOT NULL UNIQUE, and this MariaDB runs without STRICT_TRANS_TABLES, so each row\n");
                fwrite(STDERR, "silently gets ''. The seeder collides with itself, on an empty table too --\n");
                fwrite(STDERR, "`--fresh` will not help. Add the column to that INSERT.\n");
            } else {
                // A database seeded before `seed_records` existed still holds those rows, but
                // the manifest does not know about them, so the purge above could not remove them.
                fwrite(STDERR, "This database was seeded before demo rows were tracked, so the purge could not\n");
                fwrite(STDERR, "find them. Run `php bin/seed.php --fresh` once (local), or delete the stale demo\n");
                fwrite(STDERR, "rows by hand (staging). Runs after that are tracked and will not hit this.\n");
            }

            exit(1);
        }

        throw $e;
    }
    echo '  ' . basename($file) . ": {$statements} statement(s)\n";
}

// The event fixtures the frontend used to carry as TypeScript literals. They go in last
// so they can merge with the accounts 001_demo.sql creates.
foreach ((new EventFixtureImporter($pdo))->import() as $table => $rows) {
    echo "  events.json -> {$table}: {$rows} row(s)\n";
}

// The seeders disagree about what day it is: two of them carry dates written months ago.
// This reconciles status, registration state and results against each event's date.
foreach (EventCoherence::apply($pdo) as $correction => $count) {
    echo "  coherence: {$correction} = {$count}\n";
}

// Events from the SQL seeders never went through the fixture pass, so top them up too.
foreach ((new EventFixtureImporter($pdo))->topUpFromDatabase() as $table => $rows) {
    echo "  top-up -> {$table}: {$rows} row(s)\n";
}

foreach ((new ShopFixtureImporter($pdo))->import() as $table => $rows) {
    echo "  shop.json -> {$table}: {$rows} row(s)\n";
}

// Which events exist is decided above, so series are filled out once they are all in.
foreach (SeriesLinker::apply($pdo) as $table => $rows) {
    echo "  series -> {$table}: {$rows} row(s)\n";
}

$after = SeedManifest::snapshot($pdo);
$recorded = SeedManifest::recordDiff($pdo, $before, $after);

echo "Seeded {$recorded} row(s), all tracked in `" . SeedManifest::TABLE . "` for later purge.\n";
if ($env === 'local') {
    echo "Demo logins: jan@example.com / marek@timing.pl / admin@zmierzymyczas.pl (password: demo1234)\n";
} else {
    // Staging is reachable from the internet; the seeders' well-known password must not survive there.
    // SEED_DEMO_PASSWORD (this server's .env only) gives the demo accounts a secret password instead.
    $demoPassword = (string) Env::get('SEED_DEMO_PASSWORD', '');
    if ($demoPassword === '') {
        $scrubbed = DemoAccountPasswords::scrub($pdo);
        echo "Scrubbed the demo password of {$scrubbed} seeded account(s): they have no usable password.\n";
        echo "Set SEED_DEMO_PASSWORD in .env to give them a usable one instead.\n";
    } else {
        try {
            $assigned = DemoAccountPasswords::assign($pdo, $demoPassword);
        } catch (InvalidArgumentException $e) {
            // Fail closed: never leave the well-known demo password behind.
            $scrubbed = DemoAccountPasswords::scrub($pdo);
            fwrite(STDERR, "SEED_DEMO_PASSWORD rejected: {$e->getMessage()}\n");
            fwrite(STDERR, "Scrubbed the demo password of {$scrubbed} seeded account(s) instead.\n");
            exit(1);
        }
        echo "Demo logins: jan@example.com / marek@timing.pl / admin@zmierzymyczas.pl (password: SEED_DEMO_PASSWORD from .env) - {$assigned} account(s).\n";
    }
}

<?php

declare(strict_types=1);

use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\SchemaFingerprint;
use ZmierzymyCzas\Tests\Support\TestDatabase;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * Dev tool for the separate local/CI test database selected through `ZC_TEST_DB_*`
 * (see TestDatabase). Lives under tests/ so it is excluded from the deploy artifact
 * (deploy.yml only ships src/public/database/vendor/bin/composer files).
 *
 *   ZC_TEST_DB_DATABASE=<name>_test php tests/bin/test-db.php prepare
 *   ZC_TEST_DB_DATABASE=<name>_test php tests/bin/test-db.php migrate
 *   ZC_TEST_DB_DATABASE=<name>_test php tests/bin/test-db.php fingerprint
 *
 * Every mode REQUIRES ZC_TEST_DB_DATABASE and refuses a name without a "_test"
 * suffix — this tool is only ever meant to point at a test database, never dev.
 */

$mode = $argv[1] ?? null;
$known = ['prepare', 'migrate', 'fingerprint'];

if (!in_array($mode, $known, true)) {
    fwrite(STDERR, 'Usage: php tests/bin/test-db.php <' . implode('|', $known) . ">\n");
    exit(1);
}

Env::load(__DIR__ . '/../../.env');

if (getenv('ZC_TEST_DB_DATABASE') === false) {
    fwrite(STDERR, "ZC_TEST_DB_DATABASE is required (must end in \"_test\").\n");
    exit(1);
}

TestDatabase::applyOverrides();
TestDatabase::assertIsTestDatabase();
TestDatabase::applySessionSqlMode();

if ($mode === 'prepare') {
    // Drop, migrate, seed — the same sequence CI runs, against the test database.
    Env::set('APP_ENV', 'local');
    $argv = ['seed.php', '--fresh'];
    require __DIR__ . '/../../bin/seed.php';
    exit(0);
}

$pdo = Connection::fromEnv();

if ($mode === 'migrate') {
    $applied = Migrator::applyAll($pdo);
    $total = count(glob(Migrator::migrationsDir() . '/*.sql') ?: []);
    echo 'Migrations applied: ' . count($applied) . " file(s).\n";
    foreach ($applied as $file) {
        echo "  {$file}\n";
    }
    echo 'Up to date: ' . ($total - count($applied)) . " file(s).\n";
    exit(0);
}

// fingerprint
echo SchemaFingerprint::of($pdo) . "\n";

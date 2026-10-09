<?php

declare(strict_types=1);

use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\DemoAccountPasswords;
use ZmierzymyCzas\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

/**
 * PP.90: removes the well-known demo passwords (demo1234 / demo123) from an existing database.
 *
 *   php bin/scrub-demo-passwords.php --dry-run   count the accounts, write nothing
 *   php bin/scrub-demo-passwords.php             scrub seed-manifest and demo-hash accounts
 *   php bin/scrub-demo-passwords.php --deep      also check every other account against the demo passwords
 *                                                (bcrypt per row: slow on large databases)
 *
 * Each scrubbed account gets an unusable password hash and its sessions end. Meant for staging (and for
 * production, which may hold demo rows); refuses on APP_ENV=local, where the demo logins are wanted.
 * Prints counts only, never e-mails or hashes.
 */

Env::load(__DIR__ . '/../.env');

$args = array_slice($argv, 1);
$unknown = array_values(array_diff($args, ['--dry-run', '--deep']));
if ($unknown !== []) {
    fwrite(STDERR, 'Unknown option(s): ' . implode(', ', $unknown) . "\n");
    fwrite(STDERR, "Usage: php bin/scrub-demo-passwords.php [--dry-run] [--deep]\n");
    exit(1);
}
$dryRun = in_array('--dry-run', $args, true);
$deep = in_array('--deep', $args, true);

$env = (string) Env::get('APP_ENV', 'production');
if ($env === 'local') {
    fwrite(STDERR, "Refusing to run: APP_ENV=local keeps its demo logins.\n");
    exit(1);
}

$pdo = Connection::fromEnv();
echo "Environment: {$env} | database: " . (string) Env::get('DB_DATABASE', 'zmierzymy_czas') . "\n";

$count = DemoAccountPasswords::scrub(
    $pdo,
    $deep,
    $dryRun,
    static function (int $checked): void {
        echo "  checked {$checked} account(s)...\n";
    }
);

echo ($dryRun ? 'Would scrub ' : 'Scrubbed ') . "{$count} account(s)" . ($deep ? ' (deep)' : '') . ".\n";

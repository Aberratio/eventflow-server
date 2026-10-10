<?php

declare(strict_types=1);

/**
 * ZC <-> BZ end-to-end contract test (PP.43).
 *
 * Starts the real Zmierzymy Czas server (this checkout) and the real Biuro Zawodow API
 * (a second checkout, BZ_DIR) on two fresh MySQL databases, then drives the scenario
 * "organizer enables integration -> BZ pulls -> participant withdraws (webhook) -> zqr_ and
 * pqr_ scans -> check-in flows back" over HTTP and the two CLIs.
 *
 *   BZ_DIR=/path/to/biuro_zawodow-api E2E_DB_HOST=127.0.0.1 E2E_DB_USER=root \
 *   E2E_DB_PASSWORD=... php tests/e2e/bz/run.php [--keep]
 *
 * Exit codes: 0 passed, 1 assertion failed, 2 environment or preflight error.
 * See TESTING.md ("ZC <-> BZ end-to-end contract test").
 */

require __DIR__ . '/lib.php';

const E2E_BZ_EVENT_ID = 'e2e-evt';

$keep = in_array('--keep', $argv, true);
$zcDir = rtrim(str_replace('\\', '/', (string) e2e_env('ZC_DIR', dirname(__DIR__, 3))), '/');
$bzDir = rtrim(str_replace('\\', '/', (string) e2e_env('BZ_DIR', '')), '/');
$host = '127.0.0.1';
$zcPort = (int) e2e_env('E2E_ZC_PORT', '18081');
$bzPort = (int) e2e_env('E2E_BZ_PORT', '18090');
$db = [
    'host' => (string) e2e_env('E2E_DB_HOST', '127.0.0.1'),
    'port' => (string) e2e_env('E2E_DB_PORT', '3306'),
    'user' => (string) e2e_env('E2E_DB_USER', 'root'),
    'password' => (string) e2e_env('E2E_DB_PASSWORD', '', true),
];
$zcDb = (string) e2e_env('E2E_ZC_DB', 'zc_e2e');
$bzDb = (string) e2e_env('E2E_BZ_DB', 'bz_e2e');
$logDir = rtrim(str_replace('\\', '/', (string) e2e_env('E2E_LOG_DIR', sys_get_temp_dir() . '/zc-bz-e2e-' . bin2hex(random_bytes(3)))), '/');

$writtenEnvFile = null;
$exitCode = E2E_EXIT_OK;
$startedAt = microtime(true);
$ctx = [];
$admin = null;

set_time_limit(600);

function e2e_cleanup(bool $keep, ?string &$writtenEnvFile, ?PDO $admin, string $zcDb, string $bzDb): void
{
    if ($keep) {
        // Debug aid: databases, the runner-owned .env and the logs stay; the servers still stop.
        stop_children();
        e2e_out('--keep: databases ' . $zcDb . ' and ' . $bzDb . ' and the runner-owned .env were left in place.');

        return;
    }
    stop_children();
    if ($writtenEnvFile !== null && is_file($writtenEnvFile)) {
        $first = fgets(fopen($writtenEnvFile, 'rb'));
        if ($first !== false && trim($first) === E2E_ENV_MARKER) {
            @unlink($writtenEnvFile);
        }
        $writtenEnvFile = null;
    }
    if ($admin !== null) {
        foreach ([$zcDb, $bzDb] as $name) {
            try {
                $admin->exec('DROP DATABASE IF EXISTS `' . $name . '`');
            } catch (Throwable) {
                // best effort
            }
        }
    }
}

register_shutdown_function(static function () use (&$writtenEnvFile, $keep): void {
    // Safety net for fatal errors: never leave servers or a runner-owned .env behind.
    stop_children();
    if (!$keep && $writtenEnvFile !== null && is_file($writtenEnvFile)) {
        $handle = fopen($writtenEnvFile, 'rb');
        $first = $handle ? fgets($handle) : false;
        if ($handle) {
            fclose($handle);
        }
        if ($first !== false && trim($first) === E2E_ENV_MARKER) {
            @unlink($writtenEnvFile);
        }
    }
});

try {
    // ------------------------------------------------------------ preflight
    foreach (['pdo_mysql', 'curl', 'sodium'] as $extension) {
        if (!extension_loaded($extension)) {
            e2e_fail_env('PHP extension ' . $extension . ' is not loaded (try: php -d extension=' . $extension . ' ...).');
        }
    }
    if ($bzDir === '' || !is_file($bzDir . '/public/index.php')) {
        e2e_fail_env('BZ_DIR must point to a checkout of biuro_zawodow-api (got: ' . ($bzDir === '' ? '<unset>' : $bzDir) . ').');
    }
    foreach ([$zcDir, $bzDir] as $dir) {
        if (!is_file($dir . '/vendor/autoload.php')) {
            e2e_fail_env('Run "composer install" in ' . $dir . ' first (vendor/ is missing).');
        }
    }
    foreach ([$zcDb, $bzDb] as $name) {
        if (preg_match('/^[A-Za-z0-9_]+_e2e$/', $name) !== 1) {
            e2e_fail_env('Refusing database "' . $name . '": e2e databases must end with _e2e.');
        }
    }
    if ($zcDb === $bzDb) {
        e2e_fail_env('E2E_ZC_DB and E2E_BZ_DB must differ.');
    }
    foreach ([$zcPort, $bzPort] as $port) {
        if (!port_is_free($host, $port)) {
            e2e_fail_env('Port ' . $port . ' is already in use (set E2E_ZC_PORT / E2E_BZ_PORT).');
        }
    }
    $zcEnvFile = $zcDir . '/.env';
    if (is_file($zcEnvFile)) {
        $handle = fopen($zcEnvFile, 'rb');
        $first = $handle ? fgets($handle) : false;
        if ($handle) {
            fclose($handle);
        }
        if ($first === false || trim($first) !== E2E_ENV_MARKER) {
            e2e_fail_env($zcEnvFile . ' exists and was not written by this runner. Use a dedicated checkout or worktree (git worktree add ...); refusing to overwrite your .env.');
        }
    }
    if (!is_dir($logDir) && !mkdir($logDir, 0777, true) && !is_dir($logDir)) {
        e2e_fail_env('Cannot create log directory ' . $logDir);
    }
    $bzLogDir = $logDir . '/bz-app-logs';
    @mkdir($bzLogDir, 0777, true);

    // ------------------------------------------------------------ per-run secrets
    $secrets = [
        'jwtSecret' => bin2hex(random_bytes(32)),
        'bzAppKey' => bin2hex(random_bytes(32)),
        'incomingKey' => bin2hex(random_bytes(32)),
        'orgToken' => bin2hex(random_bytes(32)),
        'bzTokenKey' => base64_encode(random_bytes(32)),
    ];
    foreach ($secrets as $secret) {
        E2eSecrets::add($secret);
    }
    E2eSecrets::add($db['password']);

    // ------------------------------------------------------------ databases
    try {
        $admin = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $db['host'], $db['port']),
            $db['user'],
            $db['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
    } catch (PDOException $exception) {
        e2e_fail_env('Cannot connect to MySQL at ' . $db['host'] . ':' . $db['port'] . ': ' . $exception->getMessage());
    }
    foreach ([$zcDb, $bzDb] as $name) {
        $admin->exec('DROP DATABASE IF EXISTS `' . $name . '`');
        $admin->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    e2e_out('Fresh databases: ' . $zcDb . ', ' . $bzDb . ' on ' . $db['host'] . ':' . $db['port']);

    // ------------------------------------------------------------ ZC bootstrap (.env, migrate)
    $zcBase = 'http://' . $host . ':' . $zcPort;
    $bzBase = 'http://' . $host . ':' . $bzPort;
    $zcEnv = implode("\n", [
        E2E_ENV_MARKER,
        'APP_ENV=local',
        'DB_HOST=' . $db['host'],
        'DB_PORT=' . $db['port'],
        'DB_DATABASE=' . $zcDb,
        'DB_USERNAME=' . $db['user'],
        'DB_PASSWORD=' . $db['password'],
        'JWT_SECRET=' . $secrets['jwtSecret'],
        'APP_CORS_ORIGIN=*',
        'INCOMING_API_KEYS=biuro_zawodow=' . $secrets['incomingKey'],
        'BZ_WEBHOOK_BASE_URL=' . $bzBase,
        '',
    ]);
    if (file_put_contents($zcEnvFile, $zcEnv) === false) {
        e2e_fail_env('Cannot write ' . $zcEnvFile);
    }
    $writtenEnvFile = $zcEnvFile;

    $sodiumFlags = PHP_OS_FAMILY === 'Windows' ? ['-d', 'extension=sodium'] : [];
    $zcPhp = [PHP_BINARY];
    // No bin/seed.php: the full seed generates bulk events (minutes); tests/e2e/bz/zc-fixture.php loads
// the demo accounts and the scenario data it needs.
foreach (['migrate' => 'bin/migrate.php'] as $label => $script) {
        $t0 = microtime(true);
        $result = run_cli(array_merge($zcPhp, [$script]), $zcDir);
        e2e_out(sprintf('  zc %s: %.1f s', $label, microtime(true) - $t0));
        if ($result['status'] !== 0) {
            e2e_fail_env('ZC ' . $label . ' failed (exit ' . $result['status'] . "):\n" . substr($result['output'], -1500));
        }
    }
    e2e_out('ZC migrated.');

    // ------------------------------------------------------------ BZ bootstrap (process env, init SQL, migrations)
    $bzEnv = [
        'APP_ENV' => 'test',
        'APP_DEBUG' => 'false',
        'DB_HOST' => $db['host'],
        'DB_PORT' => $db['port'],
        'DB_DATABASE' => $bzDb,
        'DB_USERNAME' => $db['user'],
        'DB_PASSWORD' => $db['password'],
        'APP_KEY' => $secrets['bzAppKey'],
        'APP_CORS_ORIGIN' => '*',
        'LOG_DIR' => $bzLogDir,
        'BZ_PLATFORM_API_URL' => $zcBase . '/api/v1',
        'BZ_PLATFORM_APP_KEY' => $secrets['incomingKey'],
        'BZ_PLATFORM_TOKEN_KEY' => $secrets['bzTokenKey'],
        'CENTRAL_MAILER_URL' => '',
    ];
    $bzPdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $bzDb),
        $db['user'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]
    );
    $initSql = (string) file_get_contents($bzDir . '/database/init/001_init.sql');
    $initSql = preg_replace('/^\xEF\xBB\xBF/', '', $initSql) ?? $initSql;
    $statement = $bzPdo->query($initSql);
    while ($statement !== false && $statement->nextRowset()) {
        // iterating surfaces errors of later statements
    }
    // 001_init.sql already contains the schema of migrations 002-027, so those are adopted (verified
    // against the schema) and the plain run then applies what is migration-only (028-030).
    foreach ([['--adopt-existing'], []] as $migrationArgs) {
        $result = run_cli(array_merge([PHP_BINARY], $sodiumFlags, ['scripts/run-migrations.php'], $migrationArgs), $bzDir, $bzEnv);
        if ($result['status'] !== 0) {
            e2e_fail_env('BZ migrations (' . ($migrationArgs[0] ?? 'run') . ") failed (exit {$result['status']}):\n" . substr($result['output'], -1500));
        }
    }
    e2e_out('BZ initialised and migrated.');

    // ------------------------------------------------------------ servers
    start_php_server('zc', $zcDir, $host, $zcPort, 'public', 'router.php', $logDir . '/zc-server.log', $sodiumFlags, []);
    start_php_server('bz', $bzDir, $host, $bzPort, '', 'router.php', $logDir . '/bz-server.log', $sodiumFlags, $bzEnv);
    if (!wait_for_http($zcBase . '/api/v1/health', 20)) {
        e2e_fail_env("ZC server did not become ready.\n" . tail_file($logDir . '/zc-server.log'));
    }
    if (!wait_for_http($bzBase . '/health', 20)) {
        e2e_fail_env("BZ server did not become ready.\n" . tail_file($logDir . '/bz-server.log'));
    }
    e2e_out('Both servers are ready (ZC ' . $zcBase . ', BZ ' . $bzBase . ').');

    $ctx = [
        'zcDir' => $zcDir, 'bzDir' => $bzDir, 'zcBase' => $zcBase, 'bzBase' => $bzBase,
        'zcPdo' => null, 'bzPdo' => $bzPdo, 'bzEnv' => $bzEnv, 'secrets' => $secrets,
        'sodiumFlags' => $sodiumFlags, 'db' => $db, 'zcDb' => $zcDb, 'bzDb' => $bzDb,
    ];
    $ctx['zcPdo'] = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $zcDb),
        $db['user'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]
    );

    // ------------------------------------------------------------ scenario
    // (S0-S5 are added in the following commits)
    E2eScenario::section('Environment');
    expect(true, 'runner bootstrap complete');
} catch (E2eAbort $abort) {
    $exitCode = $abort->exitCode;
    e2e_out(($exitCode === E2E_EXIT_FAILED ? 'FAILED: ' : 'ENVIRONMENT ERROR: ') . $abort->getMessage());
    if ($exitCode === E2E_EXIT_FAILED || is_file($logDir . '/zc-server.log')) {
        e2e_out('--- ZC server log (tail)');
        e2e_out(tail_file($logDir . '/zc-server.log'));
        e2e_out('--- BZ server log (tail)');
        e2e_out(tail_file($logDir . '/bz-server.log'));
    }
} catch (Throwable $throwable) {
    $exitCode = E2E_EXIT_ENVIRONMENT;
    e2e_out('ENVIRONMENT ERROR: unexpected ' . $throwable::class . ': ' . $throwable->getMessage());
}

e2e_cleanup($keep, $writtenEnvFile, $admin, $zcDb, $bzDb);

// Logs may sit in an uploaded CI artifact: rewrite them redacted, whatever the outcome.
foreach (glob($logDir . '/*.log') ?: [] as $logFile) {
    file_put_contents($logFile, E2eSecrets::redact((string) file_get_contents($logFile)));
}
foreach (glob($bzLogDir ?? $logDir . '/bz-app-logs/*') ?: [] as $logFile) {
    if (is_file($logFile)) {
        file_put_contents($logFile, E2eSecrets::redact((string) file_get_contents($logFile)));
    }
}

e2e_out('');
e2e_out(sprintf(
    '%s: %d assertion(s) passed in %.1f s (exit %d)',
    $exitCode === E2E_EXIT_OK ? 'E2E PASSED' : 'E2E DID NOT PASS',
    E2eScenario::$passed,
    microtime(true) - $startedAt,
    $exitCode
));
exit($exitCode);

<?php

declare(strict_types=1);

/**
 * ZC <-> BZ end-to-end contract test.
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
$logDirIsTemporary = e2e_env('E2E_LOG_DIR') === null;
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

/**
 * The scenario. Every expect() is one assertion; the first failure aborts with exit 1.
 *
 * @param array<string, mixed> $ctx
 */
function e2e_scenario(array $ctx): void
{
    /** @var PDO $zc */
    $zc = $ctx['zcPdo'];
    /** @var PDO $bz */
    $bz = $ctx['bzPdo'];
    $zcBase = $ctx['zcBase'] . '/api/v1';
    $bzBase = $ctx['bzBase'];
    $token = $ctx['secrets']['orgToken'];
    $key = $ctx['secrets']['incomingKey'];
    $bzEventId = E2E_BZ_EVENT_ID;

    $one = static function (PDO $pdo, string $sql, array $params = []): mixed {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    };
    $rows = static function (PDO $pdo, string $sql, array $params = []): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    };
    $zcLogin = static function (string $email) use ($zcBase): array {
        $response = http_call('POST', $zcBase . '/auth/login', [], ['email' => $email, 'password' => 'demo1234']);
        $jwt = jget($response['json'], 'data.token');
        if ($response['status'] !== 200 || !is_string($jwt)) {
            e2e_fail_env('ZC login failed for ' . $email . ': ' . describe_response($response));
        }
        E2eSecrets::add($jwt);

        return ['Authorization' => 'Bearer ' . $jwt];
    };
    $bzLogin = static function (string $email) use ($bzBase): array {
        $response = http_call('POST', $bzBase . '/auth/login', [], ['email' => $email, 'password' => 'demo123']);
        $jwt = jget($response['json'], 'access_token');
        if ($response['status'] !== 200 || !is_string($jwt)) {
            e2e_fail_env('BZ login failed for ' . $email . ': ' . describe_response($response));
        }
        E2eSecrets::add($jwt);

        return ['Authorization' => 'Bearer ' . $jwt];
    };
    $bzCli = static function (string $script, array $args) use ($ctx): array {
        return run_cli(array_merge([PHP_BINARY], $ctx['sodiumFlags'], [$script], $args), $ctx['bzDir'], $ctx['bzEnv']);
    };

    // ---- fixtures: ZC through the repositories (separate process, uses the runner-owned .env), BZ through PDO
    E2eScenario::section('Fixtures');
    $fixture = run_cli([PHP_BINARY, 'tests/e2e/bz/zc-fixture.php'], $ctx['zcDir']);
    expect($fixture['status'] === 0, 'ZC fixture script exits 0', $fixture['output']);
    $data = json_decode($fixture['output'], true);
    expect(is_array($data) && isset($data['eventId']), 'ZC fixture prints its JSON', $fixture['output']);
    $eventId = (string) $data['eventId'];
    $regs = ['A' => $data['A'], 'B' => $data['B'], 'C' => $data['C']];
    foreach ($regs as $name => $reg) {
        expect(str_starts_with($reg['qrCode'], 'pqr_'), "registration $name has a pqr_ code");
        expect(str_starts_with($reg['identityCode'], 'zqr_'), "registration $name has a zqr_ identity code");
    }
    $bz->prepare(
        'INSERT INTO events (id, name, event_date, location, organization_id, office_open_at, office_close_at, is_test)
         VALUES (:id, :name, DATE_ADD(CURDATE(), INTERVAL 14 DAY), :location, :org, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY), 0)'
    )->execute(['id' => $bzEventId, 'name' => 'E2E BZ Contract Run', 'location' => 'Stadion E2E', 'org' => 'org-1']);
    // Scanners may only use an event while one of its office locations is open (event_office_location_hours,
    // not events.office_open_at). The window is +-1 day around now, computed in this process's timezone.
    $bz->prepare('INSERT INTO event_office_locations (event_id, name, display_order) VALUES (?, ?, 0)')->execute([$bzEventId, 'Biuro E2E']);
    $bz->prepare('INSERT INTO event_office_location_hours (location_id, opens_at, closes_at, display_order) VALUES (?, ?, ?, 0)')->execute([
        (int) $bz->lastInsertId(),
        date('Y-m-d H:i:s', time() - 86400),
        date('Y-m-d H:i:s', time() + 86400),
    ]);
    foreach (['u-2', 'u-4'] as $userId) {
        $bz->prepare('INSERT INTO user_event_assignments (user_id, event_id) VALUES (:u, :e)')->execute(['u' => $userId, 'e' => $bzEventId]);
    }
    expect((int) $one($bz, 'SELECT COUNT(*) FROM user_event_assignments WHERE event_id = ?', [$bzEventId]) === 2, 'BZ event with an office window around now and two assignments');

    // ---- S0: BZ stores the connection; pull is refused while ZC integration is still off
    E2eScenario::section('S0 BZ connection, ZC integration still disabled');
    $editor = $bzLogin('org.gniezno@sportevents.pl');
    $connection = http_call('PUT', $bzBase . '/events/' . $bzEventId . '/platform-connection', $editor, [
        'platform_event_id' => $eventId, 'organization_token' => $token, 'is_enabled' => true,
    ]);
    expect($connection['status'] === 200, 'BZ saves the platform connection (200)', describe_response($connection));
    expect(!str_contains($connection['body'], $token), 'BZ response never echoes the organization token');
    $pullEarly = http_call('POST', $bzBase . '/events/' . $bzEventId . '/platform-connection/pull', $editor);
    e2e_out('     pull before enablement: ' . describe_response($pullEarly));
    expect(!str_contains($pullEarly['body'], $token), 'pull response never echoes the organization token');
    expect(jget($pullEarly['json'], 'data.result.status') === 'token_rejected', 'pull before enablement is refused by ZC (token_rejected)', describe_response($pullEarly));
    expect((int) $one($bz, 'SELECT COUNT(*) FROM participants WHERE event_id = ?', [$bzEventId]) === 0, 'pull before ZC enablement imported nothing');

    // ---- S1: organizer enables the integration on ZC
    E2eScenario::section('S1 organizer enables the integration (ZC)');
    $organizer = $zcLogin('marek@timing.pl');
    $enable = http_call('PATCH', $zcBase . '/events/' . $eventId, $organizer, ['bzIntegrationEnabled' => true, 'bzOrganizationToken' => $token]);
    expect($enable['status'] === 200, 'ZC PATCH event enables BZ integration (200)', describe_response($enable));
    expect((int) $one($zc, 'SELECT bz_integration_enabled FROM events WHERE id = ?', [$eventId]) === 1, 'ZC events.bz_integration_enabled = 1');
    $list = http_call('GET', $zcBase . '/events/' . $eventId . '/bz/participants', ['X-API-Key' => $key, 'X-BZ-Organization-Token' => $token]);
    expect($list['status'] === 200, 'ZC participants list answers BZ credentials (200)', describe_response($list));

    // ---- S2: BZ pulls
    E2eScenario::section('S2 BZ pulls the participant list');
    // BZ throttles manual pulls to one per 15 s; the refused pull in S0 counts, so reset the timestamp instead of sleeping.
    $bz->prepare('UPDATE event_platform_connections SET last_pull_started_at = NULL WHERE event_id = ?')->execute([$bzEventId]);
    $pull = http_call('POST', $bzBase . '/events/' . $bzEventId . '/platform-connection/pull', $editor);
    expect($pull['status'] === 200, 'BZ manual pull answers 200', describe_response($pull));
    $imported = $rows($bz, 'SELECT id, platform_registration_id, qr_code, identity_code, platform_removed_at FROM participants WHERE event_id = ? ORDER BY platform_registration_id', [$bzEventId]);
    expect(count($imported) === 3, 'BZ holds exactly 3 participants for the event', json_encode($imported) ?: '');
    $bzByReg = [];
    foreach ($imported as $row) {
        $bzByReg[(string) $row['platform_registration_id']] = $row;
    }
    foreach ($regs as $name => $reg) {
        $row = $bzByReg[$reg['registrationId']] ?? null;
        expect($row !== null, "BZ participant exists for registration $name");
        expect($row['qr_code'] === $reg['qrCode'], "BZ qr_code equals the ZC pqr_ code of $name");
        expect($row['identity_code'] === $reg['identityCode'], "BZ identity_code equals the ZC zqr_ code of $name");
        expect($row['platform_removed_at'] === null, "participant $name is not marked withdrawn yet");
    }
    $pullAgain = $bzCli('scripts/pull-platform-participants.php', ['--event=' . $bzEventId]);
    expect($pullAgain['status'] === 0, 'BZ pull CLI exits 0', $pullAgain['output']);
    expect((int) $one($bz, 'SELECT COUNT(*) FROM participants WHERE event_id = ?', [$bzEventId]) === 3, 'second pull creates no duplicates');

    // ---- S3: participant A withdraws -> webhook
    E2eScenario::section('S3 participant withdraws (ZC -> BZ webhook)');
    $participant = $zcLogin('jan@example.com');
    $cancel = http_call('POST', $zcBase . '/registrations/' . $regs['A']['registrationId'] . '/cancel', $participant);
    expect($cancel['status'] === 200, 'ZC cancel answers 200', describe_response($cancel));
    expect((string) $one($zc, 'SELECT status FROM registrations WHERE id = ?', [$regs['A']['registrationId']]) === 'cancelled', 'ZC registration A is cancelled');
    $outbox = $rows($zc, 'SELECT change_type, status, last_error FROM bz_webhook_outbox WHERE registration_id = ?', [$regs['A']['registrationId']]);
    expect(count($outbox) === 1, 'ZC enqueued exactly one webhook outbox row for A', json_encode($outbox) ?: '');
    expect($outbox[0]['change_type'] === 'cancellation', 'outbox row is a cancellation');
    expect($outbox[0]['status'] === 'sent', 'outbox row was delivered in the same request (status sent)', json_encode($outbox) ?: '');
    $removed = $rows($bz, 'SELECT platform_registration_id, platform_removed_at FROM participants WHERE event_id = ? AND platform_removed_at IS NOT NULL', [$bzEventId]);
    expect(count($removed) === 1 && $removed[0]['platform_registration_id'] === $regs['A']['registrationId'], 'BZ marked only A as withdrawn', json_encode($removed) ?: '');
    expect((int) $one($bz, 'SELECT COUNT(*) FROM platform_webhook_receipts WHERE event_id = ? AND platform_registration_id = ?', [$bzEventId, $regs['A']['registrationId']]) === 1, 'BZ stored exactly one webhook receipt for A');
    $cancelAgain = http_call('POST', $zcBase . '/registrations/' . $regs['A']['registrationId'] . '/cancel', $participant);
    e2e_out('     repeated cancel answered HTTP ' . $cancelAgain['status']);
    expect((int) $one($zc, 'SELECT COUNT(*) FROM bz_webhook_outbox WHERE registration_id = ?', [$regs['A']['registrationId']]) === 1, 'repeated cancel creates no second outbox row');
    expect((int) $one($bz, 'SELECT COUNT(*) FROM platform_webhook_receipts WHERE event_id = ?', [$bzEventId]) === 1, 'repeated cancel causes no second receipt');
    $retry = run_cli([PHP_BINARY, 'bin/scheduler.php', '--job=bz.outbox_retry'], $ctx['zcDir']);
    expect($retry['status'] === 0, 'ZC scheduler job bz.outbox_retry exits 0', $retry['output']);
    expect((int) $one($bz, 'SELECT COUNT(*) FROM platform_webhook_receipts WHERE event_id = ?', [$bzEventId]) === 1, 'outbox retry does not resend a delivered row');
    $listAfter = http_call('GET', $zcBase . '/events/' . $eventId . '/bz/participants', ['X-API-Key' => $key, 'X-BZ-Organization-Token' => $token]);
    expect(count((array) jget($listAfter['json'], 'data')) === 2 || count((array) jget($listAfter['json'], 'data.participants')) === 2, 'ZC participants list no longer contains A', describe_response($listAfter));

    // ---- S4: scans and check-in at BZ
    E2eScenario::section('S4 scans (zqr_ and pqr_) and check-in at BZ');
    $scanner = $bzLogin('skaner1@sportevents.pl');
    $scanB = http_call('POST', $bzBase . '/participants/scan', $scanner, ['qr_code' => $regs['B']['identityCode']]);
    expect($scanB['status'] === 200, 'zqr_ scan of B answers 200', describe_response($scanB));
    $idB = (int) jget($scanB['json'], 'data.participant.id');
    expect($idB === (int) $bzByReg[$regs['B']['registrationId']]['id'], 'zqr_ scan resolves participant B in the current event');
    $checkB = http_call('POST', $bzBase . '/participants/' . $idB . '/check-in', $scanner);
    expect($checkB['status'] === 200 && jget($checkB['json'], 'data.status') === 'checked_in', 'B is checked in at BZ', describe_response($checkB));
    $scanC = http_call('POST', $bzBase . '/participants/scan', $scanner, ['qr_code' => $regs['C']['qrCode']]);
    expect($scanC['status'] === 200, 'pqr_ scan of C answers 200', describe_response($scanC));
    $idC = (int) jget($scanC['json'], 'data.participant.id');
    expect($idC === (int) $bzByReg[$regs['C']['registrationId']]['id'], 'pqr_ scan resolves participant C');
    $checkC = http_call('POST', $bzBase . '/participants/' . $idC . '/check-in', $scanner);
    expect($checkC['status'] === 200 && jget($checkC['json'], 'data.status') === 'checked_in', 'C is checked in at BZ', describe_response($checkC));
    $scanA = http_call('POST', $bzBase . '/participants/scan', $scanner, ['qr_code' => $regs['A']['qrCode']]);
    expect($scanA['status'] === 200, 'pqr_ scan of withdrawn A still answers 200 (warn, never block)', describe_response($scanA));
    expect(jget($scanA['json'], 'data.participant.platform_removed_at') !== null, 'scan of A carries platform_removed_at');
    expect((string) $one($bz, 'SELECT status FROM participants WHERE platform_registration_id = ?', [$regs['A']['registrationId']]) !== 'checked_in', 'A is not checked in');

    // ---- S5: check-in flows back to ZC
    E2eScenario::section('S5 check-in flows back to ZC (BZ push CLI)');
    $queued = $rows($bz, 'SELECT p.platform_registration_id AS reg, q.status FROM platform_push_queue q JOIN participants p ON p.id = q.participant_id WHERE q.event_id = ? ORDER BY p.platform_registration_id', [$bzEventId]);
    $queuedRegs = array_map(static fn (array $r): string => (string) $r['reg'], $queued);
    sort($queuedRegs);
    $expectedRegs = [$regs['B']['registrationId'], $regs['C']['registrationId']];
    sort($expectedRegs);
    expect($queuedRegs === $expectedRegs, 'BZ push queue holds rows for B and C only', json_encode($queued) ?: '');
    expect(count(array_filter($queued, static fn (array $r): bool => $r['status'] === 'pending')) === 2, 'both queue rows are pending before the push');
    expect($rows($bz, "SELECT 1 FROM platform_push_queue WHERE event_id = ? AND participant_id = ?", [$bzEventId, $idB]) !== [], 'the pending rows belong to the participants checked in at BZ');
    $push = $bzCli('scripts/push-platform-changes.php', ['--event=' . $bzEventId]);
    expect($push['status'] === 0, 'BZ push CLI exits 0', $push['output']);
    foreach (['B', 'C'] as $name) {
        $checked = $rows($zc, 'SELECT checked_in_at, checked_in_by FROM registrations WHERE id = ?', [$regs[$name]['registrationId']])[0];
        expect($checked['checked_in_at'] !== null, "ZC registration $name has checked_in_at");
        expect((string) $checked['checked_in_by'] === 'Wolontariusz Operator 1', "ZC registration $name carries the BZ scanner as checked_in_by", json_encode($checked) ?: '');
    }
    expect($one($zc, 'SELECT checked_in_at FROM registrations WHERE id = ?', [$regs['A']['registrationId']]) === null, 'ZC registration A is not checked in');
    expect((int) $one($bz, "SELECT COUNT(*) FROM platform_push_queue WHERE event_id = ? AND status = 'sent'", [$bzEventId]) === 2, 'both BZ queue rows are marked sent');
    expect((int) $one($bz, "SELECT COUNT(*) FROM platform_push_queue WHERE event_id = ? AND status IN ('pending', 'failed')", [$bzEventId]) === 0, 'BZ push queue has nothing pending or failed after the push');
    $before = $rows($zc, 'SELECT id, checked_in_at, checked_in_by FROM registrations WHERE event_id = ? ORDER BY id', [$eventId]);
    $pushAgain = $bzCli('scripts/push-platform-changes.php', ['--event=' . $bzEventId]);
    expect($pushAgain['status'] === 0, 'second BZ push run exits 0', $pushAgain['output']);
    expect($rows($zc, 'SELECT id, checked_in_at, checked_in_by FROM registrations WHERE event_id = ? ORDER BY id', [$eventId]) === $before, 'second push leaves ZC check-in values unchanged');
}

/**
 * Failure diagnostics: the rows that explain a contract break. Never selects tokens, payloads or
 * ciphertexts; everything printed also goes through the redaction of e2e_out().
 *
 * @param array<string, mixed> $ctx
 */
function e2e_dump_tables(array $ctx): void
{
    $queries = [
        ['ZC', $ctx['zcPdo'], 'bz_webhook_outbox', 'SELECT registration_id, change_type, status, last_error, sent_at FROM bz_webhook_outbox ORDER BY created_at'],
        ['ZC', $ctx['zcPdo'], 'registrations', 'SELECT id, status, checked_in_at, checked_in_by FROM registrations ORDER BY id'],
        ['BZ', $ctx['bzPdo'], 'platform_webhook_receipts', 'SELECT event_id, platform_registration_id, change_type, outcome FROM platform_webhook_receipts'],
        ['BZ', $ctx['bzPdo'], 'platform_push_queue', 'SELECT participant_id, status, attempts, last_error, last_http_status FROM platform_push_queue'],
        ['BZ', $ctx['bzPdo'], 'participants (platform)', 'SELECT id, platform_registration_id, status, platform_removed_at, checked_in_at FROM participants WHERE platform_registration_id IS NOT NULL'],
        ['BZ', $ctx['bzPdo'], 'event_platform_connections', 'SELECT event_id, platform_event_id, is_enabled, last_pull_status, last_pull_trigger FROM event_platform_connections'],
    ];
    foreach ($queries as [$side, $pdo, $label, $sql]) {
        e2e_out("--- {$side} {$label}");
        try {
            foreach ($pdo->query($sql)->fetchAll() as $row) {
                e2e_out('    ' . json_encode($row, JSON_UNESCAPED_SLASHES));
            }
        } catch (Throwable $exception) {
            e2e_out('    (query failed: ' . $exception->getMessage() . ')');
        }
    }
}

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
    e2e_scenario($ctx);
} catch (E2eAbort $abort) {
    $exitCode = $abort->exitCode;
    e2e_out(($exitCode === E2E_EXIT_FAILED ? 'FAILED: ' : 'ENVIRONMENT ERROR: ') . $abort->getMessage());
    if ($exitCode === E2E_EXIT_FAILED || is_file($logDir . '/zc-server.log')) {
        e2e_out('--- ZC server log (tail)');
        e2e_out(tail_file($logDir . '/zc-server.log'));
        e2e_out('--- BZ server log (tail)');
        e2e_out(tail_file($logDir . '/bz-server.log'));
    }
    if ($exitCode === E2E_EXIT_FAILED && $ctx !== []) {
        e2e_dump_tables($ctx);
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
foreach (glob($logDir . '/bz-app-logs/*') ?: [] as $logFile) {
    if (is_file($logFile)) {
        file_put_contents($logFile, E2eSecrets::redact((string) file_get_contents($logFile)));
    }
}

if ($exitCode === E2E_EXIT_OK && !$keep && $logDirIsTemporary) {
    remove_dir($logDir);
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

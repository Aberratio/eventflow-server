<?php

declare(strict_types=1);

/**
 * Helpers for the ZC <-> BZ end-to-end runner (tests/e2e/bz/run.php): environment guard,
 * HTTP client, assertions with a failure signal, child-process control and redaction.
 *
 * Exit codes of the runner: 0 passed, 1 assertion failed, 2 environment or preflight error.
 */

const E2E_EXIT_OK = 0;
const E2E_EXIT_FAILED = 1;
const E2E_EXIT_ENVIRONMENT = 2;
const E2E_ENV_MARKER = '# written by tests/e2e/bz/run.php';

final class E2eAbort extends RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode)
    {
        parent::__construct($message);
    }
}

/** Per-run values that must never reach stdout, logs or artifacts. */
final class E2eSecrets
{
    /** @var list<string> */
    private static array $values = [];

    public static function add(string $value): void
    {
        if (strlen($value) >= 8) {
            self::$values[] = $value;
        }
    }

    public static function redact(string $text): string
    {
        foreach (self::$values as $value) {
            $text = str_replace($value, '[redacted]', $text);
        }

        return $text;
    }
}

function e2e_out(string $line): void
{
    fwrite(STDOUT, E2eSecrets::redact($line) . "\n");
}

function e2e_fail_env(string $message): never
{
    throw new E2eAbort($message, E2E_EXIT_ENVIRONMENT);
}

/** Environment variable with a default; an empty string counts as "set" only when $allowEmpty. */
function e2e_env(string $name, ?string $default = null, bool $allowEmpty = false): ?string
{
    $value = getenv($name);
    if ($value === false || ($value === '' && !$allowEmpty)) {
        return $default;
    }

    return $value;
}

// ---------------------------------------------------------------- assertions

final class E2eScenario
{
    public static int $step = 0;
    public static int $passed = 0;
    public static string $section = '';

    public static function section(string $label): void
    {
        self::$section = $label;
        e2e_out('');
        e2e_out('== ' . $label);
    }
}

/** Records one assertion. Throws on failure so the runner can dump diagnostics once and exit 1. */
function expect(bool $condition, string $label, string $detail = ''): void
{
    E2eScenario::$step++;
    $n = E2eScenario::$step;
    if ($condition) {
        E2eScenario::$passed++;
        e2e_out(sprintf('PASS %02d %s', $n, $label));

        return;
    }

    e2e_out(sprintf('FAIL %02d %s [%s]', $n, $label, E2eScenario::$section));
    if ($detail !== '') {
        e2e_out('     ' . str_replace("\n", "\n     ", $detail));
    }

    throw new E2eAbort(sprintf('Assertion failed in %s: %s', E2eScenario::$section, $label), E2E_EXIT_FAILED);
}

// ---------------------------------------------------------------- HTTP

/**
 * @param array<string, string> $headers
 * @param array<string, mixed>|null $json
 * @return array{status: int, body: string, json: mixed}
 */
function http_call(string $method, string $url, array $headers = [], ?array $json = null): array
{
    $ch = curl_init($url);
    $lines = ['Accept: application/json'];
    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }
    if ($json !== null) {
        $lines[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $lines,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new E2eAbort('HTTP transport error for ' . $method . ' ' . $url . ': ' . $error, E2E_EXIT_ENVIRONMENT);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $body, true);

    return ['status' => $status, 'body' => (string) $body, 'json' => $decoded];
}

/** Dotted-path lookup in decoded JSON ("data.participant.id"); null when any segment is missing. */
function jget(mixed $data, string $path): mixed
{
    foreach (explode('.', $path) as $segment) {
        if (!is_array($data) || !array_key_exists($segment, $data)) {
            return null;
        }
        $data = $data[$segment];
    }

    return $data;
}

function describe_response(array $response): string
{
    return 'HTTP ' . $response['status'] . ' ' . substr($response['body'], 0, 400);
}

// ---------------------------------------------------------------- processes

/** @var list<array{proc: resource, name: string}> */
$GLOBALS['e2e_children'] = [];

/**
 * @param list<string> $command
 * @param array<string, string> $env merged over the current environment
 * @return array{status: int, output: string}
 */
function run_cli(array $command, string $cwd, array $env = []): array
{
    $proc = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        array_merge(getenv(), $env)
    );
    if (!is_resource($proc)) {
        throw new E2eAbort('Cannot start: ' . implode(' ', $command), E2E_EXIT_ENVIRONMENT);
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['status' => proc_close($proc), 'output' => trim($stdout . $stderr)];
}

/**
 * Starts php -S in the background, output to $logFile.
 *
 * @param list<string> $phpArgs extra php arguments (e.g. -d extension=sodium)
 * @param array<string, string> $env
 */
function start_php_server(string $name, string $cwd, string $host, int $port, string $docroot, string $router, string $logFile, array $phpArgs, array $env): void
{
    $command = array_merge([PHP_BINARY], $phpArgs, ['-S', $host . ':' . $port]);
    if ($docroot !== '') {
        array_push($command, '-t', $docroot);
    }
    $command[] = $router;

    $log = fopen($logFile, 'ab');
    if ($log === false) {
        e2e_fail_env('Cannot open log file ' . $logFile);
    }
    $proc = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => $log, 2 => $log],
        $pipes,
        $cwd,
        array_merge(getenv(), $env)
    );
    if (!is_resource($proc)) {
        e2e_fail_env('Cannot start ' . $name . ' server');
    }
    $GLOBALS['e2e_children'][] = ['proc' => $proc, 'name' => $name];
}

function stop_children(): void
{
    foreach ($GLOBALS['e2e_children'] as $child) {
        if (is_resource($child['proc'])) {
            $status = proc_get_status($child['proc']);
            if ($status['running'] && PHP_OS_FAMILY === 'Windows') {
                // /T also takes down any worker the built-in server spawned.
                exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' >NUL 2>&1');
            }
            @proc_terminate($child['proc']);
            @proc_close($child['proc']);
        }
    }
    $GLOBALS['e2e_children'] = [];
}

function port_is_free(string $host, int $port): bool
{
    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $error, 0.5);
    if ($socket === false) {
        return true;
    }
    fclose($socket);

    return false;
}

function wait_for_http(string $url, int $seconds): bool
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_CONNECTTIMEOUT => 1]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body !== false && $status === 200) {
            return true;
        }
        usleep(200_000);
    }

    return false;
}

function tail_file(string $path, int $lines = 25): string
{
    if (!is_file($path)) {
        return '(no log)';
    }
    $all = file($path, FILE_IGNORE_NEW_LINES) ?: [];

    return implode("\n", array_slice($all, -$lines));
}

function remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Support\Env;

/**
 * bin/scrub-demo-passwords.php refuses on APP_ENV=local, writes nothing with --dry-run and prints counts only.
 *
 * The CLI reads APP_ENV and the DB_* settings through Env::load(), where a `.env` file beats the process
 * environment (CI and developers have one). The test therefore runs the command with an auto_prepend_file that
 * pins those keys through Env::set() (which Env::load() respects), so the outcome does not depend on a .env.
 * Production code is untouched and the refusal on local stays as it is.
 */
final class ScrubDemoPasswordsCliTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const DB_KEYS = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

    private ?string $prepend = null;

    protected function setUp(): void
    {
        Env::load(self::ROOT . '/.env');
        try {
            Connection::fromEnv();
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
        $this->prepend = tempnam(sys_get_temp_dir(), 'zcscrub');
        file_put_contents($this->prepend, <<<'PHP'
<?php
require getenv('ZCT_ROOT') . '/vendor/autoload.php';
\ZmierzymyCzas\Support\Env::set('APP_ENV', (string) getenv('ZCT_APP_ENV'));
foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
    \ZmierzymyCzas\Support\Env::set($key, (string) getenv('ZCT_' . $key));
}
PHP);
    }

    protected function tearDown(): void
    {
        if ($this->prepend !== null) {
            @unlink($this->prepend);
        }
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function runCli(string $appEnv, string ...$args): array
    {
        $env = getenv();
        $env['ZCT_ROOT'] = (string) realpath(self::ROOT);
        $env['ZCT_APP_ENV'] = $appEnv;
        foreach (self::DB_KEYS as $key) {
            $env['ZCT_' . $key] = (string) Env::get($key, '');
        }
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=' . $this->prepend, self::ROOT . '/bin/scrub-demo-passwords.php', ...$args];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::ROOT, $env);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    public function testRefusesOnLocal(): void
    {
        [$code, , $stderr] = $this->runCli('local');

        self::assertNotSame(0, $code);
        self::assertStringContainsString('Refusing', $stderr);
    }

    public function testDryRunOnStagingCountsAndChangesNothing(): void
    {
        $pdo = Connection::fromEnv();
        $before = (string) $pdo->query('SELECT SUM(CRC32(password_hash)) FROM users')->fetchColumn();

        [$code, $stdout] = $this->runCli('staging', '--dry-run');

        self::assertSame(0, $code, $stdout);
        self::assertMatchesRegularExpression('/Would scrub \d+ account\(s\)\./', $stdout);
        self::assertStringNotContainsString('@', $stdout);
        self::assertStringNotContainsString('$2y$', $stdout);
        self::assertSame($before, (string) $pdo->query('SELECT SUM(CRC32(password_hash)) FROM users')->fetchColumn());
    }

    public function testUnknownOptionIsRejected(): void
    {
        [$code] = $this->runCli('staging', '--nope');

        self::assertSame(1, $code);
    }
}

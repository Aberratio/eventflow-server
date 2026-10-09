<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;

/**
 * PP.90: bin/scrub-demo-passwords.php refuses on APP_ENV=local, writes nothing with --dry-run and prints counts only.
 * Skipped when a developer .env exists (Env::load lets it override the process environment).
 */
final class ScrubDemoPasswordsCliTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    protected function setUp(): void
    {
        if (is_file(self::ROOT . '/.env')) {
            self::markTestSkipped('A local .env overrides APP_ENV for the CLI.');
        }
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function runCli(string $appEnv, string ...$args): array
    {
        $env = getenv() + [];
        $env['APP_ENV'] = $appEnv;
        $command = array_merge([PHP_BINARY, self::ROOT . '/bin/scrub-demo-passwords.php'], $args);
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
        $pdo = \ZmierzymyCzas\Database\Connection::fromEnv();
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

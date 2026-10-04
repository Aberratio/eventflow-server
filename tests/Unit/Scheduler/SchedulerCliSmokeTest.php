<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Scheduler\SchedulerJobs;

/** The real CLI entrypoint. Neither invocation touches the database. */
final class SchedulerCliSmokeTest extends TestCase
{
    public function testListViaRealCliExitsZero(): void
    {
        [$exit, $out] = $this->runCli(['--list']);

        self::assertSame(0, $exit);
        self::assertSame(SchedulerJobs::NAMES, array_values(array_filter(explode("\n", str_replace("\r", '', $out)))));
    }

    public function testUnknownOptionViaRealCliExits64(): void
    {
        [$exit] = $this->runCli(['--bogus']);

        self::assertSame(64, $exit);
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function runCli(array $args): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, dirname(__DIR__, 3) . '/bin/scheduler.php'], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out];
    }
}

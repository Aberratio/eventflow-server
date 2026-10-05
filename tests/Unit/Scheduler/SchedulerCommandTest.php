<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZmierzymyCzas\Scheduler\SchedulerCommand;
use ZmierzymyCzas\Scheduler\SchedulerJobs;

final class SchedulerCommandTest extends TestCase
{
    public function testListPrintsJobNamesWithoutDatabase(): void
    {
        ob_start();
        $exit = SchedulerCommand::main(['--list'], new NullLogger());
        $out = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertSame(SchedulerJobs::NAMES, array_values(array_filter(explode("\n", $out))));
    }

    public function testUnknownOptionExits64(): void
    {
        self::assertSame(64, $this->mainQuietly(['--bogus']));
    }

    public function testUnknownJobNameExits64(): void
    {
        self::assertSame(64, $this->mainQuietly(['--job=nope.nothing']));
    }

    public function testJobWiringErrorExitsWithBootstrapCodeAndLogsAlert(): void
    {
        $handler = new \Monolog\Handler\TestHandler();
        $logger = new \Monolog\Logger('test', [$handler]);

        ob_start();
        $exit = SchedulerCommand::main(
            [],
            $logger,
            new \PDO('sqlite::memory:'),
            static function (): array {
                throw new \RuntimeException('bad wiring');
            }
        );
        ob_end_clean();

        self::assertSame(SchedulerCommand::EXIT_BOOTSTRAP, $exit);
        self::assertTrue($handler->hasCriticalThatContains('could not build its jobs'));
    }

    public function testHelpExitsZero(): void
    {
        ob_start();
        $exit = SchedulerCommand::main(['--help'], new NullLogger());
        ob_end_clean();

        self::assertSame(0, $exit);
    }

    /** @param list<string> $args */
    private function mainQuietly(array $args): int
    {
        ob_start();
        try {
            return SchedulerCommand::main($args, new NullLogger());
        } finally {
            ob_end_clean();
        }
    }
}

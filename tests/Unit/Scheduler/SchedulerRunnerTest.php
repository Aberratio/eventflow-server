<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Scheduler;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Scheduler\SchedulerRunner;
use ZmierzymyCzas\Tests\Support\Scheduler\FakeJob;
use ZmierzymyCzas\Tests\Support\Scheduler\FakeLock;
use ZmierzymyCzas\Tests\Support\Scheduler\RecordingRunLog;

final class SchedulerRunnerTest extends TestCase
{
    private TestHandler $handler;
    private Logger $logger;
    private RecordingRunLog $runLog;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
        $this->logger = new Logger('test', [$this->handler]);
        $this->runLog = new RecordingRunLog();
    }

    public function testRunsJobsInOrderAndReturnsZero(): void
    {
        $calls = new \ArrayObject();
        $lock = new FakeLock(true);
        $runner = new SchedulerRunner($lock, $this->runLog, $this->logger, [
            new FakeJob('a', $calls, ['n' => 1]),
            new FakeJob('b', $calls, ['n' => 2]),
            new FakeJob('c', $calls, ['n' => 3]),
        ]);

        self::assertSame(0, $runner->run());
        self::assertSame(['a', 'b', 'c'], $calls->getArrayCopy());
        self::assertSame(['a' => 'ok', 'b' => 'ok', 'c' => 'ok'], $this->runLog->status);
        self::assertSame(1, $lock->released);
    }

    public function testFailingJobIsIsolatedLoggedWithAlertAndExitIsOne(): void
    {
        $calls = new \ArrayObject();
        $runner = new SchedulerRunner(new FakeLock(true), $this->runLog, $this->logger, [
            new FakeJob('a', $calls, []),
            new FakeJob('b', $calls, [], new \RuntimeException('secret-token=abc jane@example.com')),
            new FakeJob('c', $calls, []),
        ]);

        self::assertSame(1, $runner->run());
        self::assertSame(['a', 'b', 'c'], $calls->getArrayCopy(), 'the job after the failing one still runs');
        self::assertSame('failed', $this->runLog->status['b']);
        self::assertSame(\RuntimeException::class, $this->runLog->errorClass['b']);
        self::assertSame('ok', $this->runLog->status['c']);

        $errors = array_values(array_filter(
            $this->handler->getRecords(),
            static fn ($r): bool => $r->level === Level::Error
        ));
        self::assertCount(1, $errors);
        self::assertSame('scheduler.job_failed', $errors[0]->context['alert']);
        self::assertSame('b', $errors[0]->context['job']);
    }

    public function testLockHeldSkipsAllJobsAndReturnsZero(): void
    {
        $calls = new \ArrayObject();
        $lock = new FakeLock(false);
        $runner = new SchedulerRunner($lock, $this->runLog, $this->logger, [new FakeJob('a', $calls, [])]);

        self::assertSame(0, $runner->run());
        self::assertSame([], $calls->getArrayCopy());
        self::assertSame([], $this->runLog->status);
        self::assertTrue($this->handler->hasWarningThatContains('Scheduler run skipped'));
        self::assertSame(0, $lock->released, 'a lock that was never acquired is not released');
    }

    public function testLockIsReleasedEvenWhenAJobThrows(): void
    {
        $calls = new \ArrayObject();
        $lock = new FakeLock(true);
        $runner = new SchedulerRunner($lock, $this->runLog, $this->logger, [
            new FakeJob('a', $calls, [], new \LogicException('boom')),
        ]);

        $runner->run();

        self::assertSame(1, $lock->released);
    }

    public function testSkippedCountersRecordSkippedStatus(): void
    {
        $calls = new \ArrayObject();
        $runner = new SchedulerRunner(new FakeLock(true), $this->runLog, $this->logger, [
            new FakeJob('a', $calls, ['skipped' => 1]),
        ]);

        self::assertSame(0, $runner->run());
        self::assertSame('skipped', $this->runLog->status['a']);
    }

    public function testOnlyRunsTheRequestedJob(): void
    {
        $calls = new \ArrayObject();
        $runner = new SchedulerRunner(new FakeLock(true), $this->runLog, $this->logger, [
            new FakeJob('a', $calls, []),
            new FakeJob('b', $calls, []),
        ]);

        $runner->run('b');

        self::assertSame(['b'], $calls->getArrayCopy());
    }
}

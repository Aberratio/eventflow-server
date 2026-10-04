<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Scheduler;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Scheduler\MysqlAdvisoryLock;
use ZmierzymyCzas\Scheduler\SchedulerCommand;
use ZmierzymyCzas\Scheduler\SchedulerJobs;
use ZmierzymyCzas\Support\Env;

final class SchedulerCommandIntegrationTest extends TestCase
{
    private PDO $pdo;
    private TestHandler $handler;
    private Logger $logger;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        $this->pdo = Connection::fromEnv();
        // Outside any try/catch: a broken migration must fail, not skip.
        Migrator::applyAll($this->pdo);
        $this->pdo->exec('DELETE FROM scheduler_job_runs');
        $this->handler = new TestHandler();
        $this->logger = new Logger('test', [$this->handler]);
    }

    public function testFullRunWritesRunLogForEveryJobAndExitsZero(): void
    {
        ob_start();
        $exit = SchedulerCommand::main([], $this->logger, $this->pdo);
        ob_end_clean();

        self::assertSame(0, $exit);
        $rows = $this->pdo->query('SELECT job, last_status, last_error_class FROM scheduler_job_runs ORDER BY job')
            ->fetchAll(PDO::FETCH_ASSOC);
        $byJob = array_column($rows, null, 'job');
        self::assertCount(count(SchedulerJobs::NAMES), $rows);
        foreach (SchedulerJobs::NAMES as $name) {
            self::assertArrayHasKey($name, $byJob);
            self::assertContains($byJob[$name]['last_status'], ['ok', 'skipped'], $name);
            self::assertNull($byJob[$name]['last_error_class']);
        }
    }

    public function testRunWhileLockHeldDoesNothing(): void
    {
        $holderPdo = Connection::fromEnv();
        $holder = new MysqlAdvisoryLock($holderPdo);
        self::assertTrue($holder->acquire());

        try {
            ob_start();
            $exit = SchedulerCommand::main([], $this->logger, $this->pdo);
            $out = (string) ob_get_clean();
        } finally {
            $holder->release();
        }

        self::assertSame(0, $exit);
        self::assertSame('', $out, 'no job line is printed when the lock is held');
        self::assertSame('0', (string) $this->pdo->query('SELECT COUNT(*) FROM scheduler_job_runs')->fetchColumn());
        self::assertTrue($this->handler->hasWarningThatContains('Scheduler run skipped'));
    }
}

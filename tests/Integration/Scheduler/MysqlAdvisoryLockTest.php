<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Scheduler;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Scheduler\MysqlAdvisoryLock;
use ZmierzymyCzas\Support\Env;

final class MysqlAdvisoryLockTest extends TestCase
{
    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
    }

    public function testSecondConnectionCannotAcquireUntilFirstReleasesOrDisconnects(): void
    {
        $lockA = new MysqlAdvisoryLock(Connection::fromEnv());
        $pdoB = Connection::fromEnv();
        $lockB = new MysqlAdvisoryLock($pdoB);

        self::assertTrue($lockA->acquire());
        self::assertFalse($lockB->acquire(), 'a second connection must not get the lock while A holds it');

        $lockA->release();
        self::assertTrue($lockB->acquire(), 'B gets the lock once A released it');

        // B dies WITHOUT an explicit release: the lock must free up on its own.
        unset($lockB, $pdoB);
        $lockC = new MysqlAdvisoryLock(Connection::fromEnv());
        self::assertTrue($lockC->acquire(), 'the lock is freed when the holding connection closes');
        $lockC->release();
    }

    public function testLockNameIsShortAndPerDatabase(): void
    {
        $name = MysqlAdvisoryLock::lockNameFor(Connection::fromEnv());

        self::assertLessThanOrEqual(64, strlen($name));
        self::assertStringStartsWith('zc-scheduler:', $name);
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Auth\LoginThrottleConfig;
use ZmierzymyCzas\Domain\Auth\LoginThrottlePolicy;

final class LoginThrottlePolicyTest extends TestCase
{
    private LoginThrottlePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new LoginThrottlePolicy(new LoginThrottleConfig(3, 10, 900, [60, 300, 900]));
    }

    public function testBelowTheLimitNothingIsLocked(): void
    {
        $state = LoginThrottlePolicy::emptyState();
        for ($i = 0; $i < 2; $i++) {
            $r = $this->policy->afterFailure($state, 3, 1000 + $i);
            self::assertFalse($r['lockedNow']);
            $state = $r['state'];
        }
        self::assertSame(0, $this->policy->retryAfter($state, 1002));
    }

    public function testReachingTheLimitLocksForTheFirstStep(): void
    {
        $state = LoginThrottlePolicy::emptyState();
        $r = null;
        for ($i = 0; $i < 3; $i++) {
            $r = $this->policy->afterFailure($state, 3, 1000 + $i);
            $state = $r['state'];
        }
        self::assertTrue($r['lockedNow']);
        self::assertSame(60, $this->policy->retryAfter($state, 1002));
        self::assertSame(1, $this->policy->retryAfter($state, 1061));
        self::assertSame(0, $this->policy->retryAfter($state, 1062));
    }

    public function testFailuresWhileLockedAreIgnored(): void
    {
        $locked = ['failures' => 0, 'windowStart' => 0, 'level' => 1, 'lockedUntil' => 1100];
        $r = $this->policy->afterFailure($locked, 3, 1050);
        self::assertFalse($r['lockedNow']);
        self::assertSame($locked, $r['state']);
    }

    public function testLockoutGrowsAndTheLastStepRepeats(): void
    {
        $state = LoginThrottlePolicy::emptyState();
        $now = 1000;
        $durations = [];
        for ($lockout = 0; $lockout < 4; $lockout++) {
            for ($i = 0; $i < 3; $i++) {
                $r = $this->policy->afterFailure($state, 3, $now);
                $state = $r['state'];
            }
            $durations[] = $this->policy->retryAfter($state, $now);
            $now = $state['lockedUntil'] + 1;
        }
        self::assertSame([60, 300, 900, 900], $durations);
    }

    public function testWindowExpiryForgetsOldFailures(): void
    {
        $state = LoginThrottlePolicy::emptyState();
        $state = $this->policy->afterFailure($state, 3, 1000)['state'];
        $state = $this->policy->afterFailure($state, 3, 1001)['state'];
        $r = $this->policy->afterFailure($state, 3, 1000 + 900);
        self::assertFalse($r['lockedNow']);
        self::assertSame(1, $r['state']['failures']);
    }

    public function testLockoutLevelResetsAfterAQuietWindow(): void
    {
        $state = ['failures' => 0, 'windowStart' => 0, 'level' => 2, 'lockedUntil' => 2000];
        $now = 2000 + 901;
        for ($i = 0; $i < 3; $i++) {
            $state = $this->policy->afterFailure($state, 3, $now)['state'];
        }
        self::assertSame(60, $this->policy->retryAfter($state, $now));
        self::assertSame(1, $state['level']);
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Auth\JwtService;

final class JwtServiceTest extends TestCase
{
    public function testItIssuesAndDecodesToken(): void
    {
        $service = new JwtService('test-secret', 3600);

        $token = $service->issue(['sub' => 'u-admin', 'role' => 'admin']);
        $claims = $service->decode($token);

        self::assertSame('u-admin', $claims['sub']);
        self::assertSame('admin', $claims['role']);
        self::assertArrayHasKey('exp', $claims);
    }
}

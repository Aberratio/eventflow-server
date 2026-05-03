<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Support\Uuid;

final class UuidTest extends TestCase
{
    public function testItGeneratesUuidV4String(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            Uuid::v4()
        );
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/** Every migration must be valid UTF-8 (the DB connection is utf8mb4); a Windows-1250 file corrupts Polish text silently. */
final class MigrationEncodingTest extends TestCase
{
    public function testEveryMigrationIsValidUtf8WithoutBom(): void
    {
        $files = glob(__DIR__ . '/../../../database/migrations/*.sql');
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $bytes = (string) file_get_contents($file);
            self::assertTrue(mb_check_encoding($bytes, 'UTF-8'), basename($file) . ' is not valid UTF-8');
            self::assertStringStartsNotWith("\xEF\xBB\xBF", $bytes, basename($file) . ' has a BOM');
        }
    }
}

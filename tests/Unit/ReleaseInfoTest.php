<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Support\ReleaseInfo;

final class ReleaseInfoTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'release');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testReadsTheVersionAndShortCommitOfATaggedDeploy(): void
    {
        file_put_contents($this->path, "DEPLOY_ID=production-12-6d9302e\nGIT_SHA=6d9302e4c1b0a7f3e2d1c0b9a8f7e6d5c4b3a291\nVERSION=v2026.10.0\n");

        self::assertSame(['version' => '2026.10.0', 'commit' => '6d9302e'], ReleaseInfo::fromFile($this->path));
    }

    public function testAnUntaggedDeployHasACommitButNoVersion(): void
    {
        file_put_contents($this->path, "DEPLOY_ID=staging-13-6d9302e\nGIT_SHA=6d9302e4c1b0a7f3e2d1c0b9a8f7e6d5c4b3a291\nVERSION=\n");

        self::assertSame(['version' => null, 'commit' => '6d9302e'], ReleaseInfo::fromFile($this->path));
    }

    public function testAMarkerFromBeforeVersionsStillGivesTheCommit(): void
    {
        file_put_contents($this->path, "DEPLOY_ID=staging-1-aaaaaaa\nGIT_SHA=aaaaaaabbbbbbb\n");

        self::assertSame(['version' => null, 'commit' => 'aaaaaaa'], ReleaseInfo::fromFile($this->path));
    }

    public function testAMissingMarkerGivesNulls(): void
    {
        self::assertSame(['version' => null, 'commit' => null], ReleaseInfo::fromFile($this->path . '-missing'));
    }
}

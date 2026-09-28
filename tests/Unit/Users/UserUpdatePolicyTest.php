<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Users;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Users\UserUpdatePolicy;

final class UserUpdatePolicyTest extends TestCase
{
    public function testSelfMayEditEveryProfileFormField(): void
    {
        $payload = array_fill_keys(UserUpdatePolicy::SELF_EDITABLE, 'x');
        $result = UserUpdatePolicy::filter($payload, false, 'current@example.test');

        self::assertSame($payload, $result['allowed']);
        self::assertSame([], $result['rejected']);
    }

    /** @dataProvider forbiddenSelfKeys */
    public function testSelfCannotTouchRoleStatusLimitsOrCredits(string $key): void
    {
        $result = UserUpdatePolicy::filter([$key => 'anything'], false, 'current@example.test');

        self::assertSame([], $result['allowed']);
        self::assertSame([$key], $result['rejected']);
    }

    public static function forbiddenSelfKeys(): array
    {
        return [
            ['role'],
            ['status'],
            ['eventLimit'],
            ['organizerPackageCredits'],
            ['password'],
            ['passwordHash'],
            ['id'],
        ];
    }

    public function testSelfUnchangedEmailIsSilentlyDropped(): void
    {
        $result = UserUpdatePolicy::filter(['email' => '  Current@Example.Test  '], false, 'current@example.test');

        self::assertArrayNotHasKey('email', $result['allowed']);
        self::assertSame([], $result['rejected']);
    }

    public function testSelfChangedEmailIsRejected(): void
    {
        $result = UserUpdatePolicy::filter(['email' => 'new@example.test'], false, 'current@example.test');

        self::assertArrayNotHasKey('email', $result['allowed']);
        self::assertSame(['email'], $result['rejected']);
    }

    public function testManagerMayChangeEmailAndAvatarButNeverRole(): void
    {
        $result = UserUpdatePolicy::filter(
            ['email' => 'new@example.test', 'avatar' => 'a.png', 'role' => 'admin'],
            true,
            'current@example.test'
        );

        self::assertSame(['email' => 'new@example.test', 'avatar' => 'a.png'], $result['allowed']);
        self::assertSame(['role'], $result['rejected']);
    }
}

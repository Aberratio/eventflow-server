<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Auth\PasswordRule;

final class PasswordRuleTest extends TestCase
{
    public function testAcceptsAValidPassword(): void
    {
        self::assertNull(PasswordRule::violation('abcdefgh', 'demo1234'));
    }

    public function testTooShort(): void
    {
        self::assertSame(
            ['code' => 'password_too_short', 'message' => 'Nowe hasło musi mieć co najmniej 8 znaków.'],
            PasswordRule::violation('abcdefg', 'demo1234')
        );
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        // 7 multibyte characters are 14 bytes but still too short; 8 are enough.
        self::assertSame('password_too_short', PasswordRule::violation('żółwiki', null)['code'] ?? null);
        self::assertNull(PasswordRule::violation('żółwikiX', null));
    }

    public function testTooLongAtTheSeventyTwoByteBoundary(): void
    {
        self::assertNull(PasswordRule::violation(str_repeat('a', 72), null));
        self::assertSame(
            ['code' => 'password_too_long', 'message' => 'Nowe hasło jest za długie.'],
            PasswordRule::violation(str_repeat('a', 73), null)
        );
        // 36 two-byte characters are exactly 72 bytes, 37 are 74.
        self::assertNull(PasswordRule::violation(str_repeat('ż', 36), null));
        self::assertSame('password_too_long', PasswordRule::violation(str_repeat('ż', 37), null)['code'] ?? null);
    }

    public function testUnchangedOnlyWhenAnOldPasswordIsGiven(): void
    {
        self::assertSame(
            ['code' => 'password_unchanged', 'message' => 'Nowe hasło musi różnić się od obecnego.'],
            PasswordRule::violation('demo1234', 'demo1234')
        );
        self::assertNull(PasswordRule::violation('demo1234', null));
    }

    public function testShortWinsOverUnchanged(): void
    {
        self::assertSame('password_too_short', PasswordRule::violation('abc', 'abc')['code'] ?? null);
    }
}

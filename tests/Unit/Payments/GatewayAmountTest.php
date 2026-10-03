<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Payments;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Payments\GatewayAmount;

final class GatewayAmountTest extends TestCase
{
    /** @return array<string, array{mixed, ?int}> */
    public static function amountsProvider(): array
    {
        return [
            'two decimals string' => ['80.00', 8000],
            'one decimal string' => ['80.5', 8050],
            'padded string' => [' 55.00 ', 5500],
            'float' => [99.99, 9999],
            'int' => [80, 8000],
            'zero string' => ['0', 0],
            'zero float' => [0.0, 0],
            'comma decimal' => ['55,00', null],
            'negative string' => ['-1', null],
            'negative int' => [-1, null],
            'negative float' => [-0.5, null],
            'three decimals' => ['1.234', null],
            'three decimals float' => [1.234, null],
            'exponent' => ['1e3', null],
            'leading zero' => ['01.00', null],
            'empty' => ['', null],
            'null' => [null, null],
            'array' => [[], null],
            'bool' => [true, null],
            'nan' => [NAN, null],
            'inf' => [INF, null],
            'trailing newline' => ["80.00\n", 8000],
        ];
    }

    #[DataProvider('amountsProvider')]
    public function testToGrosze(mixed $value, ?int $expected): void
    {
        self::assertSame($expected, GatewayAmount::toGrosze($value));
    }
}

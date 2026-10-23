<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Support\CsvCell;

final class CsvCellTest extends TestCase
{
    public function testPhoneNumbersStayReadable(): void
    {
        self::assertSame('+48 600 100 200', CsvCell::safe('+48 600 100 200'));
        self::assertSame('+48600100200', CsvCell::safe('+48600100200'));
    }

    public function testFormulasStartingWithPlusAreStillNeutralised(): void
    {
        self::assertSame("'+SUM(1,1)", CsvCell::safe('+SUM(1,1)'));
        self::assertSame("'+1+cmd|' /C calc'!A0", CsvCell::safe("+1+cmd|' /C calc'!A0"));
    }

    public function testNumbersStayNumeric(): void
    {
        self::assertSame('-12,50', CsvCell::safe('-12,50'));
        self::assertSame("'-1+1", CsvCell::safe('-1+1'));
    }
}

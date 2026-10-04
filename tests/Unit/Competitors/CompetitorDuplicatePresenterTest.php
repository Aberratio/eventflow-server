<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Competitors;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Competitors\CompetitorDuplicatePresenter;

final class CompetitorDuplicatePresenterTest extends TestCase
{
    public function testKeepsOnlyIdNameAndBirthDate(): void
    {
        $out = CompetitorDuplicatePresenter::present([
            5 => ['id' => 'a', 'firstName' => 'Jan', 'lastName' => 'Kowalski', 'birthDate' => '1990-01-01',
                'email' => 'x@example.test', 'phone' => '123', 'identityCode' => 'SECRET', 'userId' => 'u1'],
            9 => ['id' => 'b', 'firstName' => 'Ann', 'lastName' => 'Nowak', 'birthDate' => null, 'identityCode' => 'S2'],
        ]);

        self::assertSame([
            ['id' => 'a', 'firstName' => 'Jan', 'lastName' => 'Kowalski', 'birthDate' => '1990-01-01'],
            ['id' => 'b', 'firstName' => 'Ann', 'lastName' => 'Nowak', 'birthDate' => null],
        ], $out);
    }
}

<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Registrations;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Registrations\AgeCategoryResolver;
use ZmierzymyCzas\Support\InputValidationException;

final class AgeCategoryResolverTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function age(): array
    {
        return [
            ['id' => 'k', 'name' => 'K-KIDS', 'gender' => 'K', 'birthYearFrom' => 2014, 'birthYearTo' => 2016],
            ['id' => 'm', 'name' => 'M30', 'gender' => 'M', 'birthYearFrom' => 1986, 'birthYearTo' => 1995],
            ['id' => 'young', 'name' => 'YOUNG', 'gender' => '', 'birthYearFrom' => null, 'birthYearTo' => 2000],
            ['id' => 'open', 'name' => 'OPEN'],
        ];
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (InputValidationException $e) {
            self::assertSame('category', $e->field);
            return $e->errorCode;
        }
        self::fail('Expected InputValidationException');
    }

    public function testInclusiveBounds(): void
    {
        self::assertSame('M30', AgeCategoryResolver::resolve('', 1986, 'M', $this->age(), [], []));
        self::assertSame('M30', AgeCategoryResolver::resolve('', 1995, 'M', $this->age(), [], []));
        self::assertSame('YOUNG', AgeCategoryResolver::resolve('', 1996, 'M', $this->age(), [], []));
    }

    public function testOpenEndedBoundsAndFirstMatchWins(): void
    {
        self::assertSame('YOUNG', AgeCategoryResolver::resolve('', 1900, null, $this->age(), [], []));
        self::assertSame('YOUNG', AgeCategoryResolver::resolve('', 2000, null, $this->age(), [], []));
        self::assertSame('OPEN', AgeCategoryResolver::resolve('', 2001, null, $this->age(), [], []));
        self::assertSame('K-KIDS', AgeCategoryResolver::resolve('', 2015, 'K', $this->age(), [], []));
    }

    public function testNullGenderNeverMatchesGenderSpecificCategory(): void
    {
        $only = [['name' => 'M-ONLY', 'gender' => 'M', 'birthYearFrom' => 1980, 'birthYearTo' => 2000]];
        self::assertSame('registration_category_unavailable', $this->code(fn () => AgeCategoryResolver::resolve('', 1990, null, $only, [], [])));
        self::assertSame('M-ONLY', AgeCategoryResolver::resolve('', 1990, 'M', $only, [], []));
    }

    public function testUnknownBirthYearAndNoFit(): void
    {
        self::assertSame('registration_birth_date_required', $this->code(fn () => AgeCategoryResolver::resolve('', null, 'M', $this->age(), [], [])));
        $only = [['name' => 'M-ONLY', 'gender' => 'M', 'birthYearFrom' => 1980, 'birthYearTo' => 2000]];
        self::assertSame('registration_category_unavailable', $this->code(fn () => AgeCategoryResolver::resolve('', 2015, 'M', $only, [], [])));
    }

    public function testSpecialCategoriesFilteredAndDeduplicated(): void
    {
        $special = [['name' => 'Weteran'], ['name' => 'Junior']];
        self::assertSame('M30, Weteran, Junior', AgeCategoryResolver::resolve('K50+, Weteran, Junior, Weteran, M30', 1990, 'M', $this->age(), [], $special));
        self::assertSame('M30, Weteran', AgeCategoryResolver::resolve('Weteran', 1990, 'M', $this->age(), [], $special));
        self::assertSame('registration_category_invalid', $this->code(fn () => AgeCategoryResolver::resolve('M30, Fake', 1990, 'M', $this->age(), [], $special)));
        self::assertSame('registration_category_invalid', $this->code(fn () => AgeCategoryResolver::resolve('M30, Weteran', 1990, 'M', $this->age(), [], [])));
    }

    public function testLegacyAllowlist(): void
    {
        self::assertSame('Elite', AgeCategoryResolver::resolve(' Elite ', null, null, [], ['Open', 'Elite'], []));
        self::assertSame('registration_category_invalid', $this->code(fn () => AgeCategoryResolver::resolve('Hacker', null, null, [], ['Open', 'Elite'], [])));
    }

    public function testNothingConfiguredStoresEmpty(): void
    {
        self::assertSame('', AgeCategoryResolver::resolve('Anything', 1990, 'M', [], [], []));
        self::assertSame('', AgeCategoryResolver::resolve('Anything', null, null, null, null, null));
    }

    public function testOverlongCategoryIsRejected(): void
    {
        $special = [['name' => str_repeat('W', 76)]];
        self::assertSame('registration_category_invalid', $this->code(fn () => AgeCategoryResolver::resolve('x, ' . str_repeat('W', 76), 1990, 'M', $this->age(), [], $special)));
    }

    public function testMalformedJsonAndNonArrayElements(): void
    {
        self::assertSame('', AgeCategoryResolver::resolve('x', 1990, 'M', '{not json', '[[', 'garbage'));
        $json = json_encode([5, 'x', null, ['name' => 'OK', 'gender' => '']]);
        self::assertSame('OK', AgeCategoryResolver::resolve('', 1990, 'M', $json, [], []));
    }
}

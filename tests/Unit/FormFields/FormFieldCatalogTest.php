<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\FormFields;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\FormFields\FormFieldCatalog;

/**
 * model-formularza-zapisow.md §2/§3: zamknięty katalog 13 pozycji, trzy klasy
 * sterowania.
 */
final class FormFieldCatalogTest extends TestCase
{
    public function testCatalogHasExactlyThirteenPositions(): void
    {
        self::assertCount(13, FormFieldCatalog::systemPositions());
        self::assertCount(13, FormFieldCatalog::systemKeys());
    }

    public function testKeysAreUnique(): void
    {
        $keys = FormFieldCatalog::systemKeys();
        self::assertCount(count($keys), array_unique($keys));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function storageLocationProvider(): array
    {
        return [
            'first_name is Zawodnik' => ['first_name', FormFieldCatalog::STORAGE_COMPETITOR, FormFieldCatalog::CONTROL_LOCKED],
            'last_name is Zawodnik' => ['last_name', FormFieldCatalog::STORAGE_COMPETITOR, FormFieldCatalog::CONTROL_LOCKED],
            'contact_email is Zapis' => ['contact_email', FormFieldCatalog::STORAGE_REGISTRATION, FormFieldCatalog::CONTROL_LOCKED],
            'birth_date is Zawodnik' => ['birth_date', FormFieldCatalog::STORAGE_COMPETITOR, FormFieldCatalog::CONTROL_AGE_DRIVEN],
            'gender is Zawodnik' => ['gender', FormFieldCatalog::STORAGE_COMPETITOR, FormFieldCatalog::CONTROL_AGE_DRIVEN],
            'anonymization is Zawodnik' => ['anonymization', FormFieldCatalog::STORAGE_COMPETITOR, FormFieldCatalog::CONTROL_FREE],
            'club is Zapis' => ['club', FormFieldCatalog::STORAGE_REGISTRATION, FormFieldCatalog::CONTROL_FREE],
            'phone is Zawodnik' => ['phone', FormFieldCatalog::STORAGE_COMPETITOR, FormFieldCatalog::CONTROL_FREE],
        ];
    }

    /** @dataProvider storageLocationProvider */
    public function testStorageLocationAndControlClass(string $key, string $expectedLocation, string $expectedClass): void
    {
        $position = FormFieldCatalog::find($key);
        self::assertNotNull($position);
        self::assertSame($expectedLocation, $position['storageLocation']);
        self::assertSame($expectedClass, $position['controlClass']);
    }

    public function testLockedFieldsOnlyAllowRequired(): void
    {
        foreach (['first_name', 'last_name', 'contact_email'] as $key) {
            self::assertSame([FormFieldCatalog::STATE_REQUIRED], FormFieldCatalog::allowedStates($key));
        }
    }

    /**
     * Data urodzenia/Płeć są dziś zawsze wymagane — odblokowanie Nie/Pokaż
     * wymagałoby klasyfikacji wiekowej Dystansu, która nie jest jeszcze wpięta
     * w tym kodzie (udokumentowana luka, patrz FormFieldCatalog::allowedStates()).
     */
    public function testAgeDrivenFieldsAreAlwaysRequiredUntilAgeClassificationIsWired(): void
    {
        self::assertSame([FormFieldCatalog::STATE_REQUIRED], FormFieldCatalog::allowedStates('birth_date'));
        self::assertSame([FormFieldCatalog::STATE_REQUIRED], FormFieldCatalog::allowedStates('gender'));
    }

    public function testFreeFieldsAllowAllThreeStates(): void
    {
        self::assertSame(
            [FormFieldCatalog::STATE_HIDDEN, FormFieldCatalog::STATE_SHOWN, FormFieldCatalog::STATE_REQUIRED],
            FormFieldCatalog::allowedStates('phone')
        );
    }

    /** Anonimizacja jest wyjątkiem wśród swobodnych: tylko Nie/Pokaż (model §2). */
    public function testAnonymizationOnlyAllowsHiddenOrShown(): void
    {
        self::assertSame(FormFieldCatalog::CONTROL_FREE, FormFieldCatalog::controlClassFor('anonymization'));
        self::assertSame([FormFieldCatalog::STATE_HIDDEN, FormFieldCatalog::STATE_SHOWN], FormFieldCatalog::allowedStates('anonymization'));
        self::assertNotContains(FormFieldCatalog::STATE_REQUIRED, FormFieldCatalog::allowedStates('anonymization'));
    }

    public function testIsSystemKeyRecognisesCatalogOnly(): void
    {
        self::assertTrue(FormFieldCatalog::isSystemKey('phone'));
        self::assertFalse(FormFieldCatalog::isSystemKey('custom_meal_preference'));
    }

    public function testVoivodeshipsAreAClosedListOfSixteen(): void
    {
        $list = FormFieldCatalog::voivodeships();
        self::assertCount(16, $list);
        self::assertCount(16, array_unique($list));
        self::assertContains('mazowieckie', $list);
    }
}

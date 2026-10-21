<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\FormFields;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\FormFields\FormFieldType;

/**
 * Form-field model: seven custom field types, validation
 * derived from the type alone (no custom regexes/formats).
 */
final class FormFieldTypeTest extends TestCase
{
    public function testExactlySevenTypesInClosedCatalog(): void
    {
        self::assertCount(7, FormFieldType::all());
        self::assertTrue(FormFieldType::isValid(FormFieldType::SHORT_TEXT));
        self::assertFalse(FormFieldType::isValid('file'));
    }

    public function testShortTextRejectsOverMaxLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormFieldType::validateAnswer(FormFieldType::SHORT_TEXT, 'za dlugi tekst', ['maxLength' => 5], [], false);
    }

    public function testShortTextAcceptsWithinMaxLength(): void
    {
        FormFieldType::validateAnswer(FormFieldType::SHORT_TEXT, 'ok', ['maxLength' => 5], [], false);
        $this->addToAssertionCount(1);
    }

    public function testLongTextRejectsOverMaxLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormFieldType::validateAnswer(FormFieldType::LONG_TEXT, str_repeat('a', 501), ['maxLength' => 500], [], false);
    }

    public function testNumberRejectsNonNumeric(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormFieldType::validateAnswer(FormFieldType::NUMBER, 'abc', [], [], false);
    }

    public function testNumberRejectsOutsideRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormFieldType::validateAnswer(FormFieldType::NUMBER, 150, ['min' => 0, 'max' => 100], [], false);
    }

    public function testNumberAcceptsWithinRange(): void
    {
        FormFieldType::validateAnswer(FormFieldType::NUMBER, 42, ['min' => 0, 'max' => 100], [], false);
        $this->addToAssertionCount(1);
    }

    public function testDateRejectsMalformedValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormFieldType::validateAnswer(FormFieldType::DATE, 'not-a-date', [], [], false);
    }

    public function testDateRejectsOutsideRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormFieldType::validateAnswer(FormFieldType::DATE, '2030-01-01', ['min' => '2020-01-01', 'max' => '2025-01-01'], [], false);
    }

    public function testDateAcceptsWithinRange(): void
    {
        FormFieldType::validateAnswer(FormFieldType::DATE, '2022-06-01', ['min' => '2020-01-01', 'max' => '2025-01-01'], [], false);
        $this->addToAssertionCount(1);
    }

    private const OPTIONS = [
        ['key' => 'veg', 'label' => 'Wegetariański', 'price' => 0.0],
        ['key' => 'meat', 'label' => 'Mięsny', 'price' => 0.0],
    ];

    public function testSingleChoiceRejectsUnknownOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormFieldType::validateAnswer(FormFieldType::SINGLE_CHOICE, 'sushi', [], self::OPTIONS, false);
    }

    public function testSingleChoiceAcceptsKnownOption(): void
    {
        FormFieldType::validateAnswer(FormFieldType::SINGLE_CHOICE, 'veg', [], self::OPTIONS, false);
        $this->addToAssertionCount(1);
    }

    public function testMultiChoiceRejectsAnyUnknownOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormFieldType::validateAnswer(FormFieldType::MULTI_CHOICE, ['veg', 'sushi'], [], self::OPTIONS, false);
    }

    public function testMultiChoiceAcceptsAllKnownOptions(): void
    {
        FormFieldType::validateAnswer(FormFieldType::MULTI_CHOICE, ['veg', 'meat'], [], self::OPTIONS, false);
        $this->addToAssertionCount(1);
    }

    public function testConsentRequiredRejectsUnchecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormFieldType::validateAnswer(FormFieldType::CONSENT, false, [], [], true);
    }

    public function testConsentRequiredAcceptsChecked(): void
    {
        FormFieldType::validateAnswer(FormFieldType::CONSENT, true, [], [], true);
        $this->addToAssertionCount(1);
    }

    public function testConsentNotRequiredAcceptsUnchecked(): void
    {
        FormFieldType::validateAnswer(FormFieldType::CONSENT, false, [], [], false);
        $this->addToAssertionCount(1);
    }

    public function testRequiredFieldRejectsEmptyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormFieldType::validateAnswer(FormFieldType::SHORT_TEXT, '', [], [], true);
    }

    public function testOptionalFieldAcceptsEmptyValue(): void
    {
        FormFieldType::validateAnswer(FormFieldType::SHORT_TEXT, '', [], [], false);
        FormFieldType::validateAnswer(FormFieldType::MULTI_CHOICE, [], [], self::OPTIONS, false);
        $this->addToAssertionCount(2);
    }
}

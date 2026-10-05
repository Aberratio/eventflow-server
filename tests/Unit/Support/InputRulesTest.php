<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Support\InputRules;
use ZmierzymyCzas\Support\InputValidationException;

final class InputRulesTest extends TestCase
{
    /** @return array<string, array{0: array{0: string, 1: string, 2?: list<string>}, 1: mixed, 2: bool}> rule, value, accepted */
    public static function cases(): array
    {
        $enum = ['a', 'b'];

        return [
            'text! string' => [[InputRules::TEXT_REQUIRED, 'L'], 'x', true],
            'text! int' => [[InputRules::TEXT_REQUIRED, 'L'], 5, true],
            'text! blank' => [[InputRules::TEXT_REQUIRED, 'L'], '  ', false],
            'text! null' => [[InputRules::TEXT_REQUIRED, 'L'], null, false],
            'text! bool' => [[InputRules::TEXT_REQUIRED, 'L'], true, false],
            'text! array' => [[InputRules::TEXT_REQUIRED, 'L'], ['x'], false],
            'text blank ok' => [[InputRules::TEXT, 'L'], '', true],
            'text null' => [[InputRules::TEXT, 'L'], null, false],
            'text array' => [[InputRules::TEXT, 'L'], [], false],
            'text? null' => [[InputRules::TEXT_NULLABLE, 'L'], null, true],
            'text? bool' => [[InputRules::TEXT_NULLABLE, 'L'], false, false],
            'enum ok' => [[InputRules::ENUM, 'L', $enum], 'a', true],
            'enum outside' => [[InputRules::ENUM, 'L', $enum], 'c', false],
            'enum null' => [[InputRules::ENUM, 'L', $enum], null, false],
            'enum? null' => [[InputRules::ENUM_NULLABLE, 'L', $enum], null, true],
            'enum? outside' => [[InputRules::ENUM_NULLABLE, 'L', $enum], 'c', false],
            'number int' => [[InputRules::NUMBER, 'L'], 0, true],
            'number numeric string' => [[InputRules::NUMBER, 'L'], '12.5', true],
            'number abc' => [[InputRules::NUMBER, 'L'], 'abc', false],
            'number negative' => [[InputRules::NUMBER, 'L'], -1, false],
            'number empty string' => [[InputRules::NUMBER, 'L'], '', false],
            'number null' => [[InputRules::NUMBER, 'L'], null, false],
            'number? null' => [[InputRules::NUMBER_NULLABLE, 'L'], null, true],
            'int ok' => [[InputRules::INT, 'L'], 3, true],
            'int digit string' => [[InputRules::INT, 'L'], '7', true],
            'int integral float' => [[InputRules::INT, 'L'], 4.0, true],
            'int 1.5' => [[InputRules::INT, 'L'], 1.5, false],
            'int negative' => [[InputRules::INT, 'L'], -2, false],
            'int null' => [[InputRules::INT, 'L'], null, false],
            'int? null' => [[InputRules::INT_NULLABLE, 'L'], null, true],
            'bool true' => [[InputRules::BOOL, 'L'], true, true],
            'bool 0' => [[InputRules::BOOL, 'L'], 0, true],
            'bool string one' => [[InputRules::BOOL, 'L'], '1', true],
            'bool yes' => [[InputRules::BOOL, 'L'], 'yes', false],
            'bool null' => [[InputRules::BOOL, 'L'], null, false],
            'list? null' => [[InputRules::LIST_NULLABLE, 'L'], null, true],
            'list? list' => [[InputRules::LIST_NULLABLE, 'L'], ['a', ['b']], true],
            'list? assoc' => [[InputRules::LIST_NULLABLE, 'L'], ['a' => 1], false],
            'list? string' => [[InputRules::LIST_NULLABLE, 'L'], 'x', false],
            'ids? ok' => [[InputRules::IDS_NULLABLE, 'L'], ['a', 2], true],
            'ids? nested' => [[InputRules::IDS_NULLABLE, 'L'], ['a', []], false],
            'ids? blank' => [[InputRules::IDS_NULLABLE, 'L'], [' '], false],
            'ids? string' => [[InputRules::IDS_NULLABLE, 'L'], 'x', false],
            'objects? ok' => [[InputRules::OBJECTS_NULLABLE, 'L'], [['a' => 1], []], true],
            'objects? scalar element' => [[InputRules::OBJECTS_NULLABLE, 'L'], ['x'], false],
            'objects? null' => [[InputRules::OBJECTS_NULLABLE, 'L'], null, true],
        ];
    }

    /** @param array{0: string, 1: string, 2?: list<string>} $rule */
    #[DataProvider('cases')]
    public function testRuleAcceptsOrRejectsValue(array $rule, mixed $value, bool $accepted): void
    {
        $run = static fn () => InputRules::check(['f' => $value], ['f' => $rule], true);

        if ($accepted) {
            $run();
            $this->addToAssertionCount(1);

            return;
        }
        try {
            $run();
            self::fail('Expected InputValidationException');
        } catch (InputValidationException $e) {
            self::assertSame('f', $e->field);
            self::assertStringStartsWith('Pole „L” ', $e->getMessage());
        }
    }

    public function testRequiredTextAbsentOnCreateIsRequiredMessage(): void
    {
        try {
            InputRules::check([], ['name' => [InputRules::TEXT_REQUIRED, 'Nazwa']], true);
            self::fail('Expected exception');
        } catch (InputValidationException $e) {
            self::assertSame('name', $e->field);
            self::assertSame('Pole „Nazwa” jest wymagane.', $e->getMessage());
        }
    }

    public function testRequiredTextAbsentOnUpdateIsSkipped(): void
    {
        InputRules::check([], ['name' => [InputRules::TEXT_REQUIRED, 'Nazwa']], false);
        $this->addToAssertionCount(1);
    }

    public function testRequiredTextBlankOrNullPresentIsRequiredAndBoolIsInvalid(): void
    {
        foreach ([[null, 'jest wymagane.'], ['  ', 'jest wymagane.'], [true, 'ma nieprawidłową wartość.']] as [$value, $suffix]) {
            try {
                InputRules::check(['name' => $value], ['name' => [InputRules::TEXT_REQUIRED, 'Nazwa']], false);
                self::fail('Expected exception');
            } catch (InputValidationException $e) {
                self::assertSame('Pole „Nazwa” ' . $suffix, $e->getMessage());
            }
        }
    }

    public function testOtherRulesAreSkippedWhenAbsentEvenOnCreate(): void
    {
        InputRules::check([], ['n' => [InputRules::NUMBER, 'N'], 'e' => [InputRules::ENUM, 'E', ['a']]], true);
        $this->addToAssertionCount(1);
    }

    public function testFirstViolationWinsInRuleOrderAndUnknownKeysAreIgnored(): void
    {
        $rules = ['a' => [InputRules::INT, 'A'], 'b' => [InputRules::INT, 'B']];
        try {
            InputRules::check(['b' => 'x', 'a' => 'y', 'zzz' => []], $rules, true);
            self::fail('Expected exception');
        } catch (InputValidationException $e) {
            self::assertSame('a', $e->field);
        }
    }
}

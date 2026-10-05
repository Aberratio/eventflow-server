<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support;

/**
 * PP.76: pure declarative checker for request bodies that are written straight into NOT NULL,
 * ENUM or numeric columns. A violation throws InputValidationException (rendered as 422) instead
 * of letting strict MySQL fail the INSERT/UPDATE with a 500. Unknown keys are ignored.
 */
final class InputRules
{
    /** string|int|float, non-blank after trim. REQUIRED on create; if present on update it must be non-blank. */
    public const TEXT_REQUIRED = 'text!';
    /** string|int|float (blank ok); null rejected. */
    public const TEXT = 'text';
    public const TEXT_NULLABLE = 'text?';
    /** One of $rule[2]; null rejected. */
    public const ENUM = 'enum';
    public const ENUM_NULLABLE = 'enum?';
    /** int|float|numeric string, >= 0. */
    public const NUMBER = 'number';
    public const NUMBER_NULLABLE = 'number?';
    /** int | digit string | integral float, >= 0. */
    public const INT = 'int';
    public const INT_NULLABLE = 'int?';
    /** true|false|0|1|'0'|'1'. */
    public const BOOL = 'bool';
    /** null or array_is_list() array (elements unchecked). */
    public const LIST_NULLABLE = 'list?';
    /** null or list of non-blank string|int. */
    public const IDS_NULLABLE = 'ids?';
    /** null or list whose every element is an array. */
    public const OBJECTS_NULLABLE = 'objects?';

    /**
     * @param array<string, mixed> $data
     * @param array<string, array{0: string, 1: string, 2?: list<string>}> $rules field => [rule, Polish label, enum values]
     * @throws InputValidationException on the first violation, in $rules order
     */
    public static function check(array $data, array $rules, bool $creating): void
    {
        foreach ($rules as $field => $rule) {
            [$type, $label] = $rule;
            if (!array_key_exists($field, $data)) {
                if ($creating && $type === self::TEXT_REQUIRED) {
                    throw self::required($field, $label);
                }
                continue;
            }

            $value = $data[$field];
            if (self::valid($type, $value, $rule[2] ?? [])) {
                continue;
            }
            if ($type === self::TEXT_REQUIRED && ($value === null || (is_string($value) && trim($value) === ''))) {
                throw self::required($field, $label);
            }
            throw self::invalid($field, $label);
        }
    }

    public static function required(string $field, string $label): InputValidationException
    {
        return new InputValidationException('Pole „' . $label . '” jest wymagane.', $field);
    }

    public static function invalid(string $field, string $label): InputValidationException
    {
        return new InputValidationException('Pole „' . $label . '” ma nieprawidłową wartość.', $field);
    }

    /** @param list<string> $enum */
    private static function valid(string $type, mixed $value, array $enum): bool
    {
        if ($value === null && str_ends_with($type, '?')) {
            return true;
        }

        return match ($type) {
            self::TEXT_REQUIRED => self::isText($value) && trim((string) $value) !== '',
            self::TEXT, self::TEXT_NULLABLE => self::isText($value),
            self::ENUM, self::ENUM_NULLABLE => is_string($value) && in_array($value, $enum, true),
            self::NUMBER, self::NUMBER_NULLABLE => self::isNumber($value),
            self::INT, self::INT_NULLABLE => self::isInt($value),
            self::BOOL => $value === true || $value === false || $value === 0 || $value === 1 || $value === '0' || $value === '1',
            self::LIST_NULLABLE => is_array($value) && array_is_list($value),
            self::IDS_NULLABLE => is_array($value) && array_is_list($value) && self::allIds($value),
            self::OBJECTS_NULLABLE => is_array($value) && array_is_list($value) && self::allArrays($value),
            default => false,
        };
    }

    private static function isText(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value);
    }

    private static function isNumber(mixed $value): bool
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !is_numeric($value)) {
                return false;
            }
            $value = (float) $value;
        }

        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0;
    }

    private static function isInt(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }
        if (is_string($value)) {
            return $value !== '' && ctype_digit($value);
        }
        if (is_float($value)) {
            return is_finite($value) && $value >= 0 && floor($value) === $value;
        }

        return false;
    }

    /** @param array<mixed> $values */
    private static function allIds(array $values): bool
    {
        foreach ($values as $item) {
            if (!(is_string($item) || is_int($item)) || trim((string) $item) === '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<mixed> $values */
    private static function allArrays(array $values): bool
    {
        foreach ($values as $item) {
            if (!is_array($item)) {
                return false;
            }
        }

        return true;
    }
}

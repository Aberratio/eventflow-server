<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\FormFields;

/**
 * Closed catalog of the seven custom-field types. Validation follows
 * from the type alone — there are no custom regular
 * expressions or formats (the client themselves wrote "no validation").
 *
 * There is deliberately no "file" type — permanently left out, not a matter of
 * configuration.
 */
final class FormFieldType
{
    public const SHORT_TEXT = 'short_text';
    public const LONG_TEXT = 'long_text';
    public const NUMBER = 'number';
    public const DATE = 'date';
    public const SINGLE_CHOICE = 'single_choice';
    public const MULTI_CHOICE = 'multi_choice';
    public const CONSENT = 'consent';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SHORT_TEXT, self::LONG_TEXT, self::NUMBER, self::DATE,
            self::SINGLE_CHOICE, self::MULTI_CHOICE, self::CONSENT,
        ];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    public static function usesOptions(string $type): bool
    {
        return in_array($type, [self::SINGLE_CHOICE, self::MULTI_CHOICE], true);
    }

    /**
     * Validates one answer to a custom field. Throws InvalidArgumentException
     * with a Polish message on error, so that routes.php can pass it on
     * unchanged as a 422 (mirrors the validation style of FormFieldRepository/MailTemplateRepository
     * in this repo).
     *
     * @param array{maxLength?: int, min?: int|float, max?: int|float} $validationConfig
     * @param array<int, array{key: string, label: string, price: float}> $options
     */
    public static function validateAnswer(string $type, mixed $value, array $validationConfig, array $options, bool $required): void
    {
        $isEmpty = $value === null || $value === '' || (is_array($value) && $value === []);
        if ($isEmpty) {
            if ($required) {
                throw new \InvalidArgumentException('To pole jest wymagane.');
            }
            return;
        }

        switch ($type) {
            case self::SHORT_TEXT:
            case self::LONG_TEXT:
                $text = (string) $value;
                $maxLength = (int) ($validationConfig['maxLength'] ?? 0);
                if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
                    throw new \InvalidArgumentException(sprintf('Tekst może mieć maksymalnie %d znaków.', $maxLength));
                }
                break;

            case self::NUMBER:
                if (!is_numeric($value)) {
                    throw new \InvalidArgumentException('Wartość musi być liczbą.');
                }
                self::assertInRange((float) $value, $validationConfig, 'Wartość');
                break;

            case self::DATE:
                $date = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);
                if ($date === false) {
                    throw new \InvalidArgumentException('Data musi być w formacie RRRR-MM-DD.');
                }
                if (isset($validationConfig['min']) && (string) $value < (string) $validationConfig['min']) {
                    throw new \InvalidArgumentException(sprintf('Data nie może być wcześniejsza niż %s.', $validationConfig['min']));
                }
                if (isset($validationConfig['max']) && (string) $value > (string) $validationConfig['max']) {
                    throw new \InvalidArgumentException(sprintf('Data nie może być późniejsza niż %s.', $validationConfig['max']));
                }
                break;

            case self::SINGLE_CHOICE:
                $keys = array_column($options, 'key');
                if (!in_array((string) $value, $keys, true)) {
                    throw new \InvalidArgumentException('Wybrana wartość nie znajduje się na liście wariantów.');
                }
                break;

            case self::MULTI_CHOICE:
                $keys = array_column($options, 'key');
                $selected = is_array($value) ? $value : [$value];
                foreach ($selected as $one) {
                    if (!in_array((string) $one, $keys, true)) {
                        throw new \InvalidArgumentException('Wybrana wartość nie znajduje się na liście wariantów.');
                    }
                }
                break;

            case self::CONSENT:
                if (!in_array($value, [true, false, 1, 0, '1', '0'], true)) {
                    throw new \InvalidArgumentException('Zgoda musi być zaznaczona albo nie.');
                }
                if ($required && !in_array($value, [true, 1, '1'], true)) {
                    throw new \InvalidArgumentException('Ta zgoda jest wymagana.');
                }
                break;

            default:
                throw new \InvalidArgumentException('Nieznany typ pola.');
        }
    }

    /** @param array{min?: int|float, max?: int|float} $validationConfig */
    private static function assertInRange(float $value, array $validationConfig, string $label): void
    {
        if (isset($validationConfig['min']) && $value < (float) $validationConfig['min']) {
            throw new \InvalidArgumentException(sprintf('%s nie może być mniejsza niż %s.', $label, $validationConfig['min']));
        }
        if (isset($validationConfig['max']) && $value > (float) $validationConfig['max']) {
            throw new \InvalidArgumentException(sprintf('%s nie może być większa niż %s.', $label, $validationConfig['max']));
        }
    }
}

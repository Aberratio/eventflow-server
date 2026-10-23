<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support;

/** Spreadsheet formula injection guard for CSV cells that carry user- or organizer-controlled text. */
final class CsvCell
{
    /**
     * Prefixes an apostrophe when the text starts with = + - @ TAB or CR, so Excel/LibreOffice
     * show it as text instead of evaluating it as a formula.
     */
    public static function text(string $value): string
    {
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Like text(), but a plain (optionally negative) number such as "-12,50" is a numeric value,
     * not text, and stays untouched. So does a phone number such as "+48 600 100 200": digits and
     * spaces after a plus cannot form a formula.
     */
    public static function safe(string $value): string
    {
        if (preg_match('/^-?\d+(?:[.,]\d+)?$/', $value) === 1 || preg_match('/^\+\d[\d ]*$/', $value) === 1) {
            return $value;
        }

        return self::text($value);
    }
}

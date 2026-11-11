<?php

namespace App\Support\Csv;

/**
 * Streamline S3: the one guard against spreadsheet formula injection in CSV exports (it was copied into fourteen files
 * with two different rules, and missing from some exports). A cell whose text starts with = + - @, a tab or a carriage
 * return is read by spreadsheets as a formula, so it is prefixed with an apostrophe and read as text. Plain numbers
 * (including negative ones such as -5) stay numbers.
 */
final class SpreadsheetCell
{
    public static function guard(string $text): string
    {
        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($text) ? "'".$text : $text;
    }

    /**
     * A row for fputcsv / League\Csv: text values guarded, everything else (numbers, null) as it is.
     *
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    public static function row(array $values): array
    {
        return array_map(fn (mixed $v) => is_string($v) ? self::guard($v) : $v, $values);
    }

    /** Any value as a guarded cell: null as empty, floats with two decimals, everything else as text. */
    public static function of(mixed $value): string
    {
        return self::guard(is_float($value) ? number_format($value, 2, '.', '') : (string) ($value ?? ''));
    }
}

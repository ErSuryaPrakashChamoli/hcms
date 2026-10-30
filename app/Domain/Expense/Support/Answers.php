<?php

namespace App\Domain\Expense\Support;

/** Policy answers as the wizard stores them: a Yes / No radio arrives as true / false or 1 / 0, a select as 'yes' / 'no'. */
final class Answers
{
    public static function yes(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes'], true);
    }

    public static function no(mixed $value): bool
    {
        return in_array($value, [false, 0, '0', 'false', 'no'], true);
    }
}

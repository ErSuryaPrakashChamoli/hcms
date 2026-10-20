<?php

namespace App\Filament\Support;

/**
 * UX.15 closure: PeopleOS writes labels in sentence case ("Leave requests", "API keys"), not Filament's title
 * case ("Leave Requests"). Words written entirely in capitals (API, SSO, TDS, EPF) stay as they are.
 */
final class PeopleOsText
{
    /** "Leave Requests" → "Leave requests"; "API Keys" → "API keys". */
    public static function sentence(string $titleCase): string
    {
        $words = preg_split('/(\s+)/u', trim($titleCase), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        return implode('', array_map(fn (string $w, int $i) => $i === 0 || self::isAcronym($w) ? $w : mb_strtolower($w), $words, array_keys($words)));
    }

    /** For use inside a sentence: "Leave Requests" → "leave requests"; "API Keys" → "API keys". */
    public static function inline(string $titleCase): string
    {
        $words = preg_split('/(\s+)/u', trim($titleCase), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        return implode('', array_map(fn (string $w) => self::isAcronym($w) ? $w : mb_strtolower($w), $words));
    }

    private static function isAcronym(string $word): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $word);

        return mb_strlen($letters) >= 2 && mb_strtoupper($letters) === $letters;
    }
}

<?php

namespace App\Domain\Organisation\Resolution;

use Illuminate\Support\Str;
use Normalizer;

/**
 * How typed values are compared.
 *
 * - key(): the EXACT comparison. Two values with the same key are the same name: Unicode NFKC, the typographic quotes,
 *   dashes and spaces that spreadsheets insert unified, spaces trimmed and collapsed, case folded. Nothing else, so
 *   "Human Resource" and "Human Resources" are different names.
 * - loose() and similarity(): used ONLY to suggest a record for a value that matched nothing. A suggestion is never
 *   applied automatically; a person confirms it.
 */
final class NameKey
{
    private const UNIFY = [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'", "\u{2032}" => "'", '`' => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{2033}" => '"',
        "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
        "\u{00A0}" => ' ', "\u{2007}" => ' ', "\u{202F}" => ' ', "\u{200B}" => '', "\u{FEFF}" => '',
    ];

    /** The exact comparison key; '' for a blank value. */
    public static function key(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $value = strtr($value, self::UNIFY);
        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        }
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }

    /** For suggestions only: punctuation dropped, & read as "and", each word in the singular. */
    public static function loose(?string $value): string
    {
        $value = str_replace('&', ' and ', self::key($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        $words = array_filter(explode(' ', trim($value)), fn (string $w) => $w !== '');

        return implode(' ', array_map(fn (string $w) => mb_strlen($w) > 3 ? Str::singular($w) : $w, $words));
    }

    /**
     * How likely $typed was meant to be $candidate (0 to 1), for suggestions. 0.95 = the same words once punctuation,
     * "&" and plurals are ignored; 0.85 = an abbreviation of the candidate's initials; otherwise the edit-distance ratio of
     * the loose forms, or 0.75 when every typed word appears in the candidate (or the other way round).
     */
    public static function similarity(string $typed, string $candidate): float
    {
        $a = self::loose($typed);
        $b = self::loose($candidate);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 0.95;
        }
        $compactA = str_replace(' ', '', $a);
        $words = explode(' ', $b);
        if (count($words) > 1 && mb_strlen($compactA) >= 2 && mb_strlen($compactA) <= 6
            && $compactA === implode('', array_map(fn (string $w) => mb_substr($w, 0, 1), array_filter($words, fn (string $w) => $w !== 'and' && $w !== 'of')))) {
            return 0.85;
        }
        $ratio = self::ratio($a, $b);
        $wordsA = explode(' ', $a);
        if ($ratio < 0.75 && (array_diff($wordsA, $words) === [] || array_diff($words, $wordsA) === [])) {
            $ratio = 0.75;
        }

        return $ratio;
    }

    private static function ratio(string $a, string $b): float
    {
        $max = max(mb_strlen($a), mb_strlen($b));
        if ($max === 0) {
            return 1.0;
        }
        // levenshtein() works on bytes and stops at 255; both forms are short master names, ASCII in the common case.
        if (strlen($a) > 255 || strlen($b) > 255) {
            return similar_text($a, $b) * 2 / (strlen($a) + strlen($b));
        }

        return 1 - levenshtein($a, $b) / max(strlen($a), strlen($b));
    }
}

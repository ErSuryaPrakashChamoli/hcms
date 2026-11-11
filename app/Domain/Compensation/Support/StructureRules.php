<?php

namespace App\Domain\Compensation\Support;

/**
 * The salary structure builder's rules: the six plain ways a component is worked out, and their translation
 * to and from the formulas payroll runs (FormulaEngine via SalaryComponent::formulaFor). Pure: no database.
 *
 * A rule is a builder row: ['component' => key, 'how' => one of HOWS (or `input` for an employee input component,
 * which formulas read and payroll never pays), 'value' => percent or monthly amount,
 * 'of' => the key of the component a percentage is taken from, 'min' / 'max' => optional monthly limits on a
 * percentage, 'formula' => a custom formula, 'nature' => fixed | variable]. A key is a catalogue component's
 * id ("12") or a component the builder will add ("new:Medical allowance").
 */
final class StructureRules
{
    public const HOWS = [
        'percent_ctc' => '% of CTC',
        'percent_of' => '% of another component',
        'fixed' => 'Fixed amount (same for everyone)',
        'per_employee' => 'Amount entered for each employee',
        'balance' => 'Whatever is left of the CTC',
        'formula' => 'Custom formula (advanced)',
    ];

    /** Ways that need a CTC: a structure built from component amounts has none. */
    public const NEEDS_CTC = ['percent_ctc', 'balance'];

    public const PERCENT = ['percent_ctc', 'percent_of'];

    private const NUMBER = '(\d+(?:\.\d+)?)';

    private const VARIABLE = '([a-z_][a-z0-9_]*)';

    /**
     * The formula a rule runs (null = the amount on each employee's compensation).
     *
     * @param  array<string, mixed>  $rule
     * @param  string|null  $of  the variable name of the component a percentage is taken from
     */
    public static function formula(array $rule, ?string $of = null): ?string
    {
        $how = (string) ($rule['how'] ?? '');
        $base = match ($how) {
            'percent_ctc' => 'ctc_monthly * '.self::number($rule['value'] ?? 0).' / 100',
            'percent_of' => $of.' * '.self::number($rule['value'] ?? 0).' / 100',
            'fixed' => self::number($rule['value'] ?? 0),
            'formula' => trim((string) ($rule['formula'] ?? '')),
            default => null,
        };
        if ($base === null || ! in_array($how, self::PERCENT, true)) {
            return $base;
        }
        [$min, $max] = [self::limit($rule['min'] ?? null), self::limit($rule['max'] ?? null)];

        return match (true) {
            $min !== null && $max !== null => "clamp({$base}, ".self::number($min).', '.self::number($max).')',
            $min !== null => "max({$base}, ".self::number($min).')',
            $max !== null => "min({$base}, ".self::number($max).')',
            default => $base,
        };
    }

    /**
     * The rule a saved structure line reads as. Parameters are put in as numbers, so a line written as
     * ctc_monthly * basic_pct / 100 reads as "% of CTC". Anything that is not one of the plain shapes stays a
     * custom formula (with the parameters put in, so the structure no longer needs them).
     *
     * @param  array<string, string>  $variables  variable name (lowercase code) => key of the builder row
     * @param  array<string, float|int|string>  $parameters
     * @return array{how: string, value: ?float, of: ?string, min: ?float, max: ?float, formula: ?string}
     */
    public static function fromFormula(?string $formula, array $variables, array $parameters = [], bool $balancing = false): array
    {
        $rule = ['how' => 'per_employee', 'value' => null, 'of' => null, 'min' => null, 'max' => null, 'formula' => null];
        if ($balancing) {
            return ['how' => 'balance'] + $rule;
        }
        if ($formula === null || trim($formula) === '') {
            return $rule;
        }
        $inlined = self::inline($formula, $parameters);
        $compact = preg_replace('/\s+/', '', strtolower($inlined));
        $n = self::NUMBER;
        $v = self::VARIABLE;

        if (preg_match('/^max\(0,ctc_monthly(-[a-z_][a-z0-9_]*)+\)$/', $compact)) {
            return ['how' => 'balance'] + $rule;
        }
        if (preg_match("/^{$n}$/", $compact, $m)) {
            return ['how' => 'fixed', 'value' => (float) $m[1]] + $rule;
        }

        [$inner, $min, $max] = [$compact, null, null];
        if (preg_match("/^clamp\((.+),{$n},{$n}\)$/", $compact, $m)) {
            [$inner, $min, $max] = [$m[1], (float) $m[2], (float) $m[3]];
        } elseif (preg_match("/^min\(max\((.+),{$n}\),{$n}\)$/", $compact, $m) || preg_match("/^max\(min\((.+),{$n}\),{$n}\)$/", $compact, $m)) {
            $swap = str_starts_with($compact, 'max(min(');
            [$inner, $min, $max] = [$m[1], (float) ($swap ? $m[3] : $m[2]), (float) ($swap ? $m[2] : $m[3])];
        } elseif (preg_match("/^max\((.+),{$n}\)$/", $compact, $m)) {
            [$inner, $min] = [$m[1], (float) $m[2]];
        } elseif (preg_match("/^max\({$n},(.+)\)$/", $compact, $m)) {
            [$inner, $min] = [$m[2], (float) $m[1]];
        } elseif (preg_match("/^min\((.+),{$n}\)$/", $compact, $m)) {
            [$inner, $max] = [$m[1], (float) $m[2]];
        } elseif (preg_match("/^min\({$n},(.+)\)$/", $compact, $m)) {
            [$inner, $max] = [$m[2], (float) $m[1]];
        }

        $share = match (true) {
            (bool) preg_match("/^ctc_annual\*{$n}\/100\/12$/", $inner, $m) => ['ctc_monthly', (float) $m[1]],
            (bool) preg_match("/^{$v}\*{$n}\/100$/", $inner, $m) => [$m[1], (float) $m[2]],
            (bool) preg_match("/^{$v}\/100\*{$n}$/", $inner, $m) => [$m[1], (float) $m[2]],
            (bool) preg_match("/^{$v}\*{$n}$/", $inner, $m) => [$m[1], round((float) $m[2] * 100, 4)],
            (bool) preg_match("/^{$n}\*{$v}$/", $inner, $m) => [$m[2], round((float) $m[1] * 100, 4)],
            (bool) preg_match("/^{$v}$/", $inner, $m) => [$m[1], 100.0],
            default => null,
        };
        if ($share !== null && $share[0] === 'ctc_monthly') {
            return ['how' => 'percent_ctc', 'value' => $share[1], 'min' => $min, 'max' => $max] + $rule;
        }
        if ($share !== null && isset($variables[$share[0]])) {
            return ['how' => 'percent_of', 'value' => $share[1], 'of' => $variables[$share[0]], 'min' => $min, 'max' => $max] + $rule;
        }

        return ['how' => 'formula', 'formula' => trim($inlined)] + $rule;
    }

    /**
     * The rule in one plain sentence, which the plain-English converter reads back to the same rule.
     *
     * @param  array<string, mixed>  $rule
     * @param  array<string, string>  $names  key => component name
     */
    public static function sentence(array $rule, array $names, ?string $type = null): string
    {
        $name = $names[$rule['component'] ?? ''] ?? 'This component';
        $limits = in_array($rule['how'] ?? null, self::PERCENT, true) ? self::limitsText($rule) : '';
        $text = match ($rule['how'] ?? null) {
            'percent_ctc' => "{$name} is ".self::number($rule['value'] ?? 0).'% of CTC'.$limits,
            'percent_of' => "{$name} is ".self::number($rule['value'] ?? 0).'% of '.($names[$rule['of'] ?? ''] ?? 'another component').$limits,
            'fixed' => "{$name} is a fixed ".self::money($rule['value'] ?? 0).' a month',
            'per_employee' => "{$name} is entered for each employee",
            'input' => "{$name} is entered for each employee and used only in formulas",
            'balance' => "{$name} is whatever is left of the CTC",
            'formula' => "{$name} = ".trim((string) ($rule['formula'] ?? '')),
            default => "{$name} is not set yet",
        };
        if (($rule['nature'] ?? 'fixed') === 'variable') {
            $text .= ', variable';
        }
        if ($type !== null && $type !== 'earning' && ($rule['how'] ?? null) !== 'formula') {
            $text .= ' ('.str_replace('_', ' ', $type).')';
        }

        return $text;
    }

    /** What a rule does, in a few words, for the breakup table. */
    public static function short(array $rule, array $names): string
    {
        $limits = in_array($rule['how'] ?? null, self::PERCENT, true) ? self::limitsText($rule) : '';

        return match ($rule['how'] ?? null) {
            'percent_ctc' => self::number($rule['value'] ?? 0).'% of CTC'.$limits,
            'percent_of' => self::number($rule['value'] ?? 0).'% of '.($names[$rule['of'] ?? ''] ?? '—').$limits,
            'fixed' => 'Fixed '.self::money($rule['value'] ?? 0),
            'per_employee' => 'Per employee',
            'input' => 'Used by formulas',
            'balance' => 'Rest of CTC',
            'formula' => 'Formula',
            default => '—',
        };
    }

    /** The variable names a custom formula reads (for ordering); empty when it does not parse. */
    public static function namesIn(string $formula): array
    {
        preg_match_all('/\b([a-z_][a-z0-9_]*)\b(?!\s*\()/i', preg_replace('/"[^"]*"|\'[^\']*\'/', '', $formula), $m);

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    /** 50 → "50", 8.330 → "8.33", 1600.0 → "1600". */
    public static function number(mixed $value): string
    {
        $number = round((float) $value, 4);
        $text = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    /** 1600 → "1,600", 1250.5 → "1,250.50". */
    public static function money(mixed $value): string
    {
        $value = (float) $value;

        return number_format($value, floor($value) == $value ? 0 : 2);
    }

    private static function limit(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private static function limitsText(array $rule): string
    {
        [$min, $max] = [self::limit($rule['min'] ?? null), self::limit($rule['max'] ?? null)];

        return ($min !== null ? ', at least '.self::money($min) : '').($max !== null ? ', at most '.self::money($max) : '');
    }

    /** Structure parameters written into a formula as numbers. */
    private static function inline(string $formula, array $parameters): string
    {
        if ($parameters === []) {
            return $formula;
        }
        $parameters = array_change_key_case($parameters);

        return preg_replace_callback('/\b[a-z_][a-z0-9_]*\b(?!\s*\()/i', fn (array $m) => array_key_exists(strtolower($m[0]), $parameters) && is_numeric($parameters[strtolower($m[0])])
            ? self::number($parameters[strtolower($m[0])]) : $m[0], $formula);
    }
}

<?php

namespace App\Domain\Compensation\Support;

use App\Domain\Payroll\Services\FormulaEngine;
use RuntimeException;

/**
 * Reads salary components written in plain English, one per line, into builder rules:
 *
 *   Basic is 50% of CTC                       HRA is 40% of Basic, at most 20,000
 *   Conveyance is a fixed 1,600 a month       Medical allowance 15000 per year
 *   Performance bonus is 10% of CTC, variable Food coupons is entered for each employee
 *   Special allowance is whatever is left     The rest goes to Special allowance
 *
 * Deterministic and offline: the same words always give the same rule, nothing leaves PeopleOS, and every
 * line reports what it was read as (or why it was not), so the person checks the result before saving.
 * Component names stay as written here; StructureBuilder matches them to the component list.
 *
 * Each result: ['line', 'name', 'how', 'value', 'of' (a name, or "ctc"), 'min', 'max', 'formula', 'nature',
 * 'type' (null = not said), 'note' (an assumption made), 'skip' (statutory, worked out by payroll), 'error'].
 */
final class PlainEnglishRules
{
    /** Statutory lines payroll works out itself: name pattern => what to tell the person. */
    private const STATUTORY = [
        '/^(employer\'?s?\s+|employee\'?s?\s+)?(pf|epf|provident fund|pf contribution)(\s+(employer|employee|contribution|share))*$/' => 'PF',
        '/^(employer\'?s?\s+|employee\'?s?\s+)?(esi|esic|state insurance)(\s+(employer|employee|contribution|share))*$/' => 'ESI',
        '/^(pt|professional tax|prof\.? tax)$/' => 'Professional tax',
        '/^(tds|income tax|it)$/' => 'Income tax (TDS)',
        '/^(lwf|labour welfare( fund)?|labor welfare( fund)?)$/' => 'Labour welfare fund',
        '/^(edli|pf admin(istration)?( charges)?|admin charges)$/' => 'PF admin and EDLI charges',
    ];

    private const BALANCE = '/\b(balance|balancing|remaining|remainder|the rest|rest of|rest|whatever is left|what is left|whatever left|left ?over|residual|residue)\b/';

    private const PER_EMPLOYEE = '/\b(per employee|each employee|every employee|for each|employee[- ]wise|varies|varying|differs|different for each|as per (?:the )?(?:offer|employee|appointment|actuals?)|entered|individual(?:ly)?|case to case|manual(?:ly)?)\b/';

    private const YEARLY = '/\b(per (?:year|annum)|a year|an? annum|yearly|annually|annual|p\.?\s?a\.?)(?=\W|$)/';

    private const QUARTERLY = '/\b(per quarter|a quarter|quarterly)\b/';

    /** Words that continue the definition before them rather than start a new component. */
    private const MODIFIERS = ['at', 'least', 'most', 'min', 'minimum', 'max', 'maximum', 'up', 'upto', 'capped', 'cap', 'subject', 'not', 'no', 'variable',
        'monthly', 'yearly', 'annually', 'annual', 'per', 'paid', 'which', 'but', 'limited', 'ceiling', 'floor', 'every', 'each', 'rounded', 'taxable', 'a', 'of', 'it'];

    /** @return list<array<string, mixed>> */
    public static function parse(string $text): array
    {
        $out = [];
        foreach (self::clauses($text) as $clause) {
            $out[] = self::read($clause);
        }

        return $out;
    }

    /** @return list<string> the text split into one clause per component */
    public static function clauses(string $text): array
    {
        $clauses = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim(preg_replace('/^\s*(?:[-*•·▪◦]+|\(?\d{1,2}[.)])\s+/u', '', $line));
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $line = self::outside($line, fn (string $plain) => preg_replace('/(?<=\d),(?=\d{2,3}(?!\d))/', '', $plain));
            foreach (self::cut($line, '/;|\.\s+(?=[A-Za-z])/') as $sentence) {
                foreach (self::splitList(rtrim(trim($sentence), '.')) as $part) {
                    if ($part !== '') {
                        $clauses[] = $part;
                    }
                }
            }
        }

        return $clauses;
    }

    /** @return array<string, mixed> */
    public static function read(string $clause): array
    {
        $result = ['line' => $clause, 'name' => null, 'how' => null, 'value' => null, 'of' => null, 'min' => null, 'max' => null,
            'formula' => null, 'nature' => 'fixed', 'type' => null, 'note' => null, 'skip' => null, 'error' => null];
        $normal = self::eitherOf(self::normalise($clause));
        [$name, $definition] = self::split($normal);
        $definition = self::eitherOf($definition);
        if ($name !== null && preg_match('/[a-z_]\(|[*=+<>%]/i', $name)) {
            $name = null; // "max(", "basic * 2": a formula, not a component's name
        }
        $result['name'] = self::prettyName($name ?? $normal);
        $nameKey = strtolower($result['name']);
        foreach (self::STATUTORY as $pattern => $label) {
            if (preg_match($pattern, $nameKey)) {
                return ['skip' => "{$label} is worked out by payroll from the statutory rules; nothing to add."] + $result;
            }
        }
        if ($name === null) {
            return ['name' => null, 'error' => 'Start the line with the component’s name and say how it is worked out, e.g. “Basic is 50% of CTC, at least 15,000”. For a formula, write the name first: “Basic = max(ctc_monthly * 0.5, 15000)”.'] + $result;
        }
        $lower = strtolower($definition);
        $everything = strtolower($normal);

        $result['nature'] = preg_match('/\bvariable\b/', $everything) || preg_match('/\b(variable|incentive|performance|commission)\b/', $nameKey) ? 'variable' : 'fixed';
        $result['type'] = match (true) {
            (bool) preg_match('/\b(deduct(ed|ion)?|recover(y|ed)?)\b/', $everything) => 'deduction',
            (bool) preg_match('/\b(employer\'?s?\s+(contribution|share|cost)|paid by (the )?employer|employer contribution)\b/', $everything) || (bool) preg_match('/\bgratuity\b/', $nameKey) => 'employer_contribution',
            (bool) preg_match('/\breimburs/', $everything) => 'reimbursement',
            default => null,
        };

        if ($formula = self::formulaIn($definition)) {
            return ['how' => 'formula', 'formula' => $formula] + $result;
        }
        if (preg_match(self::BALANCE, $lower)) {
            return ['how' => 'balance'] + $result;
        }
        if (preg_match(self::PER_EMPLOYEE, $lower)) {
            return ['how' => 'per_employee'] + $result;
        }

        [$lower, $min, $max] = self::limits($lower);
        if (preg_match('/(\d+(?:\.\d+)?)\s*%/', $lower, $m, PREG_OFFSET_CAPTURE)) {
            $result['value'] = (float) $m[1][0];
            [$result['min'], $result['max']] = [$min, $max];
            $target = self::target(substr($lower, $m[0][1] + strlen($m[0][0])));
            if ($target === null) {
                return ['how' => 'percent_ctc', 'of' => 'ctc', 'note' => 'No “of …” given, so it is taken as a % of CTC.'] + $result;
            }
            if (preg_match('/^(ctc|cost to (the )?company|annual ctc|monthly ctc|total ctc|ctc amount|package|total package|annual package|fixed ctc|salary|total salary)$/', $target)) {
                return ['how' => 'percent_ctc', 'of' => 'ctc'] + $result;
            }
            if (preg_match('/^(gross|gross salary|gross pay|gross earnings|net|net pay|net salary|take home|take-home)$/', $target)) {
                return ['error' => 'Gross and net pay are added up from the components, so a component cannot be a % of them. Use a % of CTC or of another component, e.g. “'.$result['name'].' is 40% of CTC”.'] + $result;
            }

            return ['how' => 'percent_of', 'of' => self::prettyName($target)] + $result;
        }
        if (preg_match('/\b(per day|a day|daily|per hour|hourly)\b/', $lower)) {
            return ['error' => 'Daily or hourly amounts need a custom formula, e.g. '.$result['name'].' = 200 * paid_days.'] + $result;
        }
        if (preg_match('/(?<![\w.])(\d+(?:\.\d+)?)(?![\w.])/', $lower, $m)) {
            $amount = (float) $m[1];
            $note = null;
            if (preg_match(self::YEARLY, $lower)) {
                $note = 'A yearly '.StructureRules::money($amount).' is paid as '.StructureRules::money(round($amount / 12, 2)).' a month.';
                $amount = round($amount / 12, 2);
            } elseif (preg_match(self::QUARTERLY, $lower)) {
                $note = 'A quarterly '.StructureRules::money($amount).' is paid as '.StructureRules::money(round($amount / 3, 2)).' a month.';
                $amount = round($amount / 3, 2);
            }

            return ['how' => 'fixed', 'value' => $amount, 'note' => $note] + $result;
        }

        return ['error' => 'Could not tell how '.$result['name'].' is worked out. Try “'.$result['name'].' is 10% of CTC”, “'.$result['name'].' is a fixed 2,000 a month”, “'.$result['name'].' is entered for each employee” or “'.$result['name'].' is whatever is left of the CTC”.'] + $result;
    }

    /**
     * "the higher of 50% of CTC and 15000", "50% of CTC or 15000, whichever is higher", "max(50% of CTC, 15000)" and
     * "Max(Basic 50% of CTC, 15000)" → "… 50% of CTC, at least 15000"; the lower / min forms → "at most". A percentage
     * with a floor or a cap, said the way people say it. Anything else is returned unchanged.
     */
    private static function eitherOf(string $text): string
    {
        $floor = ['higher', 'greater', 'bigger', 'larger', 'maximum', 'max', 'more'];
        $parts = null;
        if (preg_match('/^(?<word>max|min|greater|higher|lower|lesser)\s*\(\s*(?<a>.+?)\s*,\s*(?<b>[^,]+?)\s*\)$/i', $text, $m)) {
            $parts = [$m['word'], $m['a'], $m['b'], ''];
        } elseif (preg_match('/^(?<lead>.*?)\b(?:the\s+)?(?<word>higher|greater|bigger|larger|maximum|max|lower|lesser|smaller|minimum|min)\s+of\s+(?<a>.+?)\s+(?:or|and)\s+(?<b>.+?)$/i', $text, $m)) {
            $parts = [$m['word'], $m['a'], $m['b'], $m['lead']];
        } elseif (preg_match('/^(?<a>.+?)\s+or\s+(?<b>.+?),?\s+whichever is (?:the\s+)?(?<word>higher|greater|more|larger|lower|less|smaller)$/i', $text, $m)) {
            $parts = [$m['word'], $m['a'], $m['b'], ''];
        }
        if ($parts === null) {
            return $text;
        }
        [$word, $a, $b, $lead] = $parts;
        [$share, $amount] = str_contains($a, '%') ? [$a, $b] : [$b, $a];
        if (! str_contains($share, '%') || ! preg_match('/^\s*(\d+(?:\.\d+)?)\s*(.*)$/', $amount, $n) || str_contains($amount, '%')) {
            return $text;
        }

        return trim($lead.' '.trim($share).', '.(in_array(strtolower($word), $floor, true) ? 'at least ' : 'at most ').$n[1].' '.$n[2]);
    }

    /** Currency marks, thousands separators, "15k" / "1.5 lakh", "half of" and "percent" put in one form. */
    private static function normalise(string $text): string
    {
        $text = str_replace(['₹', '’', '‘', '“', '”', '–', '—'], [' ', "'", "'", '"', '"', '-', '-'], $text);
        $text = preg_replace('/\b(?:rs\.?|inr|rupees?)\s*(?=\d)/i', '', $text);
        $text = preg_replace('/(?<=\d)\s*(?:rs\.?|inr|rupees?|\/-)(?=\W|$)/i', '', $text);
        $text = self::outside($text, fn (string $plain) => preg_replace('/(?<=\d),(?=\d{2,3}(?!\d))/', '', $plain));
        $text = preg_replace_callback('/(\d+(?:\.\d+)?)\s*(k|thousand|lakhs?|lacs?|l)\b/i', function (array $m) {
            $factor = match (strtolower($m[2])) {
                'k', 'thousand' => 1000, default => 100000
            };

            return StructureRules::number((float) $m[1] * $factor);
        }, $text);
        $text = preg_replace('/\b(\d+(?:\.\d+)?)\s*(?:per\s?cent|percent|pct)\b/i', '$1%', $text);
        $text = preg_replace(['/\bhalf of\b/i', '/\bone[- ]quarter of\b|\ba quarter of\b/i', '/\bone[- ]third of\b|\ba third of\b/i'], ['50% of', '25% of', '33.3333% of'], $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * The component's name and how it is worked out: "Basic is 50% of CTC", "Basic: 50% of CTC",
     * "HRA 40% of basic", or the other way round, "50% of CTC as Basic" / "the rest goes to Special allowance".
     *
     * @return array{0: ?string, 1: string}
     */
    private static function split(string $text): array
    {
        $value = '(?:\d|balance|remaining|remainder|rest|whatever|left|entered|varies|fixed|flat)';
        if (preg_match('/^(?:give|pay|put|keep|allocate|allot|assign|and)?\s*(?:the\s+)?(?<def>(?:\d|balance|remaining|remainder|rest|whatever|what\s+is\s+left|left).*?)\s+(?:as|to|towards|for|goes to|go to|should go to|will go to|into|in)\s+(?:the\s+)?(?<name>[a-z][a-z0-9 &\/\'().-]*?)\s*$/i', $text, $m)
            && ! preg_match('/\d/', $m['name'])) {
            return [$m['name'], $m['def']];
        }
        if (preg_match('/^(?<name>[a-z][a-z0-9 &\/\'().-]*?)\s*(?:\s(?:is|are|will be|should be|shall be|would be|equals|equal to|means|gets|takes|comes to|at|as)\s|[:=]|\s-\s)\s*(?<def>.+)$/i', $text, $m)
            && ! str_starts_with(ltrim($m['def']), '=') && ! preg_match('/\d/', $m['name'])) {
            return [$m['name'], $m['def']];
        }
        if (preg_match("/^(?<name>[a-z][a-z0-9 &\\/'().-]*?)\\s+(?<def>(?:a\\s+)?{$value}.*)$/i", $text, $m)) {
            return [$m['name'], $m['def']];
        }

        return [null, $text];
    }

    /** "at least 15000", "up to 20000 per year", … taken out of the definition, as monthly amounts. @return array{0: string, 1: ?float, 2: ?float} */
    private static function limits(string $text): array
    {
        $amount = '(\d+(?:\.\d+)?)(?![\d.]|\s*%)(\s*(?:per (?:year|annum)|a year|yearly|annually|p\.?\s?a\.?)(?=\W|$))?(?:\s*(?:per month|a month|monthly|p\.?\s?m\.?)(?=\W|$))?';
        $read = function (array $m): float {
            return filled($m[2] ?? null) ? round((float) $m[1] / 12, 2) : (float) $m[1];
        };
        [$min, $max] = [null, null];
        if (preg_match('/\b(?:at least|minimum(?: of)?|min\.?|not less than|no less than|floor of|subject to (?:a )?minimum(?: of)?)\s*(?:of\s*)?'.$amount.'/', $text, $m)) {
            $min = $read($m);
            $text = str_replace($m[0], ' ', $text);
        }
        if (preg_match('/\b(?:at most|maximum(?: of)?|max\.?|up ?to|capped at|capped to|cap of|not more than|no more than|limited to|ceiling of|subject to (?:a )?maximum(?: of)?)\s*(?:of\s*)?'.$amount.'/', $text, $m)) {
            $max = $read($m);
            $text = str_replace($m[0], ' ', $text);
        }

        return [trim(preg_replace('/\s+/', ' ', $text)), $min, $max];
    }

    /** What a percentage is taken of: the words after "% of" up to the next comma or modifier; null when not said. */
    private static function target(string $after): ?string
    {
        $after = trim($after);
        if (! preg_match('/^(?:of|on)?\s*(?:the\s+)?(?<target>[a-z][a-z0-9 &\/\'.-]*)/', $after, $m)) {
            return null;
        }
        $words = [];
        foreach (preg_split('/\s+/', trim($m['target'])) as $word) {
            if (in_array($word, self::MODIFIERS, true) || in_array($word, ['and', 'with', 'for', 'in', 'from', 'paid', 'each', 'month', 'monthly', 'fixed'], true)) {
                break;
            }
            $words[] = $word;
        }
        $target = trim(implode(' ', $words), " .,()'");

        return $target === '' ? null : $target;
    }

    /** A definition that is already a formula (ctc_monthly * 0.5, if(grade == "M1", 5000, 2000)), when it parses. */
    private static function formulaIn(string $definition): ?string
    {
        $expression = trim($definition);
        if (! preg_match('/[*\/()_+]|==|<|>/', $expression) || preg_match('/%/', $expression)) {
            return null;
        }
        try {
            app(FormulaEngine::class)->validate($expression);
        } catch (RuntimeException) {
            return null;
        }

        return $expression;
    }

    /**
     * "Basic 50% of CTC, HRA 40% of basic and conveyance 1600" → one clause per component. A part joins the one before
     * it unless both carry a value and it does not start with a modifier ("…, at most 20000", "…, variable"); commas
     * inside a formula's brackets or quotes never split.
     *
     * @return list<string>
     */
    private static function splitList(string $sentence): array
    {
        $out = [];
        foreach (self::cut($sentence, '/\s*,\s*|\s+and\s+(?=[a-z])/i', true) as [$part, $separator]) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $first = strtolower((string) strtok($part, ' '));
            $previous = $out === [] ? null : $out[count($out) - 1];
            $startsNew = $previous !== null && self::hasValue($previous) && self::hasValue($part) && ! in_array($first, self::MODIFIERS, true) && ! preg_match('/^\d/', $part);
            if ($previous === null || $startsNew) {
                $out[] = $part;
            } else {
                $out[count($out) - 1] = $previous.(str_contains($separator, ',') ? ', ' : ' and ').$part;
            }
        }

        return $out;
    }

    /**
     * Splits text at a separator pattern, but never inside brackets or quotes (a formula's own commas).
     *
     * @return list<string>|list<array{0: string, 1: string}> the pieces, or [piece, separator before it] pairs
     */
    private static function cut(string $text, string $pattern, bool $withSeparators = false): array
    {
        $masked = self::mask($text);
        $pieces = [];
        $offset = 0;
        $separator = '';
        if (preg_match_all($pattern, $masked, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$found, $at]) {
                $pieces[] = [substr($text, $offset, $at - $offset), $separator];
                $separator = $found;
                $offset = $at + strlen($found);
            }
        }
        $pieces[] = [substr($text, $offset), $separator];

        return $withSeparators ? $pieces : array_map(fn (array $piece) => $piece[0], $pieces);
    }

    /** Applies a change to the text outside brackets and quotes only. */
    private static function outside(string $text, callable $change): string
    {
        $masked = self::mask($text);
        $out = '';
        $run = '';
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            if ($masked[$i] === "\0") {
                $out .= $change($run).$text[$i];
                $run = '';
            } else {
                $run .= $text[$i];
            }
        }

        return $out.$change($run);
    }

    /**
     * The text with every character inside a formula's brackets (a "(" straight after a name: max(…), if(…)) or inside
     * double quotes replaced by NUL (same length). A plain aside "(at least 15,000)" is left as it is.
     */
    private static function mask(string $text): string
    {
        $stack = [];
        $calls = 0;
        $quote = false;
        $masked = $text;
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            if ($quote) {
                $masked[$i] = "\0";
                $quote = $c !== '"';

                continue;
            }
            if ($c === '"') {
                $quote = true;
                $masked[$i] = "\0";
            } elseif ($c === '(') {
                $call = $calls > 0 || ($i > 0 && preg_match('/[A-Za-z0-9_]/', $text[$i - 1]));
                $stack[] = $call;
                $calls += $call ? 1 : 0;
                if ($call) {
                    $masked[$i] = "\0";
                }
            } elseif ($c === ')' && $stack !== []) {
                if (array_pop($stack)) {
                    $calls--;
                    $masked[$i] = "\0";
                }
            } elseif ($calls > 0) {
                $masked[$i] = "\0";
            }
        }

        return $masked;
    }

    private static function hasValue(string $text): bool
    {
        return (bool) preg_match('/\d|%|\b(balance|remaining|remainder|rest|whatever|left|entered|varies|per employee|each employee)\b|[*(]/i', $text);
    }

    private static function prettyName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name), " \t.,:;-\"'");
        $name = preg_replace('/^(?:the|a|an|our|my)\s+/i', '', $name);
        $name = preg_replace('/\s+(?:component|amount)$/i', '', $name);
        // Short codes read as codes (hra → HRA, lta → LTA); a name typed in capitals stays so.
        if (preg_match('/^[a-z]{2,3}$/i', $name) || strtoupper($name) === $name) {
            return strtoupper($name);
        }

        return ucfirst($name);
    }
}

<?php

namespace App\Domain\Payroll\Services;

use Closure;
use RuntimeException;

/**
 * A small, safe evaluator for salary formulas (§30): numbers, "text", variables, + - * / %, comparisons,
 * && ||, parentheses and the whitelisted FUNCTIONS. No PHP eval.
 *
 * Text exists to compare employee attributes (grade == "M2", in(work_city, "Mumbai", "Delhi")); it is
 * matched ignoring case and refused in arithmetic. A division by zero poisons only the value it feeds:
 * an if() / lookup() / slab() branch that is not taken does not fail the formula, so
 * if(paid_days > 0, x / paid_days, 0) is safe. The formula fails only when the poison reaches the result.
 */
final class FormulaEngine
{
    /** Function => [minimum, maximum (null = any)] arguments. Described for users in FormulaVariables::FUNCTIONS. */
    public const FUNCTIONS = [
        'min' => [1, null], 'max' => [1, null], 'round' => [1, 2], 'floor' => [1, 1], 'ceil' => [1, 1], 'abs' => [1, 1],
        'if' => [2, 3], 'in' => [2, null], 'between' => [3, 3], 'clamp' => [3, 3],
        'round_to' => [2, 2], 'ceil_to' => [2, 2], 'floor_to' => [2, 2], 'lookup' => [3, null], 'slab' => [3, null],
    ];

    private const PRECEDENCE = ['||' => 1, '&&' => 2, '==' => 3, '!=' => 3, '<' => 4, '<=' => 4, '>' => 4, '>=' => 4, '+' => 5, '-' => 5, '*' => 6, '/' => 6, '%' => 6, 'neg' => 7];

    /**
     * @param  array<string, float|int|string>|Closure(string): (float|int|string|null)  $variables  values or a resolver returning null for unknown names
     */
    public function evaluate(string $formula, array|Closure $variables = []): float
    {
        $tokens = $this->tokenize($formula);
        $rpn = $this->toRpn($tokens);

        return $this->run($rpn, $variables);
    }

    /** Variable names referenced by a formula (for dependency ordering and validation). */
    public function variablesIn(string $formula): array
    {
        return collect($this->tokenize($formula))
            ->filter(fn ($t) => $t['type'] === 'ident' && ! isset(self::FUNCTIONS[strtolower($t['value'])]))
            ->pluck('value')->map(fn ($v) => strtolower($v))->unique()->values()->all();
    }

    /** Throws RuntimeException with a readable message when a formula does not parse. */
    public function validate(string $formula): void
    {
        $this->toRpn($this->tokenize($formula));
    }

    private function tokenize(string $formula): array
    {
        $tokens = [];
        $length = strlen($formula);
        $i = 0;

        while ($i < $length) {
            $c = $formula[$i];

            if (ctype_space($c)) {
                $i++;

                continue;
            }

            if (ctype_digit($c) || ($c === '.' && $i + 1 < $length && ctype_digit($formula[$i + 1]))) {
                $start = $i;
                while ($i < $length && (ctype_digit($formula[$i]) || $formula[$i] === '.')) {
                    $i++;
                }
                $tokens[] = ['type' => 'num', 'value' => (float) substr($formula, $start, $i - $start)];

                continue;
            }

            if (ctype_alpha($c) || $c === '_') {
                $start = $i;
                while ($i < $length && (ctype_alnum($formula[$i]) || $formula[$i] === '_' || $formula[$i] === '.')) {
                    $i++;
                }
                $tokens[] = ['type' => 'ident', 'value' => substr($formula, $start, $i - $start)];

                continue;
            }

            if ($c === '"' || $c === "'") {
                $close = strpos($formula, $c, $i + 1);
                if ($close === false) {
                    throw new RuntimeException("Text is not closed; end it with {$c}.");
                }
                $tokens[] = ['type' => 'str', 'value' => substr($formula, $i + 1, $close - $i - 1)];
                $i = $close + 1;

                continue;
            }

            $two = substr($formula, $i, 2);

            if (in_array($two, ['<=', '>=', '==', '!=', '&&', '||'], true)) {
                $tokens[] = ['type' => 'op', 'value' => $two];
                $i += 2;

                continue;
            }

            if (strpbrk($c, '+-*/%<>') !== false) {
                $tokens[] = ['type' => 'op', 'value' => $c];
                $i++;

                continue;
            }

            if ($c === '(' || $c === ')' || $c === ',') {
                $tokens[] = ['type' => $c, 'value' => $c];
                $i++;

                continue;
            }

            throw new RuntimeException("Unexpected character '{$c}' in formula.");
        }

        return $tokens;
    }

    /** Shunting-yard with function arity tracking. */
    private function toRpn(array $tokens): array
    {
        $this->assertWellFormed($tokens);
        $output = [];
        $stack = [];
        $arity = [];
        $previous = null;

        foreach ($tokens as $k => $token) {
            switch ($token['type']) {
                case 'num':
                case 'str':
                    $output[] = $token;
                    break;
                case 'ident':
                    $name = strtolower($token['value']);
                    $call = ($tokens[$k + 1]['type'] ?? null) === '(';
                    if (isset(self::FUNCTIONS[$name])) {
                        if (! $call) {
                            throw new RuntimeException("{$name} is a function; write {$name}(…).");
                        }
                        $stack[] = ['type' => 'func', 'value' => $name];
                        $arity[] = 1;
                    } elseif ($call) {
                        throw new RuntimeException("Unknown function '{$token['value']}'.");
                    } else {
                        $output[] = ['type' => 'var', 'value' => $name];
                    }
                    break;
                case 'op':
                    $op = $token['value'];
                    $unary = $op === '-' && ($previous === null || in_array($previous['type'], ['op', '(', ','], true));
                    if ($unary) {
                        $op = 'neg';
                    }
                    while ($stack !== [] && end($stack)['type'] === 'op' && self::PRECEDENCE[end($stack)['value']] >= self::PRECEDENCE[$op] && $op !== 'neg') {
                        $output[] = array_pop($stack);
                    }
                    $stack[] = ['type' => 'op', 'value' => $op];
                    break;
                case '(':
                    $stack[] = $token;
                    break;
                case ',':
                    while ($stack !== [] && end($stack)['type'] !== '(') {
                        $output[] = array_pop($stack);
                    }
                    if ($stack === [] || $arity === []) {
                        throw new RuntimeException('Misplaced comma in formula.');
                    }
                    $arity[count($arity) - 1]++;
                    break;
                case ')':
                    while ($stack !== [] && end($stack)['type'] !== '(') {
                        $output[] = array_pop($stack);
                    }
                    if ($stack === []) {
                        throw new RuntimeException('Unbalanced parentheses in formula.');
                    }
                    array_pop($stack);
                    if ($stack !== [] && end($stack)['type'] === 'func') {
                        $func = array_pop($stack);
                        $func['arity'] = $previous['type'] === '(' ? 0 : array_pop($arity);
                        if ($previous['type'] === '(') {
                            array_pop($arity);
                        }
                        $this->assertArity($func['value'], $func['arity']);
                        $output[] = $func;
                    }
                    break;
            }
            $previous = $token;
        }

        while ($stack !== []) {
            $top = array_pop($stack);
            if ($top['type'] === '(') {
                throw new RuntimeException('Unbalanced parentheses in formula.');
            }
            $output[] = $top;
        }

        if ($output === []) {
            throw new RuntimeException('Formula is empty.');
        }

        return $output;
    }

    /** Values and operators must alternate; "basic * 40%" gets a hint, since % is the remainder, not percent. */
    private function assertWellFormed(array $tokens): void
    {
        $percent = "'%' is the remainder operator, not percent: for 40% write * 40 / 100 or * 0.40.";
        $expectValue = true;
        $previous = null;

        $isFunction = fn (?array $t) => ($t['type'] ?? null) === 'ident' && isset(self::FUNCTIONS[strtolower($t['value'])]);

        foreach ($tokens as $token) {
            if ($isFunction($previous) && $token['type'] !== '(') {
                throw new RuntimeException(strtolower($previous['value']).' is a function; write '.strtolower($previous['value']).'(…).');
            }
            $shown = is_string($token['value']) ? $token['value'] : $this->show($token['value']);
            switch ($token['type']) {
                case 'num':
                case 'str':
                case 'ident':
                    if (! $expectValue) {
                        throw new RuntimeException("An operator is missing before '{$shown}'.");
                    }
                    $expectValue = isset(self::FUNCTIONS[strtolower((string) $token['value'])]);
                    break;
                case 'op':
                    $unary = $token['value'] === '-' && $expectValue;
                    if ($expectValue && ! $unary) {
                        throw new RuntimeException(($previous['value'] ?? null) === '%' ? $percent : "'{$token['value']}' needs a value before it.");
                    }
                    $expectValue = true;
                    break;
                case '(':
                    if (! $expectValue) {
                        throw new RuntimeException(($previous['type'] ?? null) === 'ident' ? "Unknown function '{$previous['value']}'." : "An operator is missing before '('.");
                    }
                    break;
                case ',':
                case ')':
                    if ($expectValue && ! ($token['type'] === ')' && ($previous['type'] ?? null) === '(')) {
                        throw new RuntimeException(($previous['value'] ?? null) === '%' ? $percent : "A value is missing before '{$token['type']}'.");
                    }
                    $expectValue = $token['type'] === ',';
                    break;
            }
            $previous = $token;
        }

        if ($isFunction($previous)) {
            throw new RuntimeException(strtolower($previous['value']).' is a function; write '.strtolower($previous['value']).'(…).');
        }
        if ($expectValue && $previous !== null) {
            throw new RuntimeException($previous['value'] === '%' ? $percent : 'The formula ends with an operator.');
        }
    }

    private function assertArity(string $name, int $count): void
    {
        [$min, $max] = self::FUNCTIONS[$name];
        if ($count >= $min && ($max === null || $count <= $max)) {
            return;
        }
        $expected = $max === null ? "at least {$min}" : ($min === $max ? (string) $min : "{$min} or {$max}");

        throw new RuntimeException("{$name}() takes {$expected} argument".($expected === '1' ? '' : 's').", not {$count}.");
    }

    private function run(array $rpn, array|Closure $variables): float
    {
        $stack = [];

        foreach ($rpn as $token) {
            switch ($token['type']) {
                case 'num':
                case 'str':
                    $stack[] = $token['value'];
                    break;
                case 'var':
                    $value = $variables instanceof Closure ? $variables($token['value']) : ($variables[$token['value']] ?? null);
                    if ($value === null) {
                        throw new RuntimeException("Unknown variable '{$token['value']}' in formula.");
                    }
                    $stack[] = is_string($value) ? $value : (float) $value;
                    break;
                case 'op':
                    if ($token['value'] === 'neg') {
                        if ($stack === []) {
                            throw new RuntimeException('Malformed formula.');
                        }
                        $stack[] = -$this->number(array_pop($stack), '-');
                        break;
                    }
                    if (count($stack) < 2) {
                        throw new RuntimeException('Malformed formula.');
                    }
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $this->operate($token['value'], $a, $b);
                    break;
                case 'func':
                    $n = $token['arity'];
                    if (count($stack) < $n) {
                        throw new RuntimeException("Function {$token['value']} needs more arguments.");
                    }
                    $stack[] = $this->call($token['value'], $n > 0 ? array_splice($stack, -$n) : []);
                    break;
            }
        }

        if (count($stack) !== 1) {
            throw new RuntimeException('Malformed formula.');
        }
        if (is_string($stack[0])) {
            throw new RuntimeException('A formula must produce an amount, not text.');
        }
        if (! is_finite($stack[0])) {
            throw new RuntimeException('Division by zero in formula.');
        }

        return (float) $stack[0];
    }

    private function operate(string $op, float|string $a, float|string $b): float
    {
        if ($op === '==' || $op === '!=') {
            $equal = $this->equals($a, $b);

            return $equal === null ? NAN : (float) ($op === '==' ? $equal : ! $equal);
        }

        $a = $this->number($a, $op);
        $b = $this->number($b, $op);

        // && and || decide on a known operand even when the other is poisoned, like short-circuiting.
        if ($op === '&&') {
            return ($a == 0 && ! is_nan($a)) || ($b == 0 && ! is_nan($b)) ? 0.0 : (is_nan($a) || is_nan($b) ? NAN : 1.0);
        }
        if ($op === '||') {
            return ($a != 0 && ! is_nan($a)) || ($b != 0 && ! is_nan($b)) ? 1.0 : (is_nan($a) || is_nan($b) ? NAN : 0.0);
        }
        if (is_nan($a) || is_nan($b)) {
            return NAN;
        }

        return match ($op) {
            '+' => $a + $b,
            '-' => $a - $b,
            '*' => $a * $b,
            '/' => $b == 0.0 ? NAN : $a / $b,
            '%' => $b == 0.0 ? NAN : fmod($a, $b),
            '<' => (float) ($a < $b), '<=' => (float) ($a <= $b), '>' => (float) ($a > $b), '>=' => (float) ($a >= $b),
        };
    }

    /** @param  list<float|string>  $args */
    private function call(string $name, array $args): float|string
    {
        switch ($name) {
            case 'if':
                $condition = $this->truth($args[0], 'if');

                return $condition === null ? NAN : ($condition ? $args[1] : ($args[2] ?? 0.0));
            case 'in':
                $needle = array_shift($args);
                foreach ($args as $candidate) {
                    $equal = $this->equals($needle, $candidate);
                    if ($equal === null) {
                        return NAN;
                    }
                    if ($equal) {
                        return 1.0;
                    }
                }

                return 0.0;
            case 'lookup':
                $key = array_shift($args);
                for ($i = 0; $i + 1 < count($args); $i += 2) {
                    $equal = $this->equals($key, $args[$i]);
                    if ($equal === null) {
                        return NAN;
                    }
                    if ($equal) {
                        return $args[$i + 1];
                    }
                }
                if (count($args) % 2 === 1) {
                    return end($args);
                }

                throw new RuntimeException('lookup() has no entry for '.$this->show($key).' and no final value for anything else.');
            case 'slab':
                $value = $this->number(array_shift($args), 'slab');
                if (is_nan($value)) {
                    return NAN;
                }
                for ($i = 0; $i + 1 < count($args); $i += 2) {
                    $limit = $this->number($args[$i], 'slab');
                    if (is_nan($limit)) {
                        return NAN;
                    }
                    if ($value <= $limit) {
                        return $args[$i + 1];
                    }
                }
                if (count($args) % 2 === 1) {
                    return end($args);
                }

                throw new RuntimeException('slab() has no band for '.$this->show($value).'; add a final value for anything above the last limit.');
        }

        $numbers = array_map(fn ($arg) => $this->number($arg, $name), $args);
        foreach ($numbers as $number) {
            if (is_nan($number)) {
                return NAN;
            }
        }
        if (in_array($name, ['round_to', 'ceil_to', 'floor_to'], true) && $numbers[1] <= 0) {
            throw new RuntimeException("{$name}() needs a step above zero.");
        }

        return match ($name) {
            'min' => (float) min($numbers),
            'max' => (float) max($numbers),
            'round' => round($numbers[0], (int) ($numbers[1] ?? 0)),
            'floor' => floor($numbers[0]),
            'ceil' => ceil($numbers[0]),
            'abs' => abs($numbers[0]),
            'between' => (float) ($numbers[1] <= $numbers[0] && $numbers[0] <= $numbers[2]),
            'clamp' => min(max($numbers[0], $numbers[1]), $numbers[2]),
            'round_to' => round($numbers[0] / $numbers[1]) * $numbers[1],
            'ceil_to' => ceil(round($numbers[0] / $numbers[1], 9)) * $numbers[1],
            'floor_to' => floor(round($numbers[0] / $numbers[1], 9)) * $numbers[1],
        };
    }

    /** Equality; text matches ignoring case and surrounding spaces. Null = undecidable (a poisoned number). */
    private function equals(float|string $a, float|string $b): ?bool
    {
        if (is_string($a) && is_string($b)) {
            return strcasecmp(trim($a), trim($b)) === 0;
        }
        if (is_string($a) || is_string($b)) {
            $text = is_string($a) ? $a : $b;
            if (! is_numeric(trim($text))) {
                throw new RuntimeException('Cannot compare text '.$this->show($text).' with the number '.$this->show(is_string($a) ? $b : $a).'.');
            }
            [$a, $b] = [(float) $a, (float) $b];
        }
        if (is_nan($a) || is_nan($b)) {
            return null;
        }

        return abs($a - $b) < 0.000001;
    }

    private function truth(float|string $value, string $where): ?bool
    {
        $value = $this->number($value, $where);

        return is_nan($value) ? null : $value != 0;
    }

    private function number(float|string $value, string $where): float
    {
        if (is_string($value)) {
            throw new RuntimeException('Text '.$this->show($value)." cannot be used as a number (in {$where}).");
        }

        return $value;
    }

    private function show(float|string $value): string
    {
        return is_string($value) ? '"'.$value.'"' : rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}

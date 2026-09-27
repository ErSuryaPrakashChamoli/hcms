<?php

namespace App\Domain\Payroll\Services;

use Closure;
use RuntimeException;

/**
 * A small, safe arithmetic evaluator for salary formulas (§31): numbers, variables, + - * / %,
 * comparisons, parentheses and the functions min, max, round, floor, ceil, abs, if. No PHP eval.
 */
final class FormulaEngine
{
    private const FUNCTIONS = ['min', 'max', 'round', 'floor', 'ceil', 'abs', 'if'];

    private const PRECEDENCE = ['||' => 1, '&&' => 2, '==' => 3, '!=' => 3, '<' => 4, '<=' => 4, '>' => 4, '>=' => 4, '+' => 5, '-' => 5, '*' => 6, '/' => 6, '%' => 6, 'neg' => 7];

    /**
     * @param  array<string, float|int>|Closure(string): (float|int|null)  $variables  values or a resolver returning null for unknown names
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
            ->filter(fn ($t) => $t['type'] === 'ident' && ! in_array(strtolower($t['value']), self::FUNCTIONS, true))
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
        $output = [];
        $stack = [];
        $arity = [];
        $previous = null;

        foreach ($tokens as $token) {
            switch ($token['type']) {
                case 'num':
                    $output[] = $token;
                    break;
                case 'ident':
                    if (in_array(strtolower($token['value']), self::FUNCTIONS, true)) {
                        $stack[] = ['type' => 'func', 'value' => strtolower($token['value'])];
                        $arity[] = 1;
                    } else {
                        $output[] = ['type' => 'var', 'value' => strtolower($token['value'])];
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

    private function run(array $rpn, array|Closure $variables): float
    {
        $stack = [];

        foreach ($rpn as $token) {
            switch ($token['type']) {
                case 'num':
                    $stack[] = $token['value'];
                    break;
                case 'var':
                    $value = $variables instanceof Closure ? $variables($token['value']) : ($variables[$token['value']] ?? null);
                    if ($value === null) {
                        throw new RuntimeException("Unknown variable '{$token['value']}' in formula.");
                    }
                    $stack[] = (float) $value;
                    break;
                case 'op':
                    if ($token['value'] === 'neg') {
                        $stack[] = -array_pop($stack);
                        break;
                    }
                    if (count($stack) < 2) {
                        throw new RuntimeException('Malformed formula.');
                    }
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = match ($token['value']) {
                        '+' => $a + $b,
                        '-' => $a - $b,
                        '*' => $a * $b,
                        '/' => $b == 0.0 ? throw new RuntimeException('Division by zero in formula.') : $a / $b,
                        '%' => $b == 0.0 ? throw new RuntimeException('Division by zero in formula.') : fmod($a, $b),
                        '<' => (float) ($a < $b), '<=' => (float) ($a <= $b), '>' => (float) ($a > $b), '>=' => (float) ($a >= $b),
                        '==' => (float) (abs($a - $b) < 0.000001), '!=' => (float) (abs($a - $b) >= 0.000001),
                        '&&' => (float) ($a != 0 && $b != 0), '||' => (float) ($a != 0 || $b != 0),
                    };
                    break;
                case 'func':
                    $n = $token['arity'];
                    if (count($stack) < $n) {
                        throw new RuntimeException("Function {$token['value']} needs more arguments.");
                    }
                    $args = $n > 0 ? array_splice($stack, -$n) : [];
                    $stack[] = match ($token['value']) {
                        'min' => (float) min($args),
                        'max' => (float) max($args),
                        'round' => round($args[0], (int) ($args[1] ?? 0)),
                        'floor' => floor($args[0]),
                        'ceil' => ceil($args[0]),
                        'abs' => abs($args[0]),
                        'if' => $args[0] != 0 ? ($args[1] ?? 0) : ($args[2] ?? 0),
                    };
                    break;
            }
        }

        if (count($stack) !== 1) {
            throw new RuntimeException('Malformed formula.');
        }

        return (float) $stack[0];
    }
}

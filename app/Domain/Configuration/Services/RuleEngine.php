<?php

namespace App\Domain\Configuration\Services;

/**
 * Evaluates condition lists against a flat context array. Deterministic, side-effect free.
 * Condition: ['field' => 'department_id', 'operator' => 'in', 'value' => [1, 2]].
 */
final class RuleEngine
{
    /**
     * @param  array<int, array{field: string, operator: string, value?: mixed}>  $conditions
     * @param  array<string, mixed>  $context
     */
    public function matches(array $conditions, array $context, string $match = 'all'): bool
    {
        if ($conditions === []) {
            return true;
        }

        $results = array_map(fn (array $c) => $this->evaluate($c, $context), $conditions);

        return $match === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    /** @param  array{field: string, operator: string, value?: mixed}  $condition */
    public function evaluate(array $condition, array $context): bool
    {
        $actual = $context[$condition['field']] ?? null;
        $expected = $condition['value'] ?? null;

        return match ($condition['operator']) {
            'equals' => $this->loose($actual) === $this->loose($expected),
            'not_equals' => $this->loose($actual) !== $this->loose($expected),
            'in' => in_array($this->loose($actual), array_map($this->loose(...), (array) $expected), true),
            'not_in' => ! in_array($this->loose($actual), array_map($this->loose(...), (array) $expected), true),
            'greater_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'less_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'is_empty' => $actual === null || $actual === '' || $actual === [],
            'is_not_empty' => ! ($actual === null || $actual === '' || $actual === []),
            default => false,
        };
    }

    /** Ids arrive as ints from models and as strings from forms; compare them as strings. */
    private function loose(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : $value;
    }
}

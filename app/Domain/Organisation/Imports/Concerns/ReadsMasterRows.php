<?php

namespace App\Domain\Organisation\Imports\Concerns;

use App\Domain\Identity\Scopes\AccessScope;
use App\Support\Geography\Countries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Data import DI-1: cell readers shared by the master uploads (MasterImports subclasses): bounded text, numbers,
 * countries, a choice from a vocabulary, and a parent in the same list that may be added by an earlier row of the
 * same file. Problems go into $errors in plain English; blank cells give null (the value on file is kept).
 */
trait ReadsMasterRows
{
    /** Trimmed text of at most $max characters; blank gives null. @param list<string> $errors */
    protected function text(?string $value, int $max, string $what, array &$errors): ?string
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            $errors[] = "{$what} is longer than {$max} characters.";

            return null;
        }

        return $value;
    }

    /** A number between $min and $max; blank gives null. @param list<string> $errors */
    protected function number(?string $value, string $what, array &$errors, float $min = 0, float $max = PHP_INT_MAX, bool $whole = false): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (! is_numeric($value) || (float) $value < $min || (float) $value > $max || ($whole && (float) $value !== floor((float) $value))) {
            $errors[] = "{$what} must be ".($whole ? 'a whole number' : 'a number').($max < PHP_INT_MAX ? " from {$min} to {$max}" : " of at least {$min}").'.';

            return null;
        }

        return (float) $value;
    }

    /** An ISO 3166-1 two-letter country code (upper case); blank gives null. @param list<string> $errors */
    protected function country(?string $value, array &$errors): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $code = strtoupper(trim($value));
        if (! Countries::exists($code)) {
            $errors[] = "Country code '{$value}' is not a country: use its two-letter ISO code, e.g. IN.";

            return null;
        }

        return $code;
    }

    /**
     * One of the keys of $choices, given as the key or the label (any case, spaces or underscores); blank gives null.
     *
     * @param  array<string, string>  $choices  key => label
     * @param  list<string>  $errors
     */
    protected function choice(?string $value, array $choices, string $what, array &$errors): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $wanted = str_replace([' ', '-'], '_', mb_strtolower(trim($value)));
        foreach ($choices as $key => $label) {
            if ($wanted === mb_strtolower((string) $key) || $wanted === str_replace([' ', '-'], '_', mb_strtolower((string) $label))) {
                return (string) $key;
            }
        }
        $errors[] = "{$what} '{$value}' is not one of: ".implode(', ', array_map('strval', array_keys($choices))).'.';

        return null;
    }

    /**
     * The id of a parent record in the same list, looked up fresh (an earlier row of this run may have added it). While
     * the file is checked, a parent that an earlier row of the file adds is accepted (-1: not on file yet) when the
     * parent is in the same list as the rows ($sameList).
     *
     * @param  class-string<Model>  $class
     * @param  (callable(Builder): mixed)|null  $constrain  e.g. only states for a district's state
     * @param  list<string>  $errors
     */
    protected function parentId(string $class, ?string $code, array $context, string $what, array &$errors, ?callable $constrain = null, string $column = 'code', bool $sameList = true): ?int
    {
        if ($code === null || trim($code) === '') {
            return null;
        }
        $query = $class::query()->withoutGlobalScope(AccessScope::class)->whereRaw("lower({$column}) = ?", [mb_strtolower(trim($code))]);
        if ($constrain !== null) {
            $constrain($query);
        }
        $id = $query->value('id');
        if ($id !== null) {
            return (int) $id;
        }
        if ($sameList && isset($context['seen'][mb_strtolower(trim($code))])) {
            return -1;
        }
        $errors[] = "{$what} '{$code}' does not exist (list a parent before the rows under it).";

        return null;
    }
}

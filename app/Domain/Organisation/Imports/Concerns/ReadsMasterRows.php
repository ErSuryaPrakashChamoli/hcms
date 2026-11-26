<?php

namespace App\Domain\Organisation\Imports\Concerns;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Employment\Imports\Workbooks\ImportReferences;
use App\Domain\Employment\Imports\Workbooks\PlannedReferences;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Resolution\ResolutionStatus;
use App\Support\Geography\Countries;
use App\Support\Imports\ClearValue;
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

    /** A country by its name (India) or ISO 3166-1 two-letter code (IN), kept as the code; blank gives null. @param list<string> $errors */
    protected function country(?string $value, array &$errors): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $code = Countries::codeOf($value);
        if ($code === null) {
            $errors[] = "Country '{$value}' is not a country: use its English name (India) or its two-letter code (IN).";

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
        // `(none)`: no parent (a required parent column refuses it before this, in MasterImports::check).
        if (ClearValue::isClear($code)) {
            return MasterImports::CLEARED;
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
        // Names first: a parent an earlier row of this file adds, named rather than coded (its id once the run added it).
        if ($sameList && $constrain === null && $column === 'code' && ($earlier = $this->addedEarlier($context, $code)) !== null) {
            return $earlier;
        }
        // Master uploads: a parent an earlier worksheet of the workbook adds (sub branches under new branches).
        $planned = app(PlannedReferences::class);
        if ($planned->plannedRecord($class, $code) !== null) {
            return MasterImports::PLANNED;
        }
        if (($blocked = $planned->blockedMessage($class, $code, $what)) !== null) {
            $errors[] = $blocked;

            return null;
        }
        // A parent named rather than coded (or "Name (CODE)"), when the list is a registered master and the parent is
        // not narrowed further ($constrain, e.g. places of one kind, keeps codes only).
        $resolution = $constrain === null && $column === 'code'
            ? app(ImportReferences::class)->resolve($class, $code, null, $context['row_ref']['sheet'] ?? null, $context['row_ref']['row'] ?? null, $what) : null;
        if ($resolution !== null && $resolution->ok()) {
            return (int) $resolution->id;
        }
        $errors[] = $resolution !== null && $resolution->status !== ResolutionStatus::Unmatched && $resolution->error() !== null
            ? $resolution->error() : "{$what} '{$code}' does not exist (list a parent before the rows under it).";

        return null;
    }
}

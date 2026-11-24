<?php

namespace App\Domain\Employment\Imports\Workbooks;

use Illuminate\Container\Attributes\Scoped;

/**
 * Master uploads: while a workbook is checked, the keys that its earlier worksheets will create (a company code from
 * Companies, an employee code from Employees …) and the keys whose rows have errors. Outside a workbook check it is
 * inactive and changes nothing, so individual uploads behave exactly as before.
 *
 * Two readers:
 *  - code-keyed master uploads (MasterImports::lookup, ReadsMasterRows::parentId) accept a planned code as "added by an
 *    earlier worksheet" (-1, the convention parentId() already used for a parent added earlier in the same file), so
 *    those worksheets are checked in full in the preview;
 *  - the other uploads (employee-keyed profile data, products, contracts …) do their own lookups, so a row naming a
 *    planned key is left waiting (RowImports::validate): it is checked in full right before its worksheet runs, once the
 *    record exists.
 * A reference to a key whose own row has errors is an error naming that row, never a silent skip.
 */
#[Scoped]
final class PlannedReferences
{
    private bool $active = false;

    /** @var array<string, array<string, array{key: string, sheet: string, row: int}>> kind => lower key => where it is added */
    private array $planned = [];

    /** @var array<string, array<string, array{key: string, sheet: string, row: int}>> kind => lower key => the row with errors */
    private array $blocked = [];

    /** @var array<string, list<string>> the worksheet being checked: kind => the fields holding it */
    private array $needs = [];

    /** @var array<string, array{state: string, kind: string, label: string, key: string, sheet: string, row: int}> what the current row depends on */
    private array $hits = [];

    public function begin(): void
    {
        $this->active = true;
        $this->planned = $this->blocked = $this->needs = $this->hits = [];
    }

    public function end(): void
    {
        $this->active = false;
        $this->planned = $this->blocked = $this->needs = $this->hits = [];
    }

    public function active(): bool
    {
        return $this->active;
    }

    /** @param array<string, list<string>> $needs the worksheet about to be checked */
    public function forSheet(array $needs): void
    {
        $this->needs = $needs;
    }

    public function plan(string $kind, string $key, string $sheet, int $row): void
    {
        $lower = mb_strtolower(trim($key));
        if ($lower !== '' && ! isset($this->planned[$kind][$lower])) {
            $this->planned[$kind][$lower] = ['key' => trim($key), 'sheet' => $sheet, 'row' => $row];
        }
    }

    public function block(string $kind, string $key, string $sheet, int $row): void
    {
        $lower = mb_strtolower(trim($key));
        if ($lower !== '' && ! isset($this->planned[$kind][$lower]) && ! isset($this->blocked[$kind][$lower])) {
            $this->blocked[$kind][$lower] = ['key' => trim($key), 'sheet' => $sheet, 'row' => $row];
        }
    }

    /** @return array{key: string, sheet: string, row: int}|null where an earlier worksheet adds this record of this model */
    public function plannedRecord(string $class, ?string $key): ?array
    {
        return $this->find($this->planned, WorkbookCatalogue::kindsOf($class), $key, 'planned');
    }

    /** @return array{key: string, sheet: string, row: int}|null the row with errors that would have added this record */
    public function blockedRecord(string $class, ?string $key): ?array
    {
        return $this->find($this->blocked, WorkbookCatalogue::kindsOf($class), $key, 'blocked');
    }

    /**
     * What the row checked since the last call depends on in earlier worksheets: each reference that an earlier
     * worksheet adds (planned) or whose row there has errors (blocked), with that worksheet and row. Read by the
     * pipeline after each row, so the preview can say where each dependency comes from.
     *
     * @return list<array{state: string, kind: string, label: string, key: string, sheet: string, row: int}>
     */
    public function takeHits(): array
    {
        $hits = array_values($this->hits);
        $this->hits = [];

        return $hits;
    }

    /** @param array{key: string, sheet: string, row: int} $entry */
    private function hit(string $state, string $kind, array $entry): void
    {
        $this->hits[$state.'|'.$kind.'|'.mb_strtolower($entry['key'])] = ['state' => $state, 'kind' => $kind, 'label' => WorkbookCatalogue::KINDS[$kind][0] ?? $kind] + $entry;
    }

    /** The error a missing reference gets when its own row has errors, or null. */
    public function blockedMessage(string $class, ?string $key, string $what): ?string
    {
        if (! $this->active || ($blocked = $this->blockedRecord($class, $key)) === null) {
            return null;
        }

        return "{$what} '{$blocked['key']}' is added by row {$blocked['row']} of {$blocked['sheet']}, which has errors: fix that row first.";
    }

    /**
     * For an upload that does its own lookups: whether this row must wait for an earlier worksheet (['waiting' =>
     * reason]) or cannot be imported because the row it depends on has errors (['error' => reason]), or null.
     *
     * @param  array<string, string>  $r  the mapped row (field => value)
     * @return array{waiting?: string, error?: string}|null
     */
    public function deferral(array $r): ?array
    {
        if (! $this->active || $this->needs === []) {
            return null;
        }
        $waiting = [];
        foreach ($this->needs as $kind => $fields) {
            foreach ($fields as $field) {
                $value = trim((string) ($r[$field] ?? ''));
                if ($value === '') {
                    continue;
                }
                $label = WorkbookCatalogue::KINDS[$kind][0] ?? $kind;
                if (($blocked = $this->blocked[$kind][mb_strtolower($value)] ?? null) !== null && ! isset($this->planned[$kind][mb_strtolower($value)])) {
                    $this->hit('blocked', $kind, $blocked);

                    return ['error' => "{$label} '{$value}' depends on row {$blocked['row']} of {$blocked['sheet']}, which has errors: fix that row first."];
                }
                if (($planned = $this->planned[$kind][mb_strtolower($value)] ?? null) !== null) {
                    $this->hit('planned', $kind, $planned);
                    $waiting[] = "row {$planned['row']} of {$planned['sheet']}";
                }
            }
        }

        return $waiting === [] ? null : ['waiting' => 'Checked in full once '.implode(' and ', array_unique($waiting)).' is imported.'];
    }

    /** @return array<string, int> kind => how many keys are planned (for the preview) */
    public function counts(): array
    {
        return array_map('count', $this->planned);
    }

    /**
     * @param  array<string, array<string, array{key: string, sheet: string, row: int}>>  $set
     * @param  list<string>  $kinds
     */
    private function find(array $set, array $kinds, ?string $key, string $state): ?array
    {
        if (! $this->active || $key === null || trim($key) === '') {
            return null;
        }
        $lower = mb_strtolower(trim($key));
        foreach ($kinds as $kind) {
            if (isset($set[$kind][$lower])) {
                $this->hit($state, $kind, $set[$kind][$lower]);

                return $set[$kind][$lower];
            }
        }

        return null;
    }
}

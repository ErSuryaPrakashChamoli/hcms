<?php

namespace App\Domain\ComplianceReports\Support;

/**
 * One generated register, statement or summary: a header block (who, where, which period, under which law), the
 * columns, the rows and an optional totals row. A sheet with no rows says why in $message; it is never padded.
 *
 * $warnings are gaps in the data behind the sheet (payroll not finalized for a month, attendance not processed,
 * identifiers or registrations missing, employees attributed to an establishment by fallback): a sheet with warnings
 * is incomplete. $rules are the statutory rule versions its figures were calculated with.
 */
final class ReportSheet
{
    /**
     * @param  array<string, string>  $columns  key => heading
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{0: string, 1: string}>  $header  label => value pairs printed above the table
     * @param  array<string, mixed>|null  $totals  key => value (keys of $columns)
     * @param  list<string>  $notes  printed under the table
     * @param  list<string>  $money  column keys formatted as amounts
     * @param  list<string>  $warnings  data gaps (printed under the table and carried into the pack index)
     * @param  array<int, array<string, mixed>>  $rules  rule id => [code, state, version, verification_status, status]
     */
    public function __construct(
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows = [],
        public readonly array $header = [],
        public readonly ?array $totals = null,
        public readonly array $notes = [],
        public readonly array $money = [],
        public readonly ?string $message = null,
        public readonly string $sheetName = '',
        public readonly array $warnings = [],
        public readonly array $rules = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function withSheetName(string $name): self
    {
        return new self($this->title, $this->columns, $this->rows, $this->header, $this->totals, $this->notes, $this->money, $this->message, $name, $this->warnings, $this->rules);
    }

    public function withHeader(array $header): self
    {
        return new self($this->title, $this->columns, $this->rows, [...$header, ...$this->header], $this->totals, $this->notes, $this->money, $this->message, $this->sheetName, $this->warnings, $this->rules);
    }

    /** Sum of the money (and given) columns over the rows, with $label in the first column. */
    public static function sum(array $columns, array $rows, array $keys, string $label = 'Total'): array
    {
        $totals = [array_key_first($columns) => $label];
        foreach ($keys as $key) {
            $totals[$key] = round(array_sum(array_map(fn (array $row) => (float) ($row[$key] ?? 0), $rows)), 2);
        }

        return $totals;
    }
}

<?php

namespace App\Domain\Leave\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Leave\Services\LeaveYear;

/**
 * HROne parity HP-1b: leave opening balances (go-live) and balance changes (comp-off or RH balances included, by
 * leave code) from a file. Each row posts through LeaveAdjustments, so the ledger stays the only source of balances;
 * an opening balance is posted once per employee, leave type and year.
 */
final class LeaveBalanceImports extends RowImports
{
    public const TYPE = 'leave_balances';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'leave_code' => ['Leave code', true], 'days' => ['Days', true],
            'kind' => ['Kind (opening/adjustment)', false], 'year' => ['Leave year', false], 'note' => ['Note', false],
        ];
    }

    public function label(): string
    {
        return 'Leave balance upload';
    }

    protected function module(): string
    {
        return 'leave';
    }

    protected function entityType(): string
    {
        return LeaveLedgerEntry::class;
    }

    protected function aliases(): array
    {
        return ['leave_type' => 'leave_code', 'type' => 'kind', 'balance' => 'days', 'remarks' => 'note'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['types' => LeaveType::query()->get()->keyBy(fn (LeaveType $t) => mb_strtolower($t->code))->all()];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $type = $context['types'][mb_strtolower($r['leave_code'] ?? '')] ?? null;
        if ($type === null) {
            $errors[] = "Leave code '".($r['leave_code'] ?? '')."' does not exist.";
        }
        $kind = strtolower($r['kind'] ?? 'opening');
        if (! in_array($kind, ['opening', 'adjustment'], true)) {
            $errors[] = 'Kind must be opening or adjustment.';
        }
        if (! is_numeric($r['days'] ?? null)) {
            $errors[] = 'Days must be a number.';
        } elseif ($kind === 'opening' && (float) $r['days'] < 0) {
            $errors[] = 'An opening balance cannot be negative; use an adjustment.';
        } elseif ($kind === 'adjustment' && (float) $r['days'] == 0.0) {
            $errors[] = 'An adjustment must not be zero.';
        }
        $year = isset($r['year']) ? (int) $r['year'] : app(LeaveYear::class)->periodFor(now());
        if ($year < 2000 || $year > 2100) {
            $errors[] = 'Leave year must be a year such as 2026.';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }

        if ($kind === 'opening') {
            $key = "{$employeeId}|{$type->id}|{$year}";
            if (isset($context['seen'][$key])) {
                return ['action' => 'error', 'errors' => ['The same opening balance is in row '.$context['seen'][$key].'.'], 'employee_id' => $employeeId];
            }
            $context['seen'][$key] = $row->row_number;
            if (LeaveLedgerEntry::query()->where('employee_id', $employeeId)->where('leave_type_id', $type->id)->where('accrual_key', "opening-{$year}")->exists()) {
                return ['action' => 'skip', 'match' => ['reason' => "An opening balance for {$year} is already on file."], 'employee_id' => $employeeId];
            }
        }

        return ['action' => $kind === 'opening' ? 'create' : 'update', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = Employee::query()->findOrFail($row->employee_id);
        $type = LeaveType::query()->whereRaw('lower(code) = ?', [mb_strtolower($r['leave_code'])])->firstOrFail();
        $year = isset($r['year']) ? (int) $r['year'] : app(LeaveYear::class)->periodFor(now());
        $note = $r['note'] ?? $this->label()." #{$import->getKey()}";

        if (strtolower($r['kind'] ?? 'opening') === 'opening') {
            $entry = app(LeaveAdjustments::class)->opening($employee, $type, (float) $r['days'], $year, $note);

            return $entry ? "Opening {$r['days']} {$type->code} for {$year}" : 'Opening already on file';
        }
        app(LeaveAdjustments::class)->adjust($employee, $type, (float) $r['days'], $note, $year);

        return sprintf('Adjusted %+.2f %s for %d', (float) $r['days'], $type->code, $year);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Leave code', 'Yes', 'Code of the leave type (EL, CL, SL, CO …).', 'EL'],
                ['Days', 'Yes', 'Days to set (opening) or add / take away (adjustment, e.g. -1.5).', '12'],
                ['Kind (opening/adjustment)', 'No', 'opening (default): the balance at the start of the year, posted once. adjustment: a change to the balance.', 'opening'],
                ['Leave year', 'No', 'The leave year; blank is the current one.', (string) app(LeaveYear::class)->periodFor(now())],
                ['Note', 'No', 'Why the balance is set or changed.', 'Balance carried from HROne'],
            ],
            'notes' => [
                'Balances change only through the leave ledger: each row posts an opening or an adjustment entry, which the leave balance screens show.',
                'An opening balance is posted once per employee, leave type and year; a second upload skips it.',
                'Comp-off and restricted holiday balances are loaded the same way with their leave codes.',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';
        $year = (string) app(LeaveYear::class)->periodFor(now());

        return [[$code, 'EL', '12', 'opening', $year, 'Go-live balance'], [$code, 'CL', '-1', 'adjustment', $year, 'Correction']];
    }
}

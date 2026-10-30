<?php

namespace App\Domain\Leave\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\Leaves;

/**
 * HROne parity HP-1b: leave already taken (go-live or migration) from a file. Each row is requested on the
 * employee's behalf and approved in the importer's name through Leaves, so policy, balance and overlap checks
 * apply as for any request, and attendance for the days is recalculated.
 */
final class LeaveHistoryImports extends RowImports
{
    public const TYPE = 'leave_history';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'leave_code' => ['Leave code', true], 'from_date' => ['From date', true],
            'to_date' => ['To date', false], 'from_session' => ['From session (full/first_half/second_half)', false],
            'to_session' => ['To session (full/first_half/second_half)', false], 'reason' => ['Reason', false],
        ];
    }

    public function label(): string
    {
        return 'Leave history upload';
    }

    protected function module(): string
    {
        return 'leave';
    }

    protected function entityType(): string
    {
        return LeaveRequest::class;
    }

    protected function aliases(): array
    {
        return ['leave_type' => 'leave_code', 'from' => 'from_date', 'to' => 'to_date', 'start_date' => 'from_date', 'end_date' => 'to_date'];
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
        $from = $this->date($r['from_date'] ?? null);
        $to = $this->date($r['to_date'] ?? null) ?? $from;
        if ($from === null) {
            $errors[] = 'From date must be a date (YYYY-MM-DD).';
        } elseif ($to < $from) {
            $errors[] = 'To date must not be before the from date.';
        }
        foreach (['from_session', 'to_session'] as $s) {
            if (isset($r[$s]) && ! in_array($r[$s], ['full', 'first_half', 'second_half'], true)) {
                $errors[] = ucfirst(str_replace('_', ' ', $s)).' must be full, first_half or second_half.';
            }
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }

        $overlap = LeaveRequest::query()->where('employee_id', $employeeId)->whereIn('status', ['pending', 'approved', 'cancel_requested'])
            ->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from)->exists();

        return $overlap
            ? ['action' => 'skip', 'match' => ['reason' => 'Leave on these days is already on file.'], 'employee_id' => $employeeId]
            : ['action' => 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = Employee::query()->findOrFail($row->employee_id);
        $type = LeaveType::query()->whereRaw('lower(code) = ?', [mb_strtolower($r['leave_code'])])->firstOrFail();
        $from = $this->date($r['from_date']);
        $leaves = app(Leaves::class);
        $request = $leaves->request($employee, $type, $from, $this->date($r['to_date'] ?? null) ?? $from, $r['reason'] ?? 'Leave history upload', $r['from_session'] ?? 'full', $r['to_session'] ?? 'full', null, $user);
        if ($request->status === 'pending') {
            $request = $leaves->approve($request, $this->label()." #{$import->getKey()}", $user);
        }

        return "{$type->code} {$request->days} day(s), ".$request->status;
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Leave code', 'Yes', 'Code of the leave type.', 'CL'],
                ['From date', 'Yes', 'First day of leave, YYYY-MM-DD.', now()->subDays(10)->toDateString()],
                ['To date', 'No', 'Last day; blank is the same day.', now()->subDays(9)->toDateString()],
                ['From session (full/first_half/second_half)', 'No', 'Half of the first day, for half-day leave.', 'full'],
                ['To session (full/first_half/second_half)', 'No', 'Half of the last day.', 'full'],
                ['Reason', 'No', 'Shown on the request.', 'Taken before go-live'],
            ],
            'notes' => [
                'Each row becomes an approved leave request, checked like any request (policy, balance, overlap, locked payroll days).',
                'Load opening balances first (Leave balance upload) so the balance covers the leave.',
                'Leave already on file for the same days is skipped.',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';

        return [[$code, 'CL', now()->subDays(10)->toDateString(), now()->subDays(9)->toDateString(), 'full', 'full', 'Taken before go-live']];
    }
}

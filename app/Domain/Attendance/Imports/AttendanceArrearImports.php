<?php

namespace App\Domain\Attendance\Imports;

use App\Domain\Attendance\Imports\Concerns\ReadsAttendanceMonths;
use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Services\AttendanceArrears;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Data import DI-35 ("Override arrear days", HROne Import → Time Office; matrix 24 §24.9 #4; decision D35-1): the arrear
 * days of an employee's earlier attendance month, set by HR — the month's total to give back (positive) or take back
 * (negative). Each row goes through AttendanceArrears::setForMonth (rights, access scope, own record, the month being over,
 * the employee in the company's month): HR's figure takes the month over — the calculation's pending arrears of the month
 * are replaced and it records none for the month afterwards — and only what runs have not settled yet is taken. A month
 * with arrears on file is an "update"; the same figure again changes nothing and is skipped.
 */
final class AttendanceArrearImports extends RowImports
{
    use ReadsAttendanceMonths;

    public const TYPE = 'attendance_arrears';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'month' => ['Month (YYYY-MM)', true], 'days' => ['Arrear days', true],
            'reason' => ['Reason', true], 'company_code' => ['Company code', false]];
    }

    public function label(): string
    {
        return 'Arrear days upload';
    }

    protected function module(): string
    {
        return 'attendance';
    }

    protected function entityType(): string
    {
        return AttendanceArrear::class;
    }

    protected function aliases(): array
    {
        return ['arrear_month' => 'month', 'pay_arrear_for_month' => 'month', 'arrear_days' => 'days', 'remarks' => 'reason', 'company' => 'company_code'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['actor' => User::query()->find($import->uploaded_by)];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $month = $this->monthOf($r['month'] ?? null);
        if ($month === null) {
            $errors[] = 'Month must be a month such as '.CarbonImmutable::today()->subMonthNoOverflow()->format('Y-m').' (YYYY-MM).';
        }
        if (! is_numeric($r['days'] ?? null)) {
            $errors[] = 'Arrear days must be a number of days, e.g. 2 or -1.5.';
        }
        if (trim((string) ($r['reason'] ?? '')) === '') {
            $errors[] = 'Reason is required.';
        }
        $companyId = $this->companyOf($r['company_code'] ?? null, $employeeId, $context, $errors);
        if ($errors !== [] || $employeeId === null || $companyId === null || $month === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        [$year, $number] = $month;
        $key = "{$employeeId}|{$companyId}|{$year}|{$number}";
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ['The same employee and month are in row '.$context['seen'][$key].'.'], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;

        $service = app(AttendanceArrears::class);
        $employee = Employee::query()->findOrFail($employeeId);
        $days = round((float) $r['days'], 2);
        if (($refusal = $service->setRefusal($employee, $companyId, $year, $number, $days, $context['actor'])) !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }
        $plan = $service->planForMonth($employeeId, $companyId, $year, $number, $days);
        $label = $this->monthLabel($year, $number);
        if (! $plan['changes']) {
            return ['action' => 'skip', 'match' => ['id' => $plan['current']?->getKey(), 'reason' => "The arrear days of {$label} are already {$days}".($plan['settled'] != 0.0 ? ' (settled by runs: '.$plan['settled'].')' : '').'.'], 'employee_id' => $employeeId];
        }
        $match = ['settled' => $plan['settled'], 'to_take' => $plan['delta'], 'replaces' => $plan['replaced']->count()];

        // A figure already on file for the month (HR's, or arrears the calculation recorded) is an update of the month.
        return $plan['current'] === null && $plan['replaced']->isEmpty()
            ? ['action' => 'create', 'match' => $match, 'employee_id' => $employeeId]
            : ['action' => 'update', 'match' => $match + ['id' => $plan['current']?->getKey()], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $errors = [];
        $context = [];
        $companyId = $this->companyOf($r['company_code'] ?? null, (int) $row->employee_id, $context, $errors);
        [$year, $month] = $this->monthOf($r['month']);
        $arrear = app(AttendanceArrears::class)->setForMonth(Employee::query()->findOrFail($row->employee_id), (int) $companyId, $year, $month, (float) $r['days'], $r['reason'], $user);

        return $arrear->status === 'void'
            ? sprintf('%s set to %s arrear day(s): nothing left to take', $this->monthLabel($year, $month), (float) $r['days'])
            : sprintf('%+.2f arrear day(s) to take for %s, dated %s', (float) $arrear->delta_days, $this->monthLabel($year, $month), $arrear->date->format('d M Y'));
    }

    public function format(): array
    {
        $last = CarbonImmutable::today()->subMonthNoOverflow()->format('Y-m');

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Month (YYYY-MM)', 'Yes', 'The earlier attendance month the days belong to (YYYY-MM; also MM-YYYY or "Aug 2026"). It must be over.', $last],
                ['Arrear days', 'Yes', 'The month\'s arrear days: to give back (positive) or take back (negative), e.g. 2 or -1.5; 0 cancels the month\'s arrears. At most the days of the month.', '2'],
                ['Reason', 'Yes', 'Why HR sets the days (kept on the arrear and in the audit trail).', 'Attendance corrected after the month closed'],
                ['Company code', 'No', 'The company the employee worked for in that month (Organisation → Companies); blank is the company they work for now.', ''],
            ],
            'notes' => [
                'Each row sets the arrear days of one employee\'s month. HR\'s figure takes the month over: arrears the calculation recorded for the month and not yet taken are replaced, and it records no new ones for that month.',
                'Only what runs have not settled yet is taken: if runs already settled 1 day for the month and the figure is 3, the next run takes 2. The same figure again changes nothing.',
                'The days wait as an arrear dated the month\'s last day, taken by the next run like any other arrear (hold or release them on Attendance arrears).',
                'Needs the attendance arrears right, the employee in your access, and never your own record.',
            ],
        ];
    }

    protected function examples(): array
    {
        $employees = Employee::query()->orderBy('id')->limit(2)->get(['id', 'employee_code']);
        $first = (string) ($employees->first()?->employee_code ?? 'EMP0001');
        $second = $employees->get(1) ?? $employees->first();
        // The second row names the company the employee works for (the column is optional).
        $errors = [];
        $context = [];
        $companyId = $second === null ? null : $this->companyOf(null, (int) $second->id, $context, $errors);
        $company = $companyId === null ? '' : (string) Company::query()->whereKey($companyId)->value('code');
        $last = CarbonImmutable::today()->subMonthNoOverflow();

        return [
            [$first, $last->format('Y-m'), '2', 'Absence regularised after the month closed', ''],
            [(string) ($second?->employee_code ?? $first), $last->subMonthNoOverflow()->format('Y-m'), '-1', 'Unpaid leave applied late', $company],
        ];
    }
}

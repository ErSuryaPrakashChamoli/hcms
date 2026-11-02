<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\PaidDaysRegister;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * HROne parity TX-4 (matrix 24 §24.5.4 #8; matrix 21 §21.2.2 Time Office #35, #41 — "Monthly paid day", "Paid day YTD"
 * before payroll): paid days per employee and attendance month, exactly as the Paid days screen shows them — present days,
 * paid leave, unpaid leave, holidays, weekly offs, absent days, late-mark penalty days, arrear days, HR's paid-day
 * adjustment (only while attendance.paid_day_adjustments.enabled is on) and the paid days that result, plus hours by kind
 * of day.
 *
 * A row is an employee and a month in which some of their days are processed (grouped in SQL, like the monthly attendance
 * summary); its figures are those of the attendance month of that name for the company the employee belonged to at the
 * month's end (on a 25th–24th cycle, "Oct 2026" is 25 Sep – 24 Oct), computed by PaidDaysRegister once per month for every
 * employee of that month (a few queries a month, not one a row). attendance.view, inside the reader's access scope; not
 * readable without a signed-in user (not on analytics.unattended_datasets).
 */
class AttendancePaidDaysDataset extends Dataset
{
    /** @var array<string, array<int, array<string, mixed>>> month => employee id => figures, for one run */
    private array $memo = [];

    public function key(): string
    {
        return 'attendance_paid_days';
    }

    public function label(): string
    {
        return 'Paid days before payroll (one row per employee per attendance month)';
    }

    public function category(): string
    {
        return 'Attendance';
    }

    public function permissions(): array
    {
        return ['attendance.view'];
    }

    public function fields(): array
    {
        $number = fn (string $key) => fn ($r) => $this->figures($r)[$key] ?? null;
        $numbers = [
            'present_days' => ['Present days', 'present'], 'paid_leave_days' => ['Paid leave', 'paid_leave'], 'unpaid_leave_days' => ['Unpaid leave', 'unpaid_leave'],
            'holidays' => ['Holidays', 'holiday'], 'weekly_offs' => ['Weekly offs', 'weekly_off'], 'absent_days' => ['Absent', 'absent'], 'half_days' => ['Half days', 'half_days'],
            'penalty_days' => ['Late-mark penalty days', 'penalty_days'], 'arrear_days' => ['Arrear days (corrections to locked days)', 'arrear_days'],
            'adjustment_days' => ['Paid-day adjustment (days)', 'adjustment'], 'paid_days_processed' => ['Paid days as processed', 'paid_days_processed'],
            'paid_days' => ['Paid days', 'paid_days'], 'days_processed' => ['Days processed', 'days'],
            'working_hours' => ['Hours on working days', 'working_hours'], 'weekly_off_hours' => ['Hours on weekly offs', 'weekly_off_hours'],
            'holiday_hours' => ['Hours on holidays', 'holiday_hours'], 'overtime_hours' => ['Overtime hours (approved)', 'overtime_hours'],
        ];
        $fields = [];
        foreach ($numbers as $field => [$label, $key]) {
            $fields[$field] = ['label' => $label, 'type' => 'number', 'value' => $number($key)];
        }

        return [
            'month' => ['label' => 'Attendance month', 'type' => 'string', 'value' => fn ($r) => $r->month],
            'month_start' => ['label' => 'Month (date)', 'type' => 'date', 'value' => fn ($r) => $r->month ? Carbon::parse($r->month.'-01') : null],
            'period_from' => ['label' => 'Month from', 'type' => 'date', 'value' => fn ($r) => ($d = $this->figures($r)['start'] ?? null) ? Carbon::parse($d) : null],
            'period_to' => ['label' => 'Month to', 'type' => 'date', 'value' => fn ($r) => ($d = $this->figures($r)['end'] ?? null) ? Carbon::parse($d) : null],
            'financial_year' => ['label' => 'Financial year', 'type' => 'string', 'value' => fn ($r) => $r->month ? PaidDaysRegister::yearLabel((int) substr($r->month, 0, 4), (int) substr($r->month, 5, 2)) : null],
            ...$this->employeeFields(fn ($r) => $r->employee),
            'month_company' => ['label' => 'Company (that month)', 'type' => 'string', 'value' => fn ($r) => $this->figures($r)['company'] ?? null],
            ...$fields,
            'adjusted' => ['label' => 'Paid days adjusted', 'type' => 'boolean', 'value' => fn ($r) => (bool) ($this->figures($r)['adjusted'] ?? false)],
        ];
    }

    public function query(): Builder
    {
        $this->memo = []; // each run computes its own figures, under its own reader
        $month = 'substr(date, 1, 7)';

        return AttendanceRecord::query()->selectRaw("employee_id, {$month} as month")
            ->groupBy('employee_id')->groupByRaw($month)
            ->orderByRaw("{$month} desc")->orderBy('employee_id')
            ->with(self::employeeWith('employee'));
    }

    /** @return array<string, mixed> the row's figures (computed for every employee of its month on first use) */
    private function figures(object $row): array
    {
        $month = (string) $row->month;
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return [];
        }
        if (! array_key_exists($month, $this->memo)) {
            // Keep one month at a time: the runner reads the rows month by month.
            $this->memo = [$month => $this->month((int) substr($month, 0, 4), (int) substr($month, 5, 2))];
        }

        return $this->memo[$month][(int) $row->employee_id] ?? [];
    }

    /** @return array<int, array<string, mixed>> employee id => figures of the attendance month, for everyone with days in it */
    private function month(int $year, int $month): array
    {
        $first = Carbon::create($year, $month, 1);
        $ids = AttendanceRecord::query()->whereDate('date', '>=', $first->toDateString())->whereDate('date', '<=', $first->copy()->endOfMonth()->toDateString())
            ->distinct()->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return [];
        }
        // The company each employee belonged to at the month's end (else their current one).
        $companies = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->whereIn('employee_id', $ids)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $first->copy()->endOfMonth()->toDateString().' 23:59:59'))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $first->copy()->endOfMonth()->toDateString()))
            ->orderBy('effective_from')->orderBy('id')->get(['employee_id', 'company_id'])
            ->mapWithKeys(fn (EmployeePosition $p) => [(int) $p->employee_id => (int) $p->company_id])->all();
        foreach (array_diff($ids, array_keys($companies)) as $missing) {
            $current = Employee::query()->withoutGlobalScope(AccessScope::class)->find($missing)?->currentPosition()->withoutGlobalScope(AccessScope::class)->value('company_id');
            if ($current !== null) {
                $companies[$missing] = (int) $current;
            }
        }
        $names = Company::query()->whereKey(array_unique(array_values($companies)))->pluck('name', 'id');
        $register = app(PaidDaysRegister::class);
        $out = [];
        foreach (collect($companies)->groupBy(fn ($company) => $company, preserveKeys: true) as $companyId => $members) {
            $windows = $register->windows((int) $companyId, $year, $month);
            foreach ($register->figures(array_keys($members->all()), (int) $companyId, $windows) as $employeeId => $figures) {
                $row = $figures['months'][sprintf('%04d-%02d', $year, $month)] ?? null;
                if ($row !== null) {
                    $out[(int) $employeeId] = $row + ['company' => $names[(int) $companyId] ?? null];
                }
            }
        }

        return $out;
    }
}

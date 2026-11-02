<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\AttendanceCycle;
use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendancePenalty;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\PaidDayAdjustment;
use App\Domain\Attendance\Support\AttendanceDay;
use App\Domain\Attendance\Support\PaidDayTally;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne parity TX-4 (matrix 24 §24.5.4 "Paid days", §24.5.3 #17 "Summary"): paid days of a company's people for an
 * attendance month or for the financial year up to it — present days, paid leave, unpaid leave, holidays, weekly offs,
 * absent days, late-mark penalty days, arrear days, HR's paid-day adjustments and the paid days that result — read only,
 * inside the viewer's access scope, in a handful of queries for a page of employees.
 *
 * Every figure is the same reading AttendanceOutput::summary() makes of the same days: "paid days as processed" is the
 * summary's paid days before adjustments, the adjustment is the change the counting adjustment makes (only while
 * attendance.paid_day_adjustments.enabled is on), and "paid days" is what the attendance calendar shows — paid days after
 * adjustments less late-mark penalty days (never below 0). Arrear days of a month are the corrections recorded to its days
 * after they were locked (pending, held or settled), so a year's months add up without counting a correction twice.
 */
final class PaidDaysRegister
{
    /** Register columns (CSV order) => label. */
    public const COLUMNS = [
        'present' => 'Present', 'paid_leave' => 'Paid leave', 'unpaid_leave' => 'Unpaid leave', 'holiday' => 'Holidays', 'weekly_off' => 'Weekly offs',
        'absent' => 'Absent', 'half_days' => 'Half days', 'penalty_days' => 'Penalty days', 'arrear_days' => 'Arrear days', 'adjustment' => 'Adjustments',
        'paid_days_processed' => 'Paid days as processed', 'paid_days' => 'Paid days',
    ];

    private const NUMBERS = ['present', 'paid_leave', 'unpaid_leave', 'holiday', 'weekly_off', 'absent', 'unpaid_weekly_off', 'half_days', 'days', 'other',
        'penalty_days', 'arrear_days', 'adjustment', 'paid_days_processed', 'paid_days_adjusted', 'paid_days', 'working_hours', 'weekly_off_hours', 'holiday_hours', 'overtime_hours'];

    public function __construct(
        private readonly AttendanceCycle $cycle,
        private readonly PaidDayAdjustments $adjustments,
        private readonly AuditRecorder $audit,
    ) {}

    /** The first month of the financial year holding the month (peopleos.compliance.financial_year_start_month). */
    public static function yearStart(int $year, int $month): CarbonImmutable
    {
        $first = max(1, min(12, (int) config('peopleos.compliance.financial_year_start_month', 4)));

        return CarbonImmutable::create($month >= $first ? $year : $year - 1, $first, 1);
    }

    /** "FY 2026–27" for the financial year holding the month. */
    public static function yearLabel(int $year, int $month): string
    {
        $start = self::yearStart($year, $month);

        return $start->month === 1 ? 'Year '.$start->year : 'FY '.$start->year.'–'.substr((string) ($start->year + 1), 2);
    }

    /**
     * The months the register covers: the month itself, or every month of its financial year up to it.
     *
     * @return list<array{year: int, month: int, key: string, label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function windows(int $companyId, int $year, int $month, bool $yearToDate = false): array
    {
        $last = CarbonImmutable::create($year, $month, 1);
        $out = [];
        for ($m = $yearToDate ? self::yearStart($year, $month) : $last; $m->lte($last); $m = $m->addMonthNoOverflow()) {
            try {
                [$start, $end] = $this->cycle->window($companyId, $m->year, $m->month);
            } catch (Throwable) {
                continue;
            }
            $out[] = ['year' => $m->year, 'month' => $m->month, 'key' => $m->format('Y-m'), 'label' => $m->format('M Y'), 'start' => $start, 'end' => $end];
        }

        return $out;
    }

    /**
     * Figures per employee and month, and over all the months given.
     *
     * @param  list<int>  $employeeIds
     * @param  list<array{year: int, month: int, key: string, label: string, start: CarbonImmutable, end: CarbonImmutable}>  $windows
     * @return array<int, array{total: array<string, mixed>, months: array<string, array<string, mixed>>}>
     */
    public function figures(array $employeeIds, int $companyId, array $windows): array
    {
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        if ($employeeIds === [] || $windows === []) {
            return [];
        }
        $from = collect($windows)->min(fn (array $w) => $w['start']->toDateString());
        $to = collect($windows)->max(fn (array $w) => $w['end']->toDateString());
        $enabled = $this->adjustments->enabled();

        $out = [];
        foreach (array_chunk($employeeIds, 500) as $chunk) {
            $records = AttendanceRecord::query()->whereIn('employee_id', $chunk)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
                ->orderBy('date')->get()->groupBy('employee_id');
            $penalties = [];
            foreach (AttendancePenalty::query()->whereIn('employee_id', $chunk)->where('status', 'applied')
                ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get(['employee_id', 'date', 'days']) as $penalty) {
                $day = $penalty->date->toDateString();
                $penalties[(int) $penalty->employee_id][$day] = ($penalties[(int) $penalty->employee_id][$day] ?? 0.0) + (float) $penalty->days;
            }
            $arrears = AttendanceArrear::query()->whereIn('employee_id', $chunk)->whereIn('status', ['pending', 'held', 'settled'])
                ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get(['employee_id', 'date', 'delta_days'])->groupBy('employee_id');
            $adjustments = $enabled ? PaidDayAdjustment::query()->whereIn('employee_id', $chunk)->where('company_id', $companyId)->where('status', 'active')
                ->where(fn ($q) => collect($windows)->each(fn (array $w) => $q->orWhere(fn ($m) => $m->where('year', $w['year'])->where('month', $w['month']))))
                ->get()->groupBy('employee_id') : collect();

            foreach ($chunk as $employeeId) {
                $own = $records->get($employeeId, collect());
                // The days as AttendanceOutput reads them: a penalty adds its days to the day, never more than the whole day.
                $days = $own->map(fn (AttendanceRecord $r) => AttendanceDay::fromRecord($r, min(1.0, (float) ($penalties[$employeeId][$r->date->toDateString()] ?? 0))));
                $months = [];
                foreach ($windows as $window) {
                    $start = $window['start']->toDateString();
                    $end = $window['end']->toDateString();
                    $inside = $days->filter(fn (AttendanceDay $d) => $d->date >= $start && $d->date <= $end)->values();
                    $tally = PaidDayTally::fromDays($inside);
                    $adjustment = $adjustments->get($employeeId, collect())->first(fn (PaidDayAdjustment $a) => (int) $a->year === $window['year'] && (int) $a->month === $window['month']);
                    $change = $adjustment !== null ? $this->adjustments->changeOf($adjustment, $days) : 0.0;
                    $arrearDays = (float) $arrears->get($employeeId, collect())->filter(fn ($a) => $a->date->toDateString() >= $start && $a->date->toDateString() <= $end)->sum('delta_days');
                    $months[$window['key']] = $this->row($tally, PaidDayTally::minutes($own->filter(fn (AttendanceRecord $r) => $r->date->toDateString() >= $start && $r->date->toDateString() <= $end)),
                        $arrearDays, $change, $adjustment) + ['key' => $window['key'], 'label' => $window['label'], 'start' => $start, 'end' => $end];
                }
                $out[$employeeId] = ['total' => $this->sum($months), 'months' => $months];
            }
        }

        return $out;
    }

    /**
     * One employee's paid days: the months (one, or the financial year to the month), the chosen month's figures and hours
     * by kind of day, and why paid days are lower — penalty days, arrears and HR's adjustments (with reversed ones).
     *
     * @return array<string, mixed>
     */
    public function breakdown(Employee $employee, int $companyId, int $year, int $month, bool $yearToDate = false): array
    {
        $windows = $this->windows($companyId, $year, $month, $yearToDate);
        $figures = $this->figures([(int) $employee->id], $companyId, $windows)[(int) $employee->id] ?? ['total' => [], 'months' => []];
        $key = sprintf('%04d-%02d', $year, $month);
        $current = collect($windows)->firstWhere('key', $key);
        $start = $current['start'] ?? null;
        $end = $current['end'] ?? null;

        $penalties = $start === null ? collect() : AttendancePenalty::query()->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->orderBy('date')->get()
            ->map(fn (AttendancePenalty $p) => ['date' => $p->date->toDateString(), 'late_marks' => (int) $p->late_count, 'action' => AttendancePenalty::ACTIONS[$p->action] ?? (string) $p->action,
                'days' => (float) $p->days, 'status' => $p->status === 'applied' ? 'Applied' : 'Waived']);
        $arrears = $start === null ? collect() : AttendanceArrear::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'held', 'settled'])
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->orderBy('date')->get()
            ->map(fn (AttendanceArrear $a) => ['date' => $a->date->toDateString(), 'before' => str_replace('_', ' ', (string) $a->status_before), 'after' => str_replace('_', ' ', (string) $a->status_after),
                'days' => (float) $a->delta_days, 'status' => AttendanceArrear::STATUSES[$a->status] ?? (string) $a->status, 'note' => (string) $a->note]);
        $adjustments = PaidDayAdjustment::query()->with(['creator', 'reverser'])->where('employee_id', $employee->id)->where('company_id', $companyId)
            ->where('year', $year)->where('month', $month)->orderByDesc('id')->get();

        return [
            'employee' => $employee, 'key' => $key, 'label' => Carbon::create($year, $month, 1)->format('M Y'), 'year_to_date' => $yearToDate,
            'year_label' => self::yearLabel($year, $month), 'start' => $start, 'end' => $end,
            'months' => array_values($figures['months']), 'month' => $figures['months'][$key] ?? null, 'total' => $figures['total'],
            'penalties' => $penalties->all(), 'arrears' => $arrears->all(), 'adjustments' => $adjustments,
            'active' => $adjustments->first(fn (PaidDayAdjustment $a) => $a->isActive()),
        ];
    }

    /**
     * The register as CSV (cells safe from spreadsheet formulas), audited as an attendance export.
     *
     * @param  Collection<int, Employee>  $employees
     */
    public function csv(User $actor, Collection $employees, int $companyId, int $year, int $month, bool $yearToDate = false): string
    {
        $windows = $this->windows($companyId, $year, $month, $yearToDate);
        $figures = $this->figures($employees->pluck('id')->all(), $companyId, $windows);
        $columns = $this->adjustments->enabled() ? self::COLUMNS : array_diff_key(self::COLUMNS, ['adjustment' => true, 'paid_days_processed' => true]);
        $period = $yearToDate ? self::yearLabel($year, $month).' to '.Carbon::create($year, $month, 1)->format('M Y') : Carbon::create($year, $month, 1)->format('M Y');

        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Employee code', 'Employee', 'Period', ...array_values($columns)]);
        foreach ($employees as $employee) {
            $row = $figures[(int) $employee->id]['total'] ?? [];
            fputcsv($out, [self::cell($employee->employee_code), self::cell($employee->person?->display_name), self::cell($period),
                ...array_map(fn (string $key) => self::cell((float) ($row[$key] ?? 0)), array_keys($columns))]);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $this->audit->record(AuditAction::Export, 'attendance', null, [], null, actor: $actor, entityLabel: 'Paid days ('.$period.')', metadata: [
            'export' => 'paid_days', 'rows' => $employees->count(), 'company_id' => $companyId, 'month' => sprintf('%04d-%02d', $year, $month), 'year_to_date' => $yearToDate,
        ]);

        return $csv;
    }

    /**
     * @param  array<string, mixed>  $tally
     * @param  array<string, int>  $minutes
     * @return array<string, mixed>
     */
    private function row(array $tally, array $minutes, float $arrearDays, float $change, ?PaidDayAdjustment $adjustment): array
    {
        $adjusted = max(0.0, round($tally['paid_days'] + $change, 2));

        return [
            'present' => $tally['present'], 'paid_leave' => $tally['paid_leave'], 'unpaid_leave' => $tally['unpaid_leave'], 'holiday' => $tally['holiday'],
            'weekly_off' => $tally['weekly_off'], 'absent' => $tally['absent'], 'unpaid_weekly_off' => $tally['unpaid_weekly_off'], 'half_days' => $tally['half_days'],
            'days' => $tally['days'], 'other' => $tally['other'], 'penalty_days' => $tally['penalty_days'], 'arrear_days' => round($arrearDays, 2),
            'adjustment' => round($change, 2), 'adjusted' => $adjustment !== null, 'adjustment_id' => $adjustment?->id,
            'paid_days_processed' => $tally['paid_days'], 'paid_days_adjusted' => $adjusted, 'paid_days' => max(0.0, round($adjusted - $tally['penalty_days'], 2)),
            'working_hours' => PaidDayTally::hours($minutes['working_minutes']), 'weekly_off_hours' => PaidDayTally::hours($minutes['weekly_off_minutes']),
            'holiday_hours' => PaidDayTally::hours($minutes['holiday_minutes']), 'overtime_hours' => PaidDayTally::hours($minutes['overtime_minutes']),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $months
     * @return array<string, mixed>
     */
    private function sum(array $months): array
    {
        $total = array_fill_keys(self::NUMBERS, 0.0);
        foreach ($months as $row) {
            foreach (self::NUMBERS as $key) {
                $total[$key] += (float) ($row[$key] ?? 0);
            }
        }
        $total = array_map(fn (float $v) => round($v, 2), $total);
        $total['adjusted'] = collect($months)->contains(fn (array $r) => $r['adjusted']);

        return $total;
    }

    private static function cell(mixed $value): string
    {
        if (is_float($value)) {
            return PaidDayAdjustment::number($value);
        }
        $text = (string) ($value ?? '');

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}

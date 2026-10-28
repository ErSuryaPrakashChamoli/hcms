<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\Employment\Models\Employee;

/**
 * Attendance register-cum-muster roll in the layout of Form IX of the Wages (Central) Rules, 2026 (OSH Form XIV;
 * earlier Form D): one row per employee with shift and place of work, one column per day with the processed attendance
 * code (leave shows its leave type code), days worked, overtime hours and work away from the workplace, then the
 * month's other totals. A second sheet gives the in and out time of each day worked. Days before joining or after
 * leaving are blank; days attendance has not processed show "—". Built from attendance records, which already apply
 * punches, monthly attendance uploads, regularisations and approved leave.
 */
final class MusterRoll extends Builder
{
    private const CODES = [
        'present' => 'P', 'half_day' => 'HD', 'absent' => 'A', 'incomplete' => 'A*', 'weekly_off' => 'WO', 'holiday' => 'H',
        'unpaid_leave' => 'LWP', 'wfh' => 'WFH', 'on_duty' => 'OD', 'field_duty' => 'FD', 'not_processed' => '—',
    ];

    public function build(ReportContext $context): array
    {
        $period = $context->period;
        $employees = $context->data->employees($context->company, $period->start, $period->end, $context->establishment);
        $attendance = $context->data->attendance($employees->modelKeys(), $period->start, $period->end);

        $days = [];
        for ($day = $period->start; $day->lte($period->end); $day = $day->addDay()) {
            $days[$day->toDateString()] = $day;
        }

        $times = [];
        $rows = $employees->map(function (Employee $employee) use ($context, $attendance, $days, &$times) {
            $records = $attendance[$employee->getKey()] ?? [];
            $shift = collect($records)->map(fn ($r) => $r->shift?->name)->filter()->countBy()->sortDesc()->keys()->first();
            $row = ['code' => $employee->employee_code, 'name' => $this->name($employee), 'designation' => $this->designation($context, $employee), 'shift' => (string) $shift, 'department' => $this->department($context, $employee)];
            $timeRow = ['code' => $employee->employee_code, 'name' => $this->name($employee)];
            $totals = ['present' => 0.0, 'absent' => 0.0, 'weekly_off' => 0, 'holiday' => 0, 'leave' => 0.0, 'lwp' => 0.0, 'ot' => 0];
            $away = [];
            $joined = $employee->joining_date?->toDateString();
            $left = $employee->exit_date?->toDateString();

            foreach ($days as $date => $day) {
                $key = 'd'.$day->day;
                if (($joined !== null && $date < $joined) || ($left !== null && $date > $left)) {
                    $row[$key] = $timeRow[$key] = '';

                    continue;
                }
                $record = $records[$date] ?? null;
                $row[$key] = $record === null ? '—' : $this->code($record);
                $timeRow[$key] = $record === null ? '' : $this->times($record);
                if ($record !== null) {
                    $this->count($record, $totals);
                    if (in_array($record->status, ['on_duty', 'field_duty'], true)) {
                        $away[] = $day->format('d').' '.self::CODES[$record->status].($record->note ? ' ('.$record->note.')' : '');
                    }
                }
            }
            $times[] = $timeRow;

            return $row + [
                'days_worked' => $totals['present'],
                'ot_hours' => round($totals['ot'] / 60, 2),
                'away' => implode(', ', $away),
                'absent' => $totals['absent'], 'weekly_off' => $totals['weekly_off'], 'holiday' => $totals['holiday'], 'leave' => $totals['leave'], 'lwp' => $totals['lwp'],
                'paid_days' => $totals['present'] + $totals['weekly_off'] + $totals['holiday'] + $totals['leave'],
            ];
        })->values()->all();

        $dayColumns = [];
        foreach ($days as $day) {
            $dayColumns['d'.$day->day] = $day->format('j D');
        }
        $columns = ['sl' => 'Sl. no.', 'code' => 'Employee code', 'name' => 'Name', 'designation' => 'Designation', 'shift' => 'Shift', 'department' => 'Place of work / department']
            + $dayColumns
            + ['days_worked' => 'Total days worked', 'ot_hours' => 'Total overtime hours', 'away' => 'Tour / assignment outside the workplace',
                'absent' => 'Absent', 'weekly_off' => 'Weekly offs', 'holiday' => 'Holidays', 'leave' => 'Paid leave', 'lwp' => 'LWP', 'paid_days' => 'Paid days'];

        $processed = $attendance !== [];
        $unprocessed = collect($rows)->sum(fn (array $row) => collect($dayColumns)->keys()->filter(fn ($k) => ($row[$k] ?? '') === '—')->count());
        $sheets = [$this->sheet($context, $columns, $this->numbered($rows), [
            'sheet' => 'Form IX',
            'message' => $employees->isEmpty() ? 'No employees at this establishment in the period.' : 'No attendance processed for the period.',
            'totals' => $rows === [] ? null : ReportSheet::sum($columns, $rows, ['days_worked', 'ot_hours', 'absent', 'weekly_off', 'holiday', 'leave', 'lwp', 'paid_days']),
            'warnings' => [...($unprocessed > 0 ? ["{$unprocessed} employee-day(s) have no processed attendance (shown as —)."] : []), ...$this->attributionGaps($employees)],
            'notes' => [
                'Codes: P present · HD half day · A absent · A* missed punch (unresolved) · WO weekly off · H holiday · CL/EL/SL/ML… leave of that type (½ = half day) · LWP leave without pay · WFH work from home · OD on duty · FD field duty · — not processed.',
                ...($processed ? [] : ['Attendance has not been processed for this period: run attendance processing (Attendance) before relying on this register.']),
            ],
        ])];

        if (collect($times)->flatten()->contains(fn ($v) => is_string($v) && str_contains($v, ':'))) {
            $sheets[] = $this->sheet($context, ['sl' => 'Sl. no.', 'code' => 'Employee code', 'name' => 'Name'] + $dayColumns, $this->numbered($times), [
                'title' => 'Attendance — time in and out', 'sheet' => 'In and out',
            ]);
        }

        return $sheets;
    }

    private function code(AttendanceRecord $record): string
    {
        if ($record->status === 'leave' || ($record->leave_request_id !== null && $record->status !== 'unpaid_leave')) {
            $code = (string) ($record->leaveRequest?->leaveType?->code ?: 'L');

            return $record->is_half_day_leave ? '½'.$code : $code;
        }

        return self::CODES[$record->status] ?? strtoupper((string) $record->status);
    }

    private function times(AttendanceRecord $record): string
    {
        if ($record->first_in === null && $record->last_out === null) {
            return '';
        }
        $zone = $record->timezone ?: config('app.timezone');

        return ($record->first_in?->copy()->setTimezone($zone)->format('H:i') ?? '').'–'.($record->last_out?->copy()->setTimezone($zone)->format('H:i') ?? '');
    }

    private function count(AttendanceRecord $record, array &$totals): void
    {
        $halfLeave = (bool) $record->is_half_day_leave;
        match ($record->status) {
            'present', 'wfh', 'on_duty', 'field_duty' => $totals['present'] += $halfLeave ? 0.5 : 1,
            'half_day' => [$totals['present'] += 0.5, $totals['absent'] += $halfLeave ? 0 : 0.5],
            'absent', 'incomplete' => $totals['absent'] += 1,
            'weekly_off' => $totals['weekly_off'] += 1,
            'holiday' => $totals['holiday'] += 1,
            'unpaid_leave' => $totals['lwp'] += $halfLeave ? 0.5 : 1,
            'leave' => $totals['leave'] += $halfLeave ? 0.5 : 1,
            default => null,
        };
        if ($halfLeave && $record->status !== 'leave' && $record->status !== 'unpaid_leave') {
            $totals[$record->leaveRequest?->leaveType?->is_paid === false ? 'lwp' : 'leave'] += 0.5;
        }
        $totals['ot'] += (int) $record->overtime_approved_minutes;
    }
}

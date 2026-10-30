<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollComputation;

/**
 * HROne parity TO-15 / PY-09: attendance arrears — days of earlier, payroll-locked months corrected afterwards — are
 * paid back (or recovered) in the next payroll at the current month's day rate on gross, and settled when the run
 * is finalized. Attendance supplies the days; this source prices them.
 */
final class AttendanceArrearInput implements PayrollInputSource
{
    public function key(): string
    {
        return 'attendance_arrear';
    }

    public function inputsFor(PayrollComputation $c): array
    {
        $pending = AttendanceArrear::query()->where('employee_id', $c->employee->id)->where('status', 'pending')
            ->whereDate('date', '<', $c->period->start_date->toDateString())->orderBy('date')->get(['id', 'date', 'delta_days']);
        if ($pending->isEmpty()) {
            return [];
        }
        $perDay = PayrollInputs::dailyRate($c, 'gross');
        $days = round((float) $pending->sum('delta_days'), 2);
        if ($perDay <= 0 || abs($days) < 0.001) {
            return [];
        }
        $span = $pending->first()->date->format('d M').' – '.$pending->last()->date->format('d M Y');

        return [[
            'code' => $days > 0 ? 'ATT_ARREAR' : 'ATT_RECOVERY', 'name' => ($days > 0 ? 'Attendance arrear' : 'Attendance recovery')." ({$span})",
            'type' => $days > 0 ? 'earning' : 'deduction', 'amount' => round(abs($days) * $perDay, 2), 'ids' => $pending->pluck('id')->all(),
            'taxable' => $days > 0, 'classification' => $days > 0 ? 'arrear' : 'other_deduction',
            'basis' => ['days' => $days, 'per_day' => $perDay, 'dates' => $pending->map(fn ($a) => $a->date->toDateString())->all()],
        ]];
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        foreach ($idsByEmployee as $employeeId => $ids) {
            AttendanceArrear::query()->where('employee_id', $employeeId)->whereIn('id', $ids)->where('status', 'pending')->get()
                ->each(fn (AttendanceArrear $a) => $a->withAuditReason('Settled in payroll '.$run->period?->label())->update(['status' => 'settled', 'settled_period_id' => $run->payroll_period_id, 'settled_at' => now()]));
        }
    }
}

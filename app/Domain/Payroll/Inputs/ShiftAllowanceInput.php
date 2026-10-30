<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Attendance\Services\AttendanceOutput;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollComputation;

/**
 * HROne setup parity (TO-5): shift allowance. Attendance counts the days worked on shifts that earn it (and, when the
 * attendance policy says so, long enough days worked on weekly offs and holidays); this source pays them at the policy's
 * rate a day, after its minimum days and up to its maximum. It pays from attendance quantities, so there is nothing to
 * settle: a recalculated month recalculates the allowance.
 */
final class ShiftAllowanceInput implements PayrollInputSource
{
    public function key(): string
    {
        return 'shift_allowance';
    }

    public function inputsFor(PayrollComputation $c): array
    {
        $policy = app(PolicyResolver::class)->resolve('attendance', $c->employee, $c->period->end_date);
        $rate = (float) ($policy?->setting('shift_allowance_rate') ?? 0);
        if ($rate <= 0) {
            return [];
        }
        $days = app(AttendanceOutput::class)->shiftAllowanceDays($c->employee, $c->period->start_date, $c->period->end_date);
        $min = (float) ($policy->setting('shift_allowance_min_days') ?? 0);
        $max = (float) ($policy->setting('shift_allowance_max_days') ?? 0);
        if ($days <= 0 || ($min > 0 && $days < $min)) {
            return [];
        }
        $paid = $max > 0 ? min($days, $max) : $days;

        return [[
            'code' => 'SHIFT_ALW', 'name' => 'Shift allowance', 'type' => 'earning', 'amount' => round($paid * $rate, 2), 'ids' => [],
            'taxable' => true, 'classification' => 'allowance', 'esi_applicable' => true, 'include_in_gross' => true,
            'basis' => ['days' => $days, 'paid_days' => $paid, 'rate' => $rate, 'min_days' => $min ?: null, 'max_days' => $max ?: null],
        ]];
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        // Paid from attendance quantities; nothing to mark.
    }
}

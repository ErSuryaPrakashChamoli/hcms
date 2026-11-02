<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Contracts\SalaryHoldReader;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\SalaryHold;

/** HROne parity WO-11: SalaryHoldReader — the employee's salary holds not yet released (PY-06), with the month's net pay. */
final class OpenSalaryHolds implements SalaryHoldReader
{
    public function openHolds(Employee $employee): array
    {
        return SalaryHold::query()->where('employee_id', $employee->id)->where('status', 'held')->orderBy('pay_month')->get()
            ->map(function (SalaryHold $hold) use ($employee) {
                [$year, $month] = array_map('intval', explode('-', (string) $hold->pay_month) + [1 => 0]);
                $net = PayrollEntry::query()->where('employee_id', $employee->id)
                    ->whereHas('run', fn ($q) => $q->whereIn('status', PaidPay::FINAL)->whereHas('period', fn ($p) => $p->where('year', $year)->where('month', $month)))
                    ->latest('id')->value('net_pay');

                return ['pay_month' => (string) $hold->pay_month, 'reason' => (string) $hold->reason, 'held_on' => $hold->created_at?->toDateString(), 'net' => $net !== null ? (float) $net : null];
            })->values()->all();
    }
}

<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Contracts\PaidPayReader;
use App\Domain\Payroll\Models\PayrollEntry;

final class PaidPay implements PaidPayReader
{
    public function latestPaid(Employee $employee): ?array
    {
        $entry = PayrollEntry::query()->with(['lines', 'run.period'])->where('employee_id', $employee->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['finalized', 'paid']))->latest('id')->first();
        if ($entry === null) {
            return null;
        }
        $wages = (float) $entry->lines->where('type', 'earning')->whereIn('classification', (array) config('peopleos.payroll.guardrails.wage_share.classifications', ['basic', 'da']))->sum('amount');
        $earned = (float) $entry->lines->where('type', 'earning')->sum('amount');

        return ['net' => (float) $entry->net_pay, 'gross' => (float) $entry->gross, 'wages' => $wages, 'exclusions' => max(0, $earned - $wages),
            'wage_share' => $earned > 0 ? round($wages / $earned * 100, 1) : 0.0, 'period' => $entry->run?->period?->label() ?? ''];
    }
}

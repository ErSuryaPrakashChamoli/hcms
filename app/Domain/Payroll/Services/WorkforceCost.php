<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Contracts\WorkforceCostReader;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/** Phase 10: finalized / paid employer cost for workforce budgets — a read-only aggregate. */
final class WorkforceCost implements WorkforceCostReader
{
    public function employerCost(int $companyId, CarbonInterface $from, CarbonInterface $to, ?Builder $employeeIds = null): array
    {
        $runs = PayrollRun::query()->where('company_id', $companyId)->whereIn('status', ['finalized', 'paid'])
            ->whereHas('period', fn ($q) => $q->whereDate('start_date', '>=', $from->toDateString())->whereDate('start_date', '<=', $to->toDateString()))
            ->pluck('id');
        $entries = PayrollEntry::query()->whereIn('payroll_run_id', $runs)->when($employeeIds, fn ($q) => $q->whereIn('employee_id', $employeeIds));

        return [
            'amount' => round((float) (clone $entries)->sum('employer_cost'), 2),
            'currency' => Company::query()->whereKey($companyId)->value('currency'),
            'runs' => $runs->count(),
            'employees' => (clone $entries)->distinct()->count('employee_id'),
        ];
    }
}

<?php

namespace App\Domain\Payroll\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 10 read contract: actual employer cost from finalized or paid payroll, for workforce budget
 * comparisons. Aggregated in the database; never per-employee pay, never a write.
 */
interface WorkforceCostReader
{
    /**
     * Employer cost of finalized / paid payroll whose period starts within [from, to], for one
     * company, optionally limited to the employees selected by $employeeIds (a subquery of ids).
     *
     * @return array{amount: float, currency: ?string, runs: int, employees: int}
     */
    public function employerCost(int $companyId, CarbonInterface $from, CarbonInterface $to, ?Builder $employeeIds = null): array;
}

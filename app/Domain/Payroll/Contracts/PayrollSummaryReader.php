<?php

namespace App\Domain\Payroll\Contracts;

use Carbon\CarbonInterface;

/**
 * HROne parity CA-10 (CXO access → Payroll view): read contract for dashboard payroll figures, from finalized or paid
 * payroll only, aggregated in the database (never per-employee pay, never a write).
 *
 * Every figure is summed over the payroll entries the signed-in user may see: the entry is an employee-linked record, so
 * the user's access scope and a shared view's organisation slice apply. $finalizedBefore (a view's refresh day) leaves out
 * runs finalized at or after that moment; null = every finalized run (live).
 */
interface PayrollSummaryReader
{
    /**
     * The latest payroll month with a finalized or paid entry the user may see.
     *
     * @return array{period: string, year: int, month: int, employees: int, gross: float, net_pay: float, total_deductions: float, employer_cost: float, runs: int}|null
     */
    public function latestMonth(?CarbonInterface $finalizedBefore = null): ?array;

    /**
     * Employer cost and people paid per payroll month from $from to $to (month starts), 'Y-m' => figures.
     *
     * @return array<string, array{employer_cost: float, employees: int}>
     */
    public function byMonth(CarbonInterface $from, CarbonInterface $to, ?CarbonInterface $finalizedBefore = null): array;
}

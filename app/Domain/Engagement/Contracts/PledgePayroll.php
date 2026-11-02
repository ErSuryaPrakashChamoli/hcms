<?php

namespace App\Domain\Engagement\Contracts;

/**
 * HROne parity GO-18 (owner decision D-GO3): what a fundraiser pledge deducted through payroll needs to know about the
 * employee's payroll months. Payroll binds the implementation (it reads the pay cycle and its payroll months and runs,
 * never writing them); the engagement domain never reads payroll records itself.
 *
 * A month is named "Y-m" ("2026-12") after the month it ends in, as payroll names it, for the employee's company.
 */
interface PledgePayroll
{
    /**
     * The months a pledge of this employee may be deducted in: the company's payroll month holding today and the months
     * after it (never a finalized one), plus an earlier month whose payroll is still being prepared. Empty when the
     * employee has no company to run payroll for.
     *
     * @return array<string, string> "Y-m" => label ("Dec 2026", with a note when its payroll is already calculated)
     */
    public function months(int $employeeId): array;

    /**
     * The months a fundraiser may fix as its one salary deduction month: the tenant's cycle month holding today and the
     * months after it. Each pledge is still checked against its donor's own company's months.
     *
     * @return array<string, string> "Y-m" => "Dec 2026"
     */
    public function upcoming(): array;

    /**
     * The employee's pledges a payroll run that is calculated but not finalized already deducts.
     *
     * @return array<int, string> pledge id => where it is carried ("the payroll of Oct 2026")
     */
    public function carried(int $employeeId): array;
}

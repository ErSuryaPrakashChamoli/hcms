<?php

namespace App\Domain\Expense\Contracts;

/**
 * HROne parity XO-4 (owner decision D-XO1 / EXP-7): the payroll months an approved expense report may be paid in, and the
 * company's paying bank accounts an outside payment may be recorded from. Payroll binds the implementation (it reads the
 * pay cycle and its payroll months, never writing them); the expense domain never reads payroll records itself.
 *
 * A month is named "Y-m" ("2026-12") after the month it ends in, as payroll names it, for the employee's company.
 */
interface PayrollMonths
{
    /**
     * The months a report of this employee may be scheduled for: the company's payroll month holding today and the months
     * after it (never a finalized one), plus an earlier month whose payroll is still being prepared. Empty when the
     * employee has no company to run payroll for.
     *
     * @return array<string, string> "Y-m" => label ("Dec 2026", with a note when its payroll is already calculated)
     */
    public function openFor(int $employeeId): array;

    /**
     * The months offered when reports of several employees are scheduled at once: the tenant's cycle month holding today
     * and the months after it. Each report is still checked against its own company's months.
     *
     * @return array<string, string> "Y-m" => "Dec 2026"
     */
    public function upcoming(): array;

    /** Whether the employee's payroll of the month ("Y-m") is finalized (or paid): nothing may be added to it. */
    public function isFinalized(int $employeeId, string $month): bool;

    /**
     * Active paying bank accounts: of the employee's company, or of every company when no employee is given.
     *
     * @return array<int, string> id => "HDFC salary ••••1234"
     */
    public function payingAccounts(?int $employeeId = null): array;
}

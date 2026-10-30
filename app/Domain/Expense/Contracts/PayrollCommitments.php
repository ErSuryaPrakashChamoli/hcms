<?php

namespace App\Domain\Expense\Contracts;

/**
 * HROne setup parity EX-E: which expense records payroll has already calculated a line for in a run that is not final
 * yet. Such a record is on its way to being paid or recovered by that run (settled when it is finalized), so nothing
 * else may pay, recover, refund, write off or cancel it meanwhile. Payroll binds the implementation (the expense domain
 * never reads payroll records); the keys are the payroll input sources' keys.
 */
interface PayrollCommitments
{
    /** Approved expense reports paid with payroll. */
    public const REPORT = 'expense_report';

    /** Expense advances approved for payout with payroll. */
    public const PAYOUT = 'expense_advance_payout';

    /** Dispensed expense advances recovered through payroll. */
    public const RECOVERY = 'expense_advance_recovery';

    /**
     * The employee's records of this key carried by a payroll run that is calculated but not finalized.
     *
     * @return array<int, string> record id => where it is carried ("the payroll of September 2026")
     */
    public function pending(string $key, int $employeeId): array;
}

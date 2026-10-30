<?php

namespace App\Domain\Expense\Support;

use App\Domain\Expense\Contracts\PayrollCommitments;
use App\Domain\Expense\Settlement\ExpenseSettlementSource;

/**
 * HROne setup parity EX-E: where an expense record is already on its way to being paid or recovered — a payroll run
 * calculated but not finalized, or an open exit's final settlement calculated but not paid. Every other way of paying,
 * recovering, refunding, writing off, adjusting or cancelling it is refused (or skipped) meanwhile, so nothing is paid
 * twice: not through payroll and Pay expenses, not through payroll and the final settlement.
 */
final class ExpenseCommitments
{
    public function __construct(private readonly PayrollCommitments $payroll) {}

    /** @return array<int, string> the employee's records of this key already being paid or recovered => where */
    public function pending(string $key, int $employeeId): array
    {
        return $this->payroll->pending($key, $employeeId) + ExpenseSettlementSource::pending($key, $employeeId);
    }

    /** Where this record is already being paid or recovered, or null. */
    public function of(string $key, int $employeeId, int $id): ?string
    {
        return $this->pending($key, $employeeId)[$id] ?? null;
    }
}

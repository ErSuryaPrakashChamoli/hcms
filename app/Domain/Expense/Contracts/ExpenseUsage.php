<?php

namespace App\Domain\Expense\Contracts;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseCategory;
use Carbon\CarbonInterface;

/**
 * What an employee has already claimed, for period limits, claim frequencies and the overall limit of an expense policy
 * (ExpenseLimits). The receipts module (EX-C) binds the implementation that sums receipts; until receipts exist nothing
 * has been claimed (NoExpenseUsage).
 */
interface ExpenseUsage
{
    /**
     * Claims with an expense date in [from, to] that are approved or still pending (never rejected, withdrawn or expired):
     * in one category, or in every category when $category is null, leaving out the receipt being checked.
     *
     * @return array{amount: float, km: float, count: int} amount in the base currency
     */
    public function between(Employee $employee, ?ExpenseCategory $category, CarbonInterface $from, CarbonInterface $to, ?int $exceptReceiptId = null): array;
}

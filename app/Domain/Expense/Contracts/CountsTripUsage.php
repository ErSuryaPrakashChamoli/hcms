<?php

namespace App\Domain\Expense\Contracts;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseCategory;

/**
 * HROne setup parity EX-F: an ExpenseUsage that can count one trip's expenses on their own, so a per-trip limit or
 * frequency counts what was claimed on that trip (not every expense dated within its days). Optional: ExpenseLimits
 * falls back to the trip's dates for a usage source without it.
 */
interface CountsTripUsage
{
    /** @return array{amount: float, km: float, count: int} amount in the base currency */
    public function forTrip(Employee $employee, ?ExpenseCategory $category, int $travelRequestId, ?int $exceptReceiptId = null): array;
}

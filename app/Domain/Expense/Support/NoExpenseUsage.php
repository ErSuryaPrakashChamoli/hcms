<?php

namespace App\Domain\Expense\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Contracts\ExpenseUsage;
use App\Domain\Expense\Models\ExpenseCategory;
use Carbon\CarbonInterface;

/** Nothing claimed: the usage before expense receipts existed. EX-C binds ReceiptUsage; kept for tests and tools that need no usage. */
final class NoExpenseUsage implements ExpenseUsage
{
    public function between(Employee $employee, ?ExpenseCategory $category, CarbonInterface $from, CarbonInterface $to, ?int $exceptReceiptId = null): array
    {
        return ['amount' => 0.0, 'km' => 0.0, 'count' => 0];
    }
}

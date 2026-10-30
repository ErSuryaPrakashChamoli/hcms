<?php

namespace App\Domain\Expense\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Contracts\CountsTripUsage;
use App\Domain\Expense\Contracts\ExpenseUsage;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Identity\Scopes\AccessScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * HROne setup parity EX-C: what an employee has claimed, from their expense receipts (bound for ExpenseUsage in place of
 * NoExpenseUsage). A receipt counts while it is unreported, in a report or approved — at its approved amount once
 * decided, else its claimable amount; rejected, withdrawn and expired receipts never count. Read without the viewer's
 * access scope: limits are the employee's whoever files.
 */
final class ReceiptUsage implements CountsTripUsage, ExpenseUsage
{
    public function between(Employee $employee, ?ExpenseCategory $category, CarbonInterface $from, CarbonInterface $to, ?int $exceptReceiptId = null): array
    {
        $rows = ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)
            ->where('employee_id', $employee->getKey())
            ->when($category !== null, fn ($q) => $q->where('expense_category_id', $category->getKey()))
            ->whereIn('status', ExpenseReceipt::COUNTING)
            ->whereDate('expense_date', '>=', $from->toDateString())->whereDate('expense_date', '<=', $to->toDateString())
            ->when($exceptReceiptId !== null, fn ($q) => $q->whereKeyNot($exceptReceiptId))
            ->get(['id', 'claimable_amount', 'approved_amount', 'distance_km']);

        return self::totals($rows);
    }

    /** EX-F: what the employee has claimed on one trip (per-trip limits and frequencies). */
    public function forTrip(Employee $employee, ?ExpenseCategory $category, int $travelRequestId, ?int $exceptReceiptId = null): array
    {
        return self::totals(ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)
            ->where('employee_id', $employee->getKey())->where('travel_request_id', $travelRequestId)
            ->when($category !== null, fn ($q) => $q->where('expense_category_id', $category->getKey()))
            ->whereIn('status', ExpenseReceipt::COUNTING)
            ->when($exceptReceiptId !== null, fn ($q) => $q->whereKeyNot($exceptReceiptId))
            ->get(['id', 'claimable_amount', 'approved_amount', 'distance_km']));
    }

    /** @return array{amount: float, km: float, count: int} */
    private static function totals(Collection $rows): array
    {
        return [
            'amount' => round((float) $rows->sum(fn (ExpenseReceipt $r) => $r->countedAmount()), 2),
            'km' => round((float) $rows->sum(fn (ExpenseReceipt $r) => (float) $r->distance_km), 2),
            'count' => $rows->count(),
        ];
    }
}

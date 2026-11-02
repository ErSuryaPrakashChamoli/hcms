<?php

namespace App\Domain\Expense\Support;

use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Identity\Scopes\AccessScope;
use Illuminate\Support\Collection;

/**
 * HROne parity XO-3 (matrix 26 §26.4.3 #4, #20, #21): what the report review adds up from the report's lines, read-only.
 *
 * - Distance: requested = the sum of `distance_km` of its mileage lines; approved = each decided mileage line's approved
 *   amount ÷ its rate per km (base amount ÷ distance), so a line approved in full counts its whole distance.
 * - Approved reimbursable / non-reimbursable: the approved amounts of lines repaid to the employee, and of lines the company
 *   paid (`reimbursable` off).
 * - Rejected: what the decided lines did not approve (claimable − approved), lines held as suspected excepted; held: the
 *   claimable amount of lines an approver holds out as suspected (D-XO7), until "Clear suspicion".
 * - Paid receipts: the lines with an approved amount once the report is paid.
 *
 * The lines are read as the report holds them (the report's own access decides who sees the summary).
 */
final class ExpenseReportSummary
{
    /**
     * @return array{receipts: int, mileage_lines: int, distance_requested: float, distance_approved: float, approved_reimbursable: float,
     *     approved_non_reimbursable: float, rejected: float, held: float, held_count: int, decided: int, paid_receipts: int}
     */
    public static function of(ExpenseReport $report, ?Collection $lines = null): array
    {
        $lines ??= self::lines($report);
        $mileage = $lines->filter(fn (ExpenseReceipt $r) => $r->type === ExpenseCategory::MILEAGE);
        $decided = $lines->filter(fn (ExpenseReceipt $r) => $r->approved_amount !== null && ! $r->isHeldAsSuspected());
        $held = $lines->filter(fn (ExpenseReceipt $r) => $r->isHeldAsSuspected());

        return [
            'receipts' => $lines->count(),
            'mileage_lines' => $mileage->count(),
            'distance_requested' => round((float) $mileage->sum(fn (ExpenseReceipt $r) => (float) $r->distance_km), 2),
            'distance_approved' => round((float) $mileage->filter(fn (ExpenseReceipt $r) => $r->approved_amount !== null)->sum(fn (ExpenseReceipt $r) => self::approvedDistance($r)), 2),
            'approved_reimbursable' => round((float) $decided->filter(fn (ExpenseReceipt $r) => (bool) $r->reimbursable)->sum(fn (ExpenseReceipt $r) => (float) $r->approved_amount), 2),
            'approved_non_reimbursable' => round((float) $decided->reject(fn (ExpenseReceipt $r) => (bool) $r->reimbursable)->sum(fn (ExpenseReceipt $r) => (float) $r->approved_amount), 2),
            'rejected' => round((float) $decided->sum(fn (ExpenseReceipt $r) => max(0.0, (float) $r->claimable_amount - (float) $r->approved_amount)), 2),
            'held' => round((float) $held->sum(fn (ExpenseReceipt $r) => (float) $r->claimable_amount), 2),
            'held_count' => $held->count(),
            'decided' => $decided->count(),
            'paid_receipts' => $report->paid_on !== null ? $decided->filter(fn (ExpenseReceipt $r) => (float) $r->approved_amount > 0)->count() : 0,
        ];
    }

    /** A decided mileage line's approved distance: approved ÷ rate per km (base amount ÷ distance). */
    public static function approvedDistance(ExpenseReceipt $receipt): float
    {
        $distance = (float) $receipt->distance_km;
        $base = (float) $receipt->base_amount;
        if ($receipt->approved_amount === null || $distance <= 0) {
            return 0.0;
        }
        if ($base <= 0) {
            return 0.0;
        }

        return round(min($distance, (float) $receipt->approved_amount / ($base / $distance)), 2);
    }

    /** @return Collection<int, ExpenseReceipt> */
    public static function lines(ExpenseReport $report): Collection
    {
        return ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)->with('category')->where('expense_report_id', $report->id)
            ->orderBy('expense_date')->orderBy('id')->get();
    }
}

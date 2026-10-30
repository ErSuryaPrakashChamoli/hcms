<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Expense\Models\ExpenseReceipt;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne setup parity EX-G: expense receipts (amount and mileage), with what their checks made of them (limit, over the
 * limit, exceptional, suspected duplicate and — XO-2 — the receipts it resembles), the decision and the report they went in. Amounts are in the base currency
 * unless named otherwise; HROne's "Expense receipt by amount / by mileage / details" reports read it.
 */
class ExpenseReceiptsDataset extends Dataset
{
    public function key(): string
    {
        return 'expense_receipts';
    }

    public function label(): string
    {
        return 'Expense receipts (amount and mileage)';
    }

    public function category(): string
    {
        return 'Expense & travel';
    }

    public function permissions(): array
    {
        return ['expense.view'];
    }

    public function fields(): array
    {
        $flagged = fn (string $flag) => fn ($r) => in_array($flag, (array) $r->flags, true);

        return [
            'number' => ['label' => 'Receipt', 'type' => 'string', 'value' => fn ($r) => $r->number],
            'expense_date' => ['label' => 'Expense date', 'type' => 'date', 'value' => fn ($r) => $r->expense_date],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($r) => $r->expense_date?->format('Y-m')],
            ...$this->employeeFields(fn ($r) => $r->employee),
            'category' => ['label' => 'Expense category', 'type' => 'string', 'value' => fn ($r) => $r->category?->name],
            'category_code' => ['label' => 'Category code', 'type' => 'string', 'value' => fn ($r) => $r->category?->code],
            'expense_group' => ['label' => 'Expense group', 'type' => 'string', 'value' => fn ($r) => $r->category?->group?->name],
            'type' => ['label' => 'Type', 'type' => 'string', 'value' => fn ($r) => config('peopleos.expense.types.'.$r->type, $r->type)],
            'currency' => ['label' => 'Currency', 'type' => 'string', 'value' => fn ($r) => $r->currency],
            'amount' => ['label' => 'Amount (receipt currency)', 'type' => 'number', 'value' => fn ($r) => (float) $r->amount],
            'exchange_rate' => ['label' => 'Exchange rate', 'type' => 'number', 'value' => fn ($r) => (float) $r->exchange_rate],
            'base_amount' => ['label' => 'Amount', 'type' => 'number', 'value' => fn ($r) => (float) $r->base_amount],
            'tax_amount' => ['label' => 'Tax on the receipt (receipt currency)', 'type' => 'number', 'value' => fn ($r) => round((float) $r->taxes->sum('amount'), 2)],
            'distance_km' => ['label' => 'Distance (km)', 'type' => 'number', 'value' => fn ($r) => $r->distance_km !== null ? (float) $r->distance_km : null],
            'rate_per_km' => ['label' => 'Rate per km', 'type' => 'number', 'value' => fn ($r) => $r->type === 'mileage' && (float) $r->distance_km > 0 ? round((float) $r->base_amount / (float) $r->distance_km, 2) : null],
            'from_place' => ['label' => 'From', 'type' => 'string', 'value' => fn ($r) => $r->from_place],
            'to_place' => ['label' => 'To', 'type' => 'string', 'value' => fn ($r) => $r->to_place],
            'distance_edited' => ['label' => 'Distance edited', 'type' => 'boolean', 'value' => $flagged('distance_edited')],
            'location' => ['label' => 'Expense location', 'type' => 'string', 'value' => fn ($r) => implode(', ', array_filter([$r->location_city, $r->location_state])) ?: null],
            'location_group' => ['label' => 'Location group', 'type' => 'string', 'value' => fn ($r) => $r->locationGroup?->name],
            'merchant' => ['label' => 'Merchant', 'type' => 'string', 'value' => fn ($r) => $r->merchant],
            'invoice_number' => ['label' => 'Invoice number', 'type' => 'string', 'value' => fn ($r) => $r->invoice_number],
            'purpose' => ['label' => 'Purpose', 'type' => 'string', 'value' => fn ($r) => $r->purpose],
            'limit_amount' => ['label' => 'Limit when filed', 'type' => 'number', 'value' => fn ($r) => $r->limit_amount !== null ? (float) $r->limit_amount : null],
            'over_limit_amount' => ['label' => 'Over the limit', 'type' => 'number', 'value' => fn ($r) => (float) $r->over_limit_amount],
            'claimable_amount' => ['label' => 'Claimable', 'type' => 'number', 'value' => fn ($r) => (float) $r->claimable_amount],
            'approved_amount' => ['label' => 'Approved', 'type' => 'number', 'value' => fn ($r) => $r->approved_amount !== null ? (float) $r->approved_amount : null],
            'is_exceptional' => ['label' => 'Exceptional', 'type' => 'boolean', 'value' => fn ($r) => (bool) $r->is_exceptional],
            'exception_reason' => ['label' => 'Exception reason', 'type' => 'string', 'value' => fn ($r) => $r->exception_reason],
            'suspected_duplicate' => ['label' => 'Suspected duplicate', 'type' => 'boolean', 'value' => $flagged('suspected_duplicate')],
            // HROne parity XO-2: the receipts the duplicate check matched (only those the report's reader may see).
            'resembles' => ['label' => 'Resembles receipts', 'type' => 'string', 'value' => fn ($r) => self::matchedNumbers($r)],
            'reimbursable' => ['label' => 'Repaid to the employee', 'type' => 'boolean', 'value' => fn ($r) => (bool) $r->reimbursable],
            'report_number' => ['label' => 'Expense report', 'type' => 'string', 'value' => fn ($r) => $r->report?->number],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($r) => ExpenseReceipt::STATUSES[$r->status] ?? $r->status],
            'approver_note' => ['label' => 'Approver note', 'type' => 'string', 'value' => fn ($r) => $r->approver_note],
            'filed_on' => ['label' => 'Filed on', 'type' => 'date', 'value' => fn ($r) => $r->created_at],
            'expires_on' => ['label' => 'Expires on', 'type' => 'date', 'value' => fn ($r) => $r->expires_on],
            'count' => ['label' => 'Receipts (1 per row)', 'type' => 'number', 'value' => fn ($r) => 1],
        ];
    }

    /** The numbers of the receipts the duplicate check matched, inside the reader's access scope (the rows' own scope). */
    private static function matchedNumbers(ExpenseReceipt $receipt): ?string
    {
        $ids = array_values(array_filter(array_map(fn ($match) => is_array($match) ? (int) ($match['id'] ?? 0) : 0, (array) ($receipt->duplicate_matches ?? []))));
        if ($ids === []) {
            return null;
        }

        return ExpenseReceipt::query()->whereKey($ids)->orderBy('id')->pluck('number')->implode(', ') ?: null;
    }

    public function query(): Builder
    {
        return ExpenseReceipt::query()->with([...self::employeeWith('employee'), 'category.group', 'locationGroup', 'report', 'taxes'])->orderByDesc('expense_date')->orderByDesc('id');
    }
}

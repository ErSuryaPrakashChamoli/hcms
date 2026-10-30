<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Expense\Models\ExpenseAdvanceEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne setup parity EX-G: every movement of an expense advance — dispensed, settled against a report, refunded,
 * recovered through payroll or the final settlement, written off. HROne's "Expense advance recovery register" reads it.
 */
class ExpenseAdvanceLedgerDataset extends Dataset
{
    public function key(): string
    {
        return 'expense_advance_ledger';
    }

    public function label(): string
    {
        return 'Expense advance ledger (dispensed, settled, recovered)';
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
        return [
            'entry_date' => ['label' => 'Date', 'type' => 'date', 'value' => fn ($e) => $e->entry_date],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($e) => $e->entry_date?->format('Y-m')],
            ...$this->employeeFields(fn ($e) => $e->employee),
            'advance' => ['label' => 'Advance', 'type' => 'string', 'value' => fn ($e) => $e->advance?->number],
            'kind' => ['label' => 'Movement', 'type' => 'string', 'value' => fn ($e) => ExpenseAdvanceEntry::KINDS[$e->kind] ?? $e->kind],
            'amount' => ['label' => 'Amount', 'type' => 'number', 'value' => fn ($e) => abs((float) $e->amount)],
            'signed_amount' => ['label' => 'Ledger amount (+ dispensed, − settled)', 'type' => 'number', 'value' => fn ($e) => (float) $e->amount],
            'through' => ['label' => 'Through', 'type' => 'string', 'value' => fn ($e) => match (true) {
                $e->payroll_run_id !== null => 'Payroll',
                $e->final_settlement_id !== null => 'Full and final settlement',
                $e->expense_report_id !== null => 'Expense report',
                default => 'Outside payroll',
            }],
            'report' => ['label' => 'Expense report', 'type' => 'string', 'value' => fn ($e) => $e->report?->number],
            'reference' => ['label' => 'Reference', 'type' => 'string', 'value' => fn ($e) => $e->reference],
            'note' => ['label' => 'Note', 'type' => 'string', 'value' => fn ($e) => $e->note],
            'count' => ['label' => 'Entries (1 per row)', 'type' => 'number', 'value' => fn ($e) => 1],
        ];
    }

    public function query(): Builder
    {
        return ExpenseAdvanceEntry::query()->with([...self::employeeWith('employee'), 'advance', 'report'])->orderByDesc('entry_date')->orderByDesc('id');
    }
}

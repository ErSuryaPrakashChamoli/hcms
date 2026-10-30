<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Expense\Models\ExpenseAdvance;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne setup parity EX-G: expense advances — asked for, approved, dispensed (outside payroll, with payroll or in the
 * final settlement), and how they were settled (against reports, refunded, recovered, written off) from the ledger.
 * HROne's "Expense advance" (advance amount) and "Outstanding expense advance" reports read it.
 */
class ExpenseAdvancesDataset extends Dataset
{
    public function key(): string
    {
        return 'expense_advances';
    }

    public function label(): string
    {
        return 'Expense advances';
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
        $settled = fn (string $kind) => fn ($a) => round(-(float) $a->entries->where('kind', $kind)->sum('amount'), 2);
        $overdue = fn ($a) => $a->status === 'disbursed' && (float) $a->outstanding > 0 && $a->settle_by !== null && $a->settle_by->lt(now()->startOfDay());

        return [
            'number' => ['label' => 'Advance', 'type' => 'string', 'value' => fn ($a) => $a->number],
            'requested_on' => ['label' => 'Requested on', 'type' => 'date', 'value' => fn ($a) => $a->created_at],
            'month' => ['label' => 'Month requested', 'type' => 'string', 'value' => fn ($a) => $a->created_at?->format('Y-m')],
            ...$this->employeeFields(fn ($a) => $a->employee),
            'purpose' => ['label' => 'Purpose', 'type' => 'string', 'value' => fn ($a) => $a->purpose],
            'needed_on' => ['label' => 'Needed on', 'type' => 'date', 'value' => fn ($a) => $a->needed_on],
            'currency' => ['label' => 'Currency asked in', 'type' => 'string', 'value' => fn ($a) => $a->currency],
            'amount' => ['label' => 'Asked for (that currency)', 'type' => 'number', 'value' => fn ($a) => (float) $a->amount],
            'amount_base' => ['label' => 'Asked for', 'type' => 'number', 'value' => fn ($a) => (float) $a->amount_base],
            'approved_amount' => ['label' => 'Approved', 'type' => 'number', 'value' => fn ($a) => $a->approved_amount !== null ? (float) $a->approved_amount : null],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($a) => ExpenseAdvance::STATUSES[$a->status] ?? $a->status],
            'decided_by' => ['label' => 'Decided by', 'type' => 'string', 'value' => fn ($a) => $a->decider?->name],
            'decided_on' => ['label' => 'Decided on', 'type' => 'date', 'value' => fn ($a) => $a->decided_at],
            'paid_channel' => ['label' => 'Paid', 'type' => 'string', 'value' => fn ($a) => match ($a->disbursement_channel) {
                'payroll' => 'With payroll', 'settlement' => 'In the full and final settlement', default => 'By bank',
            }],
            'disbursed_on' => ['label' => 'Dispensed on', 'type' => 'date', 'value' => fn ($a) => $a->disbursed_on],
            'disbursement_reference' => ['label' => 'Payment reference', 'type' => 'string', 'value' => fn ($a) => $a->disbursement_reference],
            'settle_by' => ['label' => 'Settle by', 'type' => 'date', 'value' => fn ($a) => $a->settle_by],
            'recovery_due_on' => ['label' => 'Recovered through payroll from', 'type' => 'date', 'value' => fn ($a) => $a->recovery_due_on],
            'adjusted' => ['label' => 'Settled against reports', 'type' => 'number', 'value' => $settled('adjusted')],
            'refunded' => ['label' => 'Refunded', 'type' => 'number', 'value' => $settled('refunded')],
            'recovered' => ['label' => 'Recovered', 'type' => 'number', 'value' => $settled('recovered')],
            'written_off' => ['label' => 'Written off', 'type' => 'number', 'value' => $settled('written_off')],
            'outstanding' => ['label' => 'Outstanding', 'type' => 'number', 'value' => fn ($a) => (float) $a->outstanding],
            'is_overdue' => ['label' => 'Past the settle-by date', 'type' => 'boolean', 'value' => $overdue],
            'days_overdue' => ['label' => 'Days past the settle-by date', 'type' => 'number', 'value' => fn ($a) => $overdue($a) ? (int) $a->settle_by->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null],
            'count' => ['label' => 'Advances (1 per row)', 'type' => 'number', 'value' => fn ($a) => 1],
        ];
    }

    public function query(): Builder
    {
        return ExpenseAdvance::query()->with([...self::employeeWith('employee'), 'decider', 'entries'])->orderByDesc('id');
    }
}

<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Expense\Models\ExpenseReport;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne setup parity EX-G: expense reports — claimed, approved, settled against advances, payable and paid (outside
 * payroll, with payroll or in the final settlement). HROne's "Expense report details" and "Expense payment register"
 * read it.
 */
class ExpenseReportsDataset extends Dataset
{
    public function key(): string
    {
        return 'expense_reports';
    }

    public function label(): string
    {
        return 'Expense reports and their payment';
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
            'number' => ['label' => 'Report', 'type' => 'string', 'value' => fn ($r) => $r->number],
            'title' => ['label' => 'Title', 'type' => 'string', 'value' => fn ($r) => $r->title],
            ...$this->employeeFields(fn ($r) => $r->employee),
            'period_from' => ['label' => 'Expenses from', 'type' => 'date', 'value' => fn ($r) => $r->period_from],
            'period_to' => ['label' => 'Expenses to', 'type' => 'date', 'value' => fn ($r) => $r->period_to],
            'month' => ['label' => 'Expense month', 'type' => 'string', 'value' => fn ($r) => $r->period_from?->format('Y-m')],
            'receipts' => ['label' => 'Receipts', 'type' => 'number', 'value' => fn ($r) => (int) $r->receipts_count],
            'currency' => ['label' => 'Currency', 'type' => 'string', 'value' => fn ($r) => $r->currency],
            'total_claimed' => ['label' => 'Claimed', 'type' => 'number', 'value' => fn ($r) => (float) $r->total_claimed],
            'total_approved' => ['label' => 'Approved', 'type' => 'number', 'value' => fn ($r) => (float) $r->total_approved],
            'company_paid' => ['label' => 'Approved, paid by the company', 'type' => 'number', 'value' => fn ($r) => round((float) $r->total_approved - (float) $r->reimbursable_approved, 2)],
            'advance_adjusted' => ['label' => 'Advances settled', 'type' => 'number', 'value' => fn ($r) => (float) $r->advance_adjusted],
            'net_payable' => ['label' => 'Net payable', 'type' => 'number', 'value' => fn ($r) => (float) $r->net_payable],
            'paid_amount' => ['label' => 'Paid', 'type' => 'number', 'value' => fn ($r) => $r->paid_on !== null ? (float) $r->net_payable : 0.0],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($r) => ExpenseReport::STATUSES[$r->status] ?? $r->status],
            'submitted_on' => ['label' => 'Submitted on', 'type' => 'date', 'value' => fn ($r) => $r->submitted_at],
            'decided_on' => ['label' => 'Decided on', 'type' => 'date', 'value' => fn ($r) => $r->decided_at],
            'decided_by' => ['label' => 'Decided by', 'type' => 'string', 'value' => fn ($r) => $r->decider?->name],
            'filed_on_behalf' => ['label' => 'Filed on behalf', 'type' => 'boolean', 'value' => fn ($r) => (bool) $r->filed_on_behalf],
            'payment_status' => ['label' => 'Payment', 'type' => 'string', 'value' => fn ($r) => $r->paymentStatus()],
            'payment_channel' => ['label' => 'Paid / to be paid', 'type' => 'string', 'value' => fn ($r) => $r->payment_channel ? (ExpenseReport::CHANNELS[$r->payment_channel] ?? $r->payment_channel) : null],
            'scheduled_on' => ['label' => 'Scheduled for payroll on', 'type' => 'date', 'value' => fn ($r) => $r->payment_scheduled_at],
            'paid_on' => ['label' => 'Paid on', 'type' => 'date', 'value' => fn ($r) => $r->paid_on],
            'paid_month' => ['label' => 'Paid in month', 'type' => 'string', 'value' => fn ($r) => $r->paid_on?->format('Y-m')],
            'payment_reference' => ['label' => 'Payment reference', 'type' => 'string', 'value' => fn ($r) => $r->payment_reference],
            'pending_days' => ['label' => 'Waiting for approval (days)', 'type' => 'number', 'value' => fn ($r) => $r->status === 'submitted' && $r->submitted_at ? (int) $r->submitted_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null],
            'count' => ['label' => 'Reports (1 per row)', 'type' => 'number', 'value' => fn ($r) => 1],
        ];
    }

    public function query(): Builder
    {
        return ExpenseReport::query()->with([...self::employeeWith('employee'), 'decider'])->withCount('receipts')->orderByDesc('id');
    }
}

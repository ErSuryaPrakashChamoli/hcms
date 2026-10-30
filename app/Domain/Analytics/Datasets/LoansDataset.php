<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Loans\Models\Loan;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity AN-26 (HROne reports "Loan request", "Loan summary"; Payroll dashboard "Loans — till date"): one row per
 * loan or salary advance with its terms, recovery so far and what is still outstanding. For payroll (payroll.manage, as
 * the Loans & advances screen, in the reader's access scope); amounts are sensitive fields of that same right, so a run
 * without a reader (the warehouse feed) leaves them out.
 */
class LoansDataset extends Dataset
{
    public function key(): string
    {
        return 'loans';
    }

    public function label(): string
    {
        return 'Loans and salary advances';
    }

    public function category(): string
    {
        return 'Payroll & compensation';
    }

    public function permissions(): array
    {
        return ['payroll.manage'];
    }

    public function sensitivePermission(): ?string
    {
        return 'payroll.manage';
    }

    public function fields(): array
    {
        $money = fn (string $label, \Closure $value) => ['label' => $label, 'type' => 'number', 'sensitive' => true, 'value' => $value];

        return [
            'number' => ['label' => 'Loan number', 'type' => 'string', 'value' => fn (Loan $l) => $l->number],
            'requested_on' => ['label' => 'Requested on', 'type' => 'date', 'value' => fn (Loan $l) => $l->created_at],
            ...$this->employeeFields(fn (Loan $l) => $l->employee),
            'loan_type' => ['label' => 'Loan type', 'type' => 'string', 'value' => fn (Loan $l) => $l->typeLabel()],
            'principal' => $money('Loan amount', fn (Loan $l) => round((float) $l->principal, 2)),
            'interest_rate' => ['label' => 'Interest (% a year)', 'type' => 'number', 'value' => fn (Loan $l) => (float) $l->interest_rate],
            'installments' => ['label' => 'Instalments', 'type' => 'number', 'value' => fn (Loan $l) => (int) $l->installments],
            'emi' => $money('Monthly recovery (EMI)', fn (Loan $l) => round((float) $l->emi, 2)),
            'first_due_month' => ['label' => 'Recovery from (month)', 'type' => 'string', 'value' => fn (Loan $l) => $l->first_due_month],
            'disbursed_through' => ['label' => 'Paid out by', 'type' => 'string', 'value' => fn (Loan $l) => $l->disbursed_through === 'bank' ? 'Bank' : 'Payroll'],
            'disbursed_on' => ['label' => 'Disbursed on', 'type' => 'date', 'value' => fn (Loan $l) => $l->disbursed_on],
            'status' => ['label' => 'Status (code)', 'type' => 'string', 'value' => fn (Loan $l) => $l->status],
            'status_label' => ['label' => 'Status', 'type' => 'string', 'value' => fn (Loan $l) => Loan::STATUSES[$l->status] ?? $l->status],
            'recovered' => $money('Recovered so far', fn (Loan $l) => round((float) $l->getAttribute('recovered_amount'), 2)),
            'outstanding' => $money('Outstanding', fn (Loan $l) => round((float) $l->outstanding, 2)),
            'installments_recovered' => ['label' => 'Instalments recovered', 'type' => 'number', 'value' => fn (Loan $l) => (int) $l->getAttribute('installments_recovered')],
            'installments_left' => ['label' => 'Instalments left', 'type' => 'number', 'value' => fn (Loan $l) => (int) $l->getAttribute('installments_left')],
            'next_due_month' => ['label' => 'Next recovery (month)', 'type' => 'string', 'value' => fn (Loan $l) => $l->getAttribute('next_due_month')],
            'guarantor' => ['label' => 'Guarantor', 'type' => 'string', 'value' => fn (Loan $l) => $l->guarantor?->person?->full_name],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn (Loan $l) => $l->reason],
            'beyond_policy' => ['label' => 'Beyond the loan policy', 'type' => 'boolean', 'value' => fn (Loan $l) => $l->exceptionSummary() !== null],
            'reviewed_on' => ['label' => 'Decided on', 'type' => 'date', 'value' => fn (Loan $l) => $l->reviewed_at],
            'reviewed_by' => ['label' => 'Decided by', 'type' => 'string', 'value' => fn (Loan $l) => $l->reviewer?->name],
            'closed_on' => ['label' => 'Closed on', 'type' => 'date', 'value' => fn (Loan $l) => $l->closed_at],
            'count' => ['label' => 'Loans (1 per row)', 'type' => 'number', 'value' => fn (Loan $l) => 1],
        ];
    }

    public function query(): Builder
    {
        $recovered = fn (Builder $q) => $q->whereIn('status', ['recovered', 'prepaid']);

        return Loan::query()->with([...self::employeeWith('employee'), 'guarantor.person', 'reviewer'])
            ->withCount(['schedule as installments_recovered' => $recovered, 'schedule as installments_left' => fn (Builder $q) => $q->whereIn('status', ['due', 'deferred'])])
            ->withSum(['schedule as recovered_amount' => $recovered], 'amount')
            ->withMin(['schedule as next_due_month' => fn (Builder $q) => $q->where('status', 'due')], 'due_month')
            ->orderByDesc('id');
    }
}

<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Models\LoanInstallment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * HROne parity AN-26 (HROne report "Loan ledger": month-wise EMI transactions): one row per instalment of every loan
 * schedule — due month, principal and interest, how it was settled. For payroll (payroll.manage) in the reader's access
 * scope; amounts are sensitive fields of that right.
 */
class LoanInstallmentsDataset extends Dataset
{
    public function key(): string
    {
        return 'loan_installments';
    }

    public function label(): string
    {
        return 'Loan ledger (instalments by month)';
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
            'due_month' => ['label' => 'Due month', 'type' => 'string', 'value' => fn (LoanInstallment $i) => $i->due_month],
            'due_on' => ['label' => 'Due month (date)', 'type' => 'date', 'value' => fn (LoanInstallment $i) => self::monthStart($i->due_month)],
            'loan_number' => ['label' => 'Loan number', 'type' => 'string', 'value' => fn (LoanInstallment $i) => $i->loan?->number],
            'loan_type' => ['label' => 'Loan type', 'type' => 'string', 'value' => fn (LoanInstallment $i) => $i->loan?->typeLabel()],
            'loan_status' => ['label' => 'Loan status', 'type' => 'string', 'value' => fn (LoanInstallment $i) => $i->loan ? (Loan::STATUSES[$i->loan->status] ?? $i->loan->status) : null],
            ...$this->employeeFields(fn (LoanInstallment $i) => $i->employee),
            'sequence' => ['label' => 'Instalment', 'type' => 'number', 'value' => fn (LoanInstallment $i) => (int) $i->sequence],
            'principal_part' => $money('Principal', fn (LoanInstallment $i) => round((float) $i->principal_part, 2)),
            'interest_part' => $money('Interest', fn (LoanInstallment $i) => round((float) $i->interest_part, 2)),
            'amount' => $money('Instalment amount', fn (LoanInstallment $i) => round((float) $i->amount, 2)),
            'status' => ['label' => 'Status (code)', 'type' => 'string', 'value' => fn (LoanInstallment $i) => $i->status],
            'status_label' => ['label' => 'Status', 'type' => 'string', 'value' => fn (LoanInstallment $i) => LoanInstallment::STATUSES[$i->status] ?? $i->status],
            'recovered_in_payroll' => ['label' => 'Recovered by payroll', 'type' => 'boolean', 'value' => fn (LoanInstallment $i) => $i->payroll_run_id !== null],
            'recovered_on' => ['label' => 'Settled on', 'type' => 'date', 'value' => fn (LoanInstallment $i) => $i->recovered_on],
            'note' => ['label' => 'Note', 'type' => 'string', 'value' => fn (LoanInstallment $i) => $i->note],
            'count' => ['label' => 'Instalments (1 per row)', 'type' => 'number', 'value' => fn (LoanInstallment $i) => 1],
        ];
    }

    public function query(): Builder
    {
        // Instalments are scoped by their employee; requiring the loan keeps the ledger to loans the reader may see too.
        return LoanInstallment::query()->with([...self::employeeWith('employee'), 'loan'])->whereHas('loan')
            ->orderByDesc('due_month')->orderBy('loan_id')->orderBy('sequence');
    }

    private static function monthStart(?string $month): ?Carbon
    {
        return $month !== null && preg_match('/^\d{4}-\d{2}$/', $month) ? Carbon::parse($month.'-01') : null;
    }
}

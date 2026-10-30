<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Services\Loans;
use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollComputation;

/** HROne parity PY-01: an approved loan paid through payroll is paid out (non-taxable) by the next run. */
final class LoanDisbursementInput implements PayrollInputSource
{
    public function key(): string
    {
        return 'loan_disbursement';
    }

    public function inputsFor(PayrollComputation $c): array
    {
        return Loan::query()->where('employee_id', $c->employee->id)->where('status', 'approved')->where('disbursed_through', 'payroll')->orderBy('id')->get()
            ->map(fn (Loan $loan) => ['code' => 'LOAN_PAYOUT', 'name' => $loan->typeLabel().' '.$loan->number, 'type' => 'reimbursement', 'amount' => (float) $loan->principal,
                'ids' => [$loan->id], 'taxable' => false, 'classification' => 'loan_disbursement', 'basis' => ['loan' => $loan->number]])
            ->values()->all();
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        $on = ($run->period?->payment_date ?? $run->period?->end_date ?? now())->toDateString();
        foreach ($idsByEmployee as $ids) {
            Loan::query()->whereIn('id', $ids)->where('status', 'approved')->get()
                ->each(fn (Loan $loan) => app(Loans::class)->markDisbursed($loan, $on, 'Paid out in payroll '.$run->period?->label()));
        }
    }
}

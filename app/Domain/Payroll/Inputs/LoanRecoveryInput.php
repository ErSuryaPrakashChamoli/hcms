<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Models\LoanInstallment;
use App\Domain\Loans\Services\Loans;
use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollComputation;

/**
 * HROne parity PY-01: instalments of active loans due in the period's month (and any earlier ones still due) are
 * recovered as one deduction per loan; finalization marks them recovered and closes paid-up loans.
 */
final class LoanRecoveryInput implements PayrollInputSource
{
    public function key(): string
    {
        return 'loan_recovery';
    }

    public function inputsFor(PayrollComputation $c): array
    {
        $month = sprintf('%04d-%02d', $c->period->year, $c->period->month);
        $due = LoanInstallment::query()->with('loan')->where('employee_id', $c->employee->id)->where('status', 'due')->where('due_month', '<=', $month)
            ->whereHas('loan', fn ($q) => $q->where('status', 'active'))->orderBy('due_month')->get();

        return $due->groupBy('loan_id')->map(function ($rows) {
            /** @var Loan $loan */
            $loan = $rows->first()->loan;

            return ['code' => 'LOAN_EMI', 'name' => $loan->typeLabel().' recovery '.$loan->number, 'type' => 'deduction', 'amount' => round((float) $rows->sum('amount'), 2),
                'ids' => $rows->pluck('id')->all(), 'classification' => 'loan_recovery',
                'basis' => ['loan' => $loan->number, 'months' => $rows->pluck('due_month')->all(), 'interest' => round((float) $rows->sum('interest_part'), 2)]];
        })->values()->all();
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        $on = ($run->period?->payment_date ?? $run->period?->end_date ?? now())->toDateString();
        app(Loans::class)->recovered(array_merge(...array_values($idsByEmployee)), $run->id, $on);
    }
}

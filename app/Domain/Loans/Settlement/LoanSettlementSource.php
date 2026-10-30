<?php

namespace App\Domain\Loans\Settlement;

use App\Domain\Exit\Contracts\SettlementSource;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Models\LoanInstallment;
use App\Domain\Loans\Services\Loans;

/**
 * HROne parity PY-01: at exit the loan balance is recovered in full and final settlement. Instalments due up to the
 * last working month are already in the final salary (payroll recovery); the rest of each active loan is one line.
 * When the settlement is paid the loans are closed.
 */
final class LoanSettlementSource implements SettlementSource
{
    public function linesFor(ExitCase $case, FinalSettlement $settlement): array
    {
        $lastMonth = $case->last_working_day->format('Y-m');

        return Loan::query()->where('employee_id', $case->employee_id)->where('status', 'active')->get()
            ->map(function (Loan $loan) use ($lastMonth) {
                $remaining = (float) LoanInstallment::query()->where('loan_id', $loan->id)->where('status', 'due')->where('due_month', '>', $lastMonth)->sum('principal_part');

                return ['type' => 'deduction', 'code' => 'LOAN_BALANCE', 'name' => $loan->typeLabel().' balance '.$loan->number, 'amount' => round($remaining, 2), 'basis' => ['loan' => $loan->number, 'after' => $lastMonth]];
            })
            ->filter(fn (array $line) => $line['amount'] > 0)->values()->all();
    }

    public function settled(ExitCase $case, FinalSettlement $settlement): void
    {
        Loan::query()->where('employee_id', $case->employee_id)->where('status', 'active')->get()
            ->each(fn (Loan $loan) => app(Loans::class)->settledAtExit($loan, 'Recovered in full and final settlement '.$case->number));
    }
}

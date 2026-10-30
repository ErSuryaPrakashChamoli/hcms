<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Contracts\PayrollCommitments;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Expense\Support\ExpenseCommitments;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollComputation;
use Carbon\CarbonImmutable;

/**
 * HROne setup parity EX-E: expense advances approved (up to the period's end) under a policy that pays them with payroll
 * are paid out, non-taxable, one line each, like a loan paid through payroll; finalizing the run dispenses them (ledger,
 * settle-by and recovery dates). Not in the month the employee leaves (finance dispenses it outside payroll instead), and
 * not when another run not yet finalized or an unpaid final settlement already carries it.
 */
final class ExpenseAdvancePayoutInput implements PayrollInputSource
{
    public function key(): string
    {
        return PayrollCommitments::PAYOUT;
    }

    public function inputsFor(PayrollComputation $c): array
    {
        $end = $c->period->end_date->copy()->endOfDay();
        $advances = ExpenseAdvance::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $c->employee->id)->where('status', 'approved')
            ->where('disbursement_channel', 'payroll')->where('decided_at', '<=', $end)->orderBy('id')->sharedLock()->get();
        if ($advances->isEmpty()) {
            return [];
        }
        // Read from the row, not the computation's model (which may have been loaded without it).
        $exit = Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($c->employee->id)->value('exit_date');
        if ($exit !== null && CarbonImmutable::parse($exit)->lte($end)) {
            return [];
        }
        $elsewhere = app(ExpenseCommitments::class)->pending($this->key(), (int) $c->employee->id);

        return $advances->reject(fn (ExpenseAdvance $a) => isset($elsewhere[$a->id]))
            ->map(fn (ExpenseAdvance $a) => ['code' => 'EXP_ADVANCE', 'name' => 'Expense advance '.$a->number, 'type' => 'reimbursement', 'amount' => (float) $a->approved_amount,
                'ids' => [$a->id], 'taxable' => false, 'classification' => 'expense_advance', 'basis' => ['advance' => $a->number]])
            ->values()->all();
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        $run->loadMissing('period');
        $on = ($run->period?->payment_date ?? $run->period?->end_date ?? now())->toDateString();
        app(ExpenseAdvances::class)->paidOut(array_merge(...array_values($idsByEmployee)), 'payroll', $on, 'Payroll '.$run->period?->label(), 'Paid out in payroll '.$run->period?->label(), (int) $run->id);
    }
}

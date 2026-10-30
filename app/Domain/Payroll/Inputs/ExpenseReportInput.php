<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Expense\Contracts\PayrollCommitments;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Expense\Support\ExpenseCommitments;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollComputation;

/**
 * HROne setup parity EX-E: approved expense reports scheduled for payroll (on approval when there is no payment step,
 * or from Pay expenses) up to the period's end are repaid, non-taxable and outside gross, as one line; finalizing the
 * run marks exactly those reports paid. A report another run not yet finalized or an unpaid final settlement already
 * carries is left to it. The rows are read with a shared lock, so Pay expenses waits for a calculation that is paying
 * the same report and then refuses it.
 */
final class ExpenseReportInput implements PayrollInputSource
{
    public function key(): string
    {
        return PayrollCommitments::REPORT;
    }

    public function inputsFor(PayrollComputation $c): array
    {
        $reports = ExpenseReport::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $c->employee->id)->whereIn('status', ['approved', 'partly_approved'])
            ->where('payment_channel', 'payroll')->whereNull('paid_on')->where('net_payable', '>', 0)
            ->where('payment_scheduled_at', '<=', $c->period->end_date->copy()->endOfDay())->orderBy('id')->sharedLock()->get();
        if ($reports->isEmpty()) {
            return [];
        }
        $elsewhere = app(ExpenseCommitments::class)->pending($this->key(), (int) $c->employee->id);
        $reports = $reports->reject(fn (ExpenseReport $r) => isset($elsewhere[$r->id]))->values();
        if ($reports->isEmpty()) {
            return [];
        }

        return [[
            'code' => 'EXPENSE', 'name' => 'Expense reimbursement', 'type' => 'reimbursement', 'amount' => round((float) $reports->sum(fn (ExpenseReport $r) => (float) $r->net_payable), 2),
            'ids' => $reports->pluck('id')->all(), 'taxable' => false, 'classification' => 'expense_reimbursement',
            'basis' => ['reports' => $reports->pluck('number')->all()],
        ]];
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        $run->loadMissing('period');
        $on = ($run->period?->payment_date ?? $run->period?->end_date ?? now())->toDateString();
        app(ExpenseReports::class)->paidThrough(array_merge(...array_values($idsByEmployee)), 'payroll', $on, 'Payroll '.$run->period?->label(), (int) $run->id);
    }
}

<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Expense\Contracts\PayrollCommitments;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Expense\Support\ExpenseCommitments;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollComputation;

/**
 * HROne setup parity EX-E: what is still outstanding on a dispensed expense advance whose recovery date (the settle-by
 * date plus the policy's days, set when it was dispensed under a policy that recovers through payroll) falls in or
 * before the period is recovered as one deduction per advance, like a loan instalment; finalizing the run posts the
 * amount deducted to the advance's ledger. Advances dispensed before this existed have no recovery date and stay as
 * they are. Nothing is recovered while the employee has an expense report waiting for a decision (its approval settles
 * advances first), nor what another run not yet finalized or an unpaid final settlement already recovers.
 */
final class ExpenseAdvanceRecoveryInput implements PayrollInputSource
{
    public function key(): string
    {
        return PayrollCommitments::RECOVERY;
    }

    public function inputsFor(PayrollComputation $c): array
    {
        $advances = ExpenseAdvance::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $c->employee->id)->where('status', 'disbursed')
            ->where('outstanding', '>', 0)->whereNotNull('recovery_due_on')->whereDate('recovery_due_on', '<=', $c->period->end_date->toDateString())
            ->orderBy('recovery_due_on')->orderBy('id')->sharedLock()->get();
        if ($advances->isEmpty()) {
            return [];
        }
        if (ExpenseReport::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $c->employee->id)->where('status', 'submitted')->exists()) {
            return [];
        }
        $elsewhere = app(ExpenseCommitments::class)->pending($this->key(), (int) $c->employee->id);

        return $advances->reject(fn (ExpenseAdvance $a) => isset($elsewhere[$a->id]))
            ->map(fn (ExpenseAdvance $a) => ['code' => 'EXP_ADV_RECOVERY', 'name' => 'Expense advance recovery '.$a->number, 'type' => 'deduction', 'amount' => (float) $a->outstanding,
                'ids' => [$a->id], 'classification' => 'expense_advance_recovery',
                'basis' => ['advance' => $a->number, 'settle_by' => $a->settle_by?->toDateString(), 'recovery_due_on' => $a->recovery_due_on?->toDateString()]])
            ->values()->all();
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        $run->loadMissing('period');
        $on = ($run->period?->payment_date ?? $run->period?->end_date ?? now())->toDateString();
        // The ledger takes what the payslip deducted: each advance's own line in this run.
        $amounts = [];
        PayrollEntryLine::query()->whereIn('payroll_entry_id', $run->entries()->select('id'))->where('code', 'EXP_ADV_RECOVERY')->get(['id', 'amount', 'basis'])
            ->each(function (PayrollEntryLine $line) use (&$amounts) {
                if (($line->basis['source']['key'] ?? null) === PayrollCommitments::RECOVERY) {
                    foreach ((array) $line->basis['source']['ids'] as $id) {
                        $amounts[(int) $id] = round(($amounts[(int) $id] ?? 0) + (float) $line->amount, 2);
                    }
                }
            });
        $carried = array_flip(array_map('intval', array_merge(...array_values($idsByEmployee))));
        app(ExpenseAdvances::class)->recovered(array_intersect_key($amounts, $carried), 'payroll-'.$run->id, $on, 'Payroll '.$run->period?->label(),
            'Recovered in payroll '.$run->period?->label(), (int) $run->id);
    }
}

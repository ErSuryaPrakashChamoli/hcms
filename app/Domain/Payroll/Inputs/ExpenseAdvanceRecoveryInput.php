<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Expense\Contracts\PayrollCommitments;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseAdvanceRecoverySchedule;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Expense\Support\ExpenseCommitments;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollComputation;
use Illuminate\Support\Collection;

/**
 * HROne setup parity EX-E: what is still outstanding on a dispensed expense advance whose recovery date (the settle-by
 * date plus the policy's days, set when it was dispensed under a policy that recovers through payroll) falls in or
 * before the period is recovered as one deduction per advance, like a loan instalment; finalizing the run posts the
 * amount deducted to the advance's ledger. Advances dispensed before this existed have no recovery date and stay as
 * they are. Nothing is recovered while the employee has an expense report waiting for a decision (its approval settles
 * advances first), nor what another run not yet finalized or an unpaid final settlement already recovers.
 *
 * HROne parity XO-6 (owner decision D-XO2 / EXP-8): recoveries finance scheduled on the Advance recovery desk are read
 * first. An advance with an open scheduled recovery is recovered only by the payroll of a scheduled month — the scheduled
 * amount, at most what is outstanding, as its own line (the basis names the schedule) — and never by the automatic date
 * meanwhile; finance chose the month and amount, so a report waiting for a decision does not hold it. An advance without
 * one follows the rule above exactly as before. Finalizing the run marks the schedule recovered with the ledger entry.
 */
final class ExpenseAdvanceRecoveryInput implements PayrollInputSource
{
    public function key(): string
    {
        return PayrollCommitments::RECOVERY;
    }

    public function inputsFor(PayrollComputation $c): array
    {
        $schedules = ExpenseAdvanceRecoverySchedule::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $c->employee->id)->where('status', 'scheduled')
            ->orderBy('recover_in')->orderBy('id')->sharedLock()->get();
        if ($schedules->isEmpty()) {
            return $this->automatic($c, []);
        }

        return [...$this->automatic($c, $schedules->pluck('expense_advance_id')->map(fn ($id) => (int) $id)->unique()->values()->all()), ...$this->scheduled($c, $schedules)];
    }

    /**
     * EX-E's recovery from the automatic date, for advances without an open scheduled recovery.
     *
     * @param  list<int>  $scheduledAdvanceIds
     */
    private function automatic(PayrollComputation $c, array $scheduledAdvanceIds): array
    {
        $advances = ExpenseAdvance::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $c->employee->id)->where('status', 'disbursed')
            ->where('outstanding', '>', 0)->whereNotNull('recovery_due_on')->whereDate('recovery_due_on', '<=', $c->period->end_date->toDateString())
            ->when($scheduledAdvanceIds !== [], fn ($q) => $q->whereNotIn('id', $scheduledAdvanceIds))
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

    /**
     * XO-6: the recoveries scheduled for this period's month, one line per advance (at most what is outstanding).
     *
     * @param  Collection<int, ExpenseAdvanceRecoverySchedule>  $schedules  the employee's open schedules
     */
    private function scheduled(PayrollComputation $c, Collection $schedules): array
    {
        $month = sprintf('%04d-%02d-01', (int) $c->period->year, (int) $c->period->month);
        // One recovery per advance in a month (the desk refuses a second one).
        $due = $schedules->filter(fn (ExpenseAdvanceRecoverySchedule $s) => $s->recover_in->toDateString() === $month)->unique('expense_advance_id')->values();
        if ($due->isEmpty()) {
            return [];
        }
        $advances = ExpenseAdvance::query()->withoutGlobalScope(AccessScope::class)->whereKey($due->pluck('expense_advance_id')->all())->where('employee_id', $c->employee->id)
            ->where('status', 'disbursed')->where('outstanding', '>', 0)->sharedLock()->get()->keyBy('id');
        $elsewhere = app(ExpenseCommitments::class)->pending($this->key(), (int) $c->employee->id);
        $lines = [];
        foreach ($due as $schedule) {
            $advance = $advances->get((int) $schedule->expense_advance_id);
            if ($advance === null || isset($elsewhere[$advance->id])) {
                continue;
            }
            $amount = round(min((float) $schedule->amount, (float) $advance->outstanding), 2);
            if ($amount <= 0) {
                continue;
            }
            $lines[] = ['code' => 'EXP_ADV_RECOVERY', 'name' => 'Expense advance recovery '.$advance->number, 'type' => 'deduction', 'amount' => $amount,
                'ids' => [$advance->id], 'classification' => 'expense_advance_recovery',
                'basis' => ['advance' => $advance->number, 'settle_by' => $advance->settle_by?->toDateString(), 'recovery_due_on' => $advance->recovery_due_on?->toDateString(),
                    'schedule' => (int) $schedule->id, 'scheduled_for' => $schedule->month(), 'scheduled_amount' => (float) $schedule->amount]];
        }

        return $lines;
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        $run->loadMissing('period');
        $on = ($run->period?->payment_date ?? $run->period?->end_date ?? now())->toDateString();
        // The ledger takes what the payslip deducted: each advance's own line in this run.
        $amounts = [];
        // XO-6: the scheduled recovery a line carried — of this run's month only — is marked recovered with its ledger entry.
        $schedules = [];
        $month = $run->period?->key();
        PayrollEntryLine::query()->whereIn('payroll_entry_id', $run->entries()->select('id'))->where('code', 'EXP_ADV_RECOVERY')->get(['id', 'amount', 'basis'])
            ->each(function (PayrollEntryLine $line) use (&$amounts, &$schedules, $month) {
                if (($line->basis['source']['key'] ?? null) === PayrollCommitments::RECOVERY) {
                    foreach ((array) $line->basis['source']['ids'] as $id) {
                        $amounts[(int) $id] = round(($amounts[(int) $id] ?? 0) + (float) $line->amount, 2);
                        if (isset($line->basis['schedule']) && ($line->basis['scheduled_for'] ?? null) === $month) {
                            $schedules[(int) $id] = (int) $line->basis['schedule'];
                        }
                    }
                }
            });
        $carried = array_flip(array_map('intval', array_merge(...array_values($idsByEmployee))));
        app(ExpenseAdvances::class)->recovered(array_intersect_key($amounts, $carried), 'payroll-'.$run->id.PayrollInputs::generation($run), $on, 'Payroll '.$run->period?->label(),
            'Recovered in payroll '.$run->period?->label(), (int) $run->id, null, $schedules);
    }

    public function unsettle(PayrollRun $run, array $idsByEmployee): void
    {
        $run->loadMissing('period');
        // What the run's lines recovered from each advance (the amounts settle() posted).
        $amounts = [];
        PayrollEntryLine::query()->whereIn('payroll_entry_id', $run->entries()->withoutGlobalScope(AccessScope::class)->select('id'))->where('code', 'EXP_ADV_RECOVERY')->get(['id', 'amount', 'basis'])
            ->each(function (PayrollEntryLine $line) use (&$amounts) {
                if (($line->basis['source']['key'] ?? null) === PayrollCommitments::RECOVERY) {
                    foreach ((array) $line->basis['source']['ids'] as $id) {
                        $amounts[(int) $id] = round(($amounts[(int) $id] ?? 0) + (float) $line->amount, 2);
                    }
                }
            });
        $carried = array_flip(array_map('intval', array_merge(...array_values($idsByEmployee))));
        app(ExpenseAdvances::class)->undoPayrollRecovery(array_intersect_key($amounts, $carried), (int) $run->id,
            'payroll-'.$run->id.PayrollInputs::generation($run).'-reversed', 'Payroll '.$run->period?->label().' reopened');
    }
}

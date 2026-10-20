<?php

namespace App\Domain\Ai\Services;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;

/**
 * AI Payroll Auditor (§94): deterministic anomaly rules over a run, each with evidence. Findings are
 * indicators for a human reviewer; nothing here changes payroll.
 */
final class PayrollAnomalyDetector
{
    /** @return array<int, array{type: string, severity: string, employee: string, employee_id: int, message: string, evidence: array<string, mixed>}> */
    public function audit(PayrollRun $run): array
    {
        $run->loadMissing('period');
        $t = config('peopleos.ai.payroll_audit');
        $findings = [];
        $entries = $run->entries()->with(['employee.person', 'employee.bankAccounts', 'employee.statutoryDetail', 'lines'])->get();
        $previousRun = PayrollRun::query()->with('period')->where('company_id', $run->company_id)->whereIn('status', ['finalized', 'paid'])->where('id', '<', $run->id)->orderByDesc('id')->first();
        $previous = $previousRun ? PayrollEntry::query()->with('lines')->where('payroll_run_id', $previousRun->id)->get()->keyBy('employee_id') : collect();

        $add = function (PayrollEntry $e, string $type, string $severity, string $message, array $evidence = []) use (&$findings) {
            $findings[] = ['type' => $type, 'severity' => $severity, 'employee' => ($e->employee?->person?->full_name ?? '—').' ('.($e->employee?->employee_code ?? '?').')', 'employee_id' => $e->employee_id, 'message' => $message, 'evidence' => $evidence];
        };

        // Duplicate bank accounts across employees in the run.
        $accounts = collect();
        foreach ($entries as $e) {
            $bank = $e->employee?->bankAccounts->firstWhere('is_primary', true);
            if ($bank) {
                $accounts->push(['entry' => $e, 'key' => strtoupper(trim((string) $bank->account_number)).'|'.strtoupper((string) $bank->ifsc)]);
            }
        }
        foreach ($accounts->groupBy('key')->filter(fn ($g) => $g->count() > 1) as $group) {
            foreach ($group as $item) {
                $add($item['entry'], 'duplicate_bank', 'high', 'Primary bank account is shared with '.($group->count() - 1).' other employee(s) in this run — possible duplicate payment.', ['shared_with' => $group->count() - 1]);
            }
        }

        foreach ($entries as $e) {
            $net = (float) $e->net_pay;
            $gross = (float) $e->gross;

            if ($e->status === 'exception') {
                $add($e, 'blocking_exception', 'high', 'Entry has blocking exceptions: '.collect($e->exceptions)->pluck('message')->implode(' '), ['exceptions' => $e->exceptions]);
            }
            if ($net <= 0 && $gross > 0) {
                $add($e, 'negative_net', 'high', 'Net pay is zero or negative while gross is '.number_format($gross, 2).'.', ['net' => $net, 'gross' => $gross]);
            }
            if ($gross > 0 && (float) $e->total_deductions / $gross * 100 > $t['deduction_share_pct']) {
                $add($e, 'deduction_share', 'medium', sprintf('Deductions are %.0f%% of gross (threshold %d%%).', (float) $e->total_deductions / $gross * 100, $t['deduction_share_pct']), ['deductions' => (float) $e->total_deductions, 'gross' => $gross]);
            }
            if ((float) $e->lop_days > $t['lop_days']) {
                $add($e, 'high_lop', 'medium', sprintf('%.1f loss-of-pay days this period (threshold %d).', (float) $e->lop_days, $t['lop_days']), ['lop_days' => (float) $e->lop_days]);
            }
            if ($e->employee?->bankAccounts->where('is_primary', true)->isEmpty()) {
                $add($e, 'no_bank', 'medium', 'No primary bank account; payment will fail.', []);
            }
            if (blank($e->employee?->statutoryDetail?->pan) && $e->amount('TDS') > 0) {
                $add($e, 'no_pan', 'low', 'PAN missing while tax is deducted; higher-rate TDS applies.', ['tds' => $e->amount('TDS')]);
            }

            $prev = $previous->get($e->employee_id);
            if ($prev) {
                $prevNet = (float) $prev->net_pay;
                if ($prevNet > 0) {
                    $change = ($net - $prevNet) / $prevNet * 100;
                    if (abs($change) > $t['net_change_pct']) {
                        $add($e, 'net_change', abs($change) > 2 * $t['net_change_pct'] ? 'high' : 'medium', sprintf('Net pay changed %+.0f%% vs %s (%s → %s).', $change, $previousRun->period->label(), number_format($prevNet, 2), number_format($net, 2)), ['previous_net' => $prevNet, 'net' => $net, 'change_pct' => round($change, 1)]);
                    }
                }
                $prevTds = $prev->amount('TDS');
                $tds = $e->amount('TDS');
                if ($prevTds > 0 && $tds > $prevTds * (1 + $t['tds_jump_pct'] / 100)) {
                    $add($e, 'tds_jump', 'medium', sprintf('TDS jumped from %s to %s (more than %d%%).', number_format($prevTds, 2), number_format($tds, 2), $t['tds_jump_pct']), ['previous_tds' => $prevTds, 'tds' => $tds]);
                }
            } elseif ($previousRun && $e->employee?->joining_date && $e->employee->joining_date->lt($previousRun->period->start_date)) {
                $add($e, 'new_in_run', 'low', 'Employee was not in the previous finalized run although employed then.', ['joining_date' => $e->employee->joining_date->toDateString()]);
            }

            // Phase 11: approved compensation history through the Compensation read contract.
            $history = app(CompensationOutput::class)->history((int) $e->employee_id);
            $revisions = $history->filter(fn ($s) => $s->effectiveFrom->betweenIncluded($run->period->start_date->copy()->startOfDay(), $run->period->end_date->copy()->startOfDay()));
            foreach ($revisions as $rev) {
                $before = $history->filter(fn ($s) => $s->effectiveFrom->lt($rev->effectiveFrom))->last();
                if ($before && $before->ctcAnnual > 0) {
                    $pct = ($rev->ctcAnnual - $before->ctcAnnual) / $before->ctcAnnual * 100;
                    if (abs($pct) > $t['salary_revision_pct']) {
                        $add($e, 'salary_revision', 'high', sprintf('Salary revised %+.0f%% effective %s (%s → %s).', $pct, $rev->effectiveFrom->toDateString(), number_format($before->ctcAnnual), number_format($rev->ctcAnnual)), ['from' => $before->ctcAnnual, 'to' => $rev->ctcAnnual, 'reason' => $rev->reason]);
                    }
                }
            }
            if ($e->employee?->exit_date && $e->employee->exit_date->lt($run->period->start_date)) {
                $add($e, 'exited_employee', 'high', 'Employee exited on '.$e->employee->exit_date->toDateString().', before this period started.', ['exit_date' => $e->employee->exit_date->toDateString()]);
            }
        }

        usort($findings, fn ($a, $b) => ['high' => 0, 'medium' => 1, 'low' => 2][$a['severity']] <=> ['high' => 0, 'medium' => 1, 'low' => 2][$b['severity']]);

        return $findings;
    }
}

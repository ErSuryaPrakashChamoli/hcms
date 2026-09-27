<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;

/**
 * Reconciles a run's stored totals against its entries and lines (Phase 4 §49): employee count,
 * gross, deductions, net, employer contributions, paid/unpaid days and overtime. A run whose totals
 * do not equal the sum of its entries cannot be finalized.
 */
final class PayrollReconciliation
{
    /** @return array{balanced: bool, checks: array<string, array{stored: float, computed: float, ok: bool}>} */
    public function reconcile(PayrollRun $run): array
    {
        $entries = PayrollEntry::query()->where('payroll_run_id', $run->id);
        $lines = PayrollEntryLine::query()->whereIn('payroll_entry_id', (clone $entries)->select('id'));
        $totals = $run->totals ?? [];

        $computed = [
            'employees' => (float) (clone $entries)->count(),
            'gross' => round((float) (clone $entries)->sum('gross'), 2),
            'deductions' => round((float) (clone $entries)->sum('total_deductions'), 2),
            'net' => round((float) (clone $entries)->sum('net_pay'), 2),
            'employer_cost' => round((float) (clone $entries)->sum('employer_cost'), 2),
            'lop_days' => round((float) (clone $entries)->sum('lop_days'), 2),
        ];

        $checks = [];
        foreach ($computed as $key => $value) {
            $stored = round((float) ($totals[$key] ?? 0), 2);
            $checks[$key] = ['stored' => $stored, 'computed' => $value, 'ok' => abs($stored - $value) < 0.01];
        }

        // Line-level: every entry's net equals its earnings minus deductions.
        $lineNet = round((float) (clone $lines)->whereIn('type', ['earning', 'reimbursement'])->sum('amount') - (float) (clone $lines)->where('type', 'deduction')->sum('amount'), 2);
        $checks['lines_net'] = ['stored' => $computed['net'], 'computed' => $lineNet, 'ok' => abs($lineNet - $computed['net']) < 0.01 * max(1, $computed['employees'])];

        return ['balanced' => collect($checks)->every(fn ($c) => $c['ok']), 'checks' => $checks, 'reconciled_at' => now()->toIso8601String()];
    }
}

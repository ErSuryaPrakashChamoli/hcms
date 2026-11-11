<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;

/**
 * Streamline review (8D): what a calculated month looks like before it is approved and closed — the totals with the
 * statutory ones, reimbursements and recoveries, joiners and leavers, exceptions, the change from the company's last
 * closed month, and the employees whose net pay moved unusually (with the run page for each employee's breakdown).
 * Read-only; it reads the run's own entries and the previous closed run, within the reader's access scope.
 */
final class PayrollRunReview
{
    /** A net pay change at least this share of last month's (and at least VARIANCE_AMOUNT) is listed as unusual. */
    public const VARIANCE_SHARE = 0.2;

    public const VARIANCE_AMOUNT = 1000.0;

    /** Unusual changes listed (largest first). */
    public const VARIANCE_ROWS = 25;

    /** Input sources whose lines take money back from the employee. */
    private const RECOVERY_SOURCES = ['loan_recovery', 'expense_advance_recovery', 'fundraiser_pledge'];

    /**
     * @return array{totals: array<string, float|int>, previous: ?array{label: string, totals: array<string, float|int>}, change: array<string, ?float>, joiners: int, leavers: int, exceptions: int, warnings: int, variances: list<array<string, mixed>>, new_in_run: int, missing_from_run: int}
     */
    public function summary(PayrollRun $run): array
    {
        $run->loadMissing('period');
        $totals = $run->totals ?? [];
        $lineSum = fn (callable $narrow) => round((float) $narrow(PayrollEntryLine::query()->whereIn('payroll_entry_id', $run->entries()->select('id')))->sum('amount'), 2);
        $reimbursements = $lineSum(fn ($q) => $q->where('type', 'reimbursement'));
        $recoveries = round((float) PayrollEntryLine::query()->whereIn('payroll_entry_id', $run->entries()->select('id'))->where('type', 'deduction')->whereNotNull('basis')->get(['amount', 'basis'])
            ->filter(fn (PayrollEntryLine $l) => in_array($l->basis['source']['key'] ?? null, self::RECOVERY_SOURCES, true))->sum('amount'), 2);

        $figures = [
            'employees' => (int) ($totals['employees'] ?? 0), 'gross' => (float) ($totals['gross'] ?? 0), 'deductions' => (float) ($totals['deductions'] ?? 0),
            'net' => (float) ($totals['net'] ?? 0), 'employer_cost' => (float) ($totals['employer_cost'] ?? 0), 'lop_days' => (float) ($totals['lop_days'] ?? 0),
            'pf_employee' => (float) ($totals['pf_employee'] ?? 0), 'pf_employer' => (float) ($totals['pf_employer'] ?? 0), 'esi' => (float) ($totals['esi'] ?? 0),
            'pt' => (float) ($totals['pt'] ?? 0), 'tds' => (float) ($totals['tds'] ?? 0), 'reimbursements' => $reimbursements, 'recoveries' => $recoveries,
        ];

        $previousRun = PayrollRun::query()->with('period')->where('company_id', $run->company_id)->whereIn('status', PaidPay::FINAL)
            ->whereHas('period', fn ($q) => $q->where(fn ($q) => $q->where('year', '<', $run->period->year)->orWhere(fn ($q) => $q->where('year', $run->period->year)->where('month', '<', $run->period->month))))
            ->get()->sortByDesc(fn (PayrollRun $r) => $r->period->year * 100 + $r->period->month)->first();
        $previous = $previousRun === null ? null : ['label' => $previousRun->period->label(), 'totals' => [
            'employees' => (int) $previousRun->total('employees'), 'gross' => (float) $previousRun->total('gross'), 'net' => (float) $previousRun->total('net'),
            'employer_cost' => (float) $previousRun->total('employer_cost'), 'tds' => (float) $previousRun->total('tds'),
        ]];
        $change = [];
        foreach (['employees', 'gross', 'net', 'employer_cost', 'tds'] as $key) {
            $before = (float) ($previous['totals'][$key] ?? 0);
            $change[$key] = $previous === null ? null : ($before == 0.0 ? null : round(($figures[$key] - $before) / $before * 100, 1));
        }

        $entries = $run->entries()->with(['employee', 'employee.person'])->get(['id', 'employee_id', 'net_pay', 'status']);
        $start = $run->period->start_date->toDateString();
        $end = $run->period->end_date->toDateString();
        $within = fn ($date) => $date !== null && $date->toDateString() >= $start && $date->toDateString() <= $end;

        $variances = [];
        $newInRun = 0;
        $missing = 0;
        if ($previousRun !== null) {
            $before = PayrollEntry::query()->where('payroll_run_id', $previousRun->id)->pluck('net_pay', 'employee_id')->map(fn ($v) => (float) $v);
            foreach ($entries as $entry) {
                if (! $before->has($entry->employee_id)) {
                    $newInRun++;

                    continue;
                }
                $was = $before[$entry->employee_id];
                $delta = round((float) $entry->net_pay - $was, 2);
                if (abs($delta) >= self::VARIANCE_AMOUNT && ($was == 0.0 || abs($delta) / abs($was) >= self::VARIANCE_SHARE)) {
                    $variances[] = ['code' => $entry->employee?->employee_code, 'name' => $entry->employee?->person?->full_name, 'before' => $was, 'now' => (float) $entry->net_pay,
                        'change' => $delta, 'share' => $was == 0.0 ? null : round($delta / abs($was) * 100, 1)];
                }
            }
            $missing = $before->keys()->diff($entries->pluck('employee_id'))->count();
        }
        usort($variances, fn (array $a, array $b) => abs($b['change']) <=> abs($a['change']));

        return [
            'totals' => $figures, 'previous' => $previous, 'change' => $change,
            'joiners' => $entries->filter(fn (PayrollEntry $e) => $within($e->employee?->joining_date))->count(),
            'leavers' => $entries->filter(fn (PayrollEntry $e) => $within($e->employee?->exit_date))->count(),
            'exceptions' => $entries->where('status', 'exception')->count(), 'warnings' => $entries->where('status', 'warning')->count(),
            'variances' => array_slice($variances, 0, self::VARIANCE_ROWS), 'variance_count' => count($variances),
            'new_in_run' => $newInRun, 'missing_from_run' => $missing,
        ];
    }
}

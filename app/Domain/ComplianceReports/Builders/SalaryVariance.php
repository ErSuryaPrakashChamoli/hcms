<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;
use Illuminate\Support\Collection;

/**
 * Salary variance: the month's payroll against the previous month's, per employee (gross, deductions, net, days and
 * the components that changed, with joiners and leavers marked) and per component. It reads the newest calculated
 * run of each month, finalized or not, so it can be checked before finalization; the run status is in the header.
 */
final class SalaryVariance extends Builder
{
    public function build(ReportContext $context): array
    {
        $current = $context->data->latestRun($context->company, $context->period);
        $previous = $context->data->latestRun($context->company, $context->period->previous());
        $now = $this->entries($current);
        $before = $this->entries($previous);
        $runs = [
            ['Current run', $current ? $this->runLabel($current) : 'none calculated'],
            ['Previous run', $previous ? $this->runLabel($previous) : 'none calculated'],
        ];

        $rows = [];
        foreach ($now->keys()->merge($before->keys())->unique()->sort() as $employeeId) {
            /** @var PayrollEntry|null $a */
            $a = $before->get($employeeId);
            /** @var PayrollEntry|null $b */
            $b = $now->get($employeeId);
            $employee = ($b ?? $a)->employee;
            $gross = [(float) ($a?->total_earnings ?? 0), (float) ($b?->total_earnings ?? 0)];
            $net = [(float) ($a?->net_pay ?? 0), (float) ($b?->net_pay ?? 0)];

            $rows[] = [
                'code' => $employee?->employee_code,
                'name' => $this->name($employee),
                'status' => $a === null ? 'New in payroll' : ($b === null ? 'Not in this month\'s payroll' : (abs($gross[1] - $gross[0]) < 0.005 && abs($net[1] - $net[0]) < 0.005 ? 'No change' : 'Changed')),
                'days_before' => $a ? (float) $a->paid_days : null,
                'days_now' => $b ? (float) $b->paid_days : null,
                'gross_before' => round($gross[0], 2),
                'gross_now' => round($gross[1], 2),
                'gross_change' => round($gross[1] - $gross[0], 2),
                'gross_pct' => $gross[0] > 0 ? round(($gross[1] - $gross[0]) / $gross[0] * 100, 1) : null,
                'deductions_change' => round((float) ($b?->total_deductions ?? 0) - (float) ($a?->total_deductions ?? 0), 2),
                'net_before' => round($net[0], 2),
                'net_now' => round($net[1], 2),
                'net_change' => round($net[1] - $net[0], 2),
                'components' => $this->componentChanges($a, $b),
            ];
        }

        $columns = ['sl' => 'Sl.', 'code' => 'Code', 'name' => 'Name', 'status' => 'Status', 'days_before' => 'Paid days (prev.)', 'days_now' => 'Paid days',
            'gross_before' => 'Gross (prev.)', 'gross_now' => 'Gross', 'gross_change' => 'Gross change', 'gross_pct' => 'Change %', 'deductions_change' => 'Deductions change',
            'net_before' => 'Net (prev.)', 'net_now' => 'Net', 'net_change' => 'Net change', 'components' => 'Components changed'];
        $money = ['gross_before', 'gross_now', 'gross_change', 'deductions_change', 'net_before', 'net_now', 'net_change'];

        $summary = $this->summary($now, $before);
        $summaryColumns = ['item' => 'Item', 'before' => $context->period->previous()->label(), 'now' => $context->period->label(), 'change' => 'Change'];

        return [
            $this->sheet($context, $columns, $this->numbered($rows), ['money' => $money, 'totals' => $rows === [] ? null : ReportSheet::sum($columns, $rows, $money), 'sheet' => 'By employee',
                'message' => 'No calculated payroll in either month.'])->withHeader($runs),
            $this->sheet($context, $summaryColumns, $summary, ['title' => 'Salary variance — summary', 'sheet' => 'Summary', 'money' => ['before', 'now', 'change'],
                'message' => 'No calculated payroll in either month.'])->withHeader($runs),
        ];
    }

    /** @return Collection<int, PayrollEntry> */
    private function entries(?PayrollRun $run): Collection
    {
        return ($run?->entries ?? collect())->keyBy('employee_id');
    }

    private function runLabel(PayrollRun $run): string
    {
        return "#{$run->id} ".config("peopleos.payroll.run_statuses.{$run->status}", $run->status).(in_array($run->status, ['finalized', 'paid'], true) ? '' : ' — not finalized, figures can still change');
    }

    private function componentChanges(?PayrollEntry $a, ?PayrollEntry $b): string
    {
        if ($a === null || $b === null) {
            return '';
        }
        $before = $a->lines->groupBy('code')->map(fn ($l) => (float) $l->sum('amount'));
        $now = $b->lines->groupBy('code')->map(fn ($l) => (float) $l->sum('amount'));

        return $before->keys()->merge($now->keys())->unique()
            ->map(fn ($code) => [$code, round(($now[$code] ?? 0) - ($before[$code] ?? 0), 2)])
            ->filter(fn ($c) => abs($c[1]) >= 0.01)
            ->map(fn ($c) => $c[0].' '.($c[1] > 0 ? '+' : '').number_format($c[1], 2))
            ->implode('; ');
    }

    /** Headcount, totals and every component, both months. */
    private function summary(Collection $now, Collection $before): array
    {
        $line = fn (string $item, float $a, float $b) => ['item' => $item, 'before' => round($a, 2), 'now' => round($b, 2), 'change' => round($b - $a, 2)];
        $rows = [
            $line('Employees paid', $before->count(), $now->count()),
            $line('Joined payroll this month', 0, $now->keys()->diff($before->keys())->count()),
            $line('Left payroll this month', $before->keys()->diff($now->keys())->count(), 0),
            $line('Gross earnings', $before->sum('total_earnings'), $now->sum('total_earnings')),
            $line('Total deductions', $before->sum('total_deductions'), $now->sum('total_deductions')),
            $line('Net pay', $before->sum('net_pay'), $now->sum('net_pay')),
            $line('Employer cost', $before->sum('employer_cost'), $now->sum('employer_cost')),
        ];

        $components = $before->flatMap(fn ($e) => $e->lines)->merge($now->flatMap(fn ($e) => $e->lines))->sortBy('sort_order')->pluck('name', 'code');
        foreach ($components as $code => $name) {
            $rows[] = $line("{$name} ({$code})", (float) $before->sum(fn ($e) => $e->lines->where('code', $code)->sum('amount')), (float) $now->sum(fn ($e) => $e->lines->where('code', $code)->sum('amount')));
        }

        return $now->isEmpty() && $before->isEmpty() ? [] : $rows;
    }
}

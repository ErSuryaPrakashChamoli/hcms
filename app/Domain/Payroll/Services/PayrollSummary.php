<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Payroll\Contracts\PayrollSummaryReader;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * HROne parity CA-10: dashboard payroll figures from finalized / paid payroll (see the contract). Runs are read for their
 * period and finalisation time only; the money comes from the entries the signed-in user may see (access scope + slice).
 */
final class PayrollSummary implements PayrollSummaryReader
{
    public function latestMonth(?CarbonInterface $finalizedBefore = null): ?array
    {
        $runs = $this->runs($finalizedBefore);
        if ($runs->isEmpty()) {
            return null;
        }
        $visible = $this->totalsByRun($runs->keys()->all());
        $months = $runs->filter(fn ($run, $id) => isset($visible[$id]))->groupBy(fn ($run) => sprintf('%04d-%02d', $run->period->year, $run->period->month));
        if ($months->isEmpty()) {
            return null;
        }
        $inMonth = $months[$months->keys()->sort()->last()];
        $ids = $inMonth->pluck('id')->map(fn ($id) => (int) $id)->all();
        $period = $inMonth->first()->period;
        $sum = fn (string $field) => round(array_sum(array_map(fn (int $id) => $visible[$id][$field], $ids)), 2);

        return [
            'period' => $period->label(), 'year' => (int) $period->year, 'month' => (int) $period->month,
            'employees' => (int) PayrollEntry::query()->whereIn('payroll_run_id', $ids)->distinct()->count('employee_id'),
            'gross' => $sum('gross'), 'net_pay' => $sum('net_pay'), 'total_deductions' => $sum('total_deductions'), 'employer_cost' => $sum('employer_cost'),
            'runs' => count($ids),
        ];
    }

    public function byMonth(CarbonInterface $from, CarbonInterface $to, ?CarbonInterface $finalizedBefore = null): array
    {
        $runs = $this->runs($finalizedBefore)->filter(function ($run) use ($from, $to) {
            $key = sprintf('%04d-%02d', $run->period->year, $run->period->month);

            return $key >= $from->format('Y-m') && $key <= $to->format('Y-m');
        });
        $out = [];
        if ($runs->isEmpty()) {
            return $out;
        }
        $visible = $this->totalsByRun($runs->keys()->all());
        foreach ($runs->groupBy(fn ($run) => sprintf('%04d-%02d', $run->period->year, $run->period->month)) as $key => $inMonth) {
            $ids = array_values(array_filter($inMonth->pluck('id')->map(fn ($id) => (int) $id)->all(), fn (int $id) => isset($visible[$id])));
            if ($ids === []) {
                continue;
            }
            $out[$key] = [
                'employer_cost' => round(array_sum(array_map(fn ($id) => $visible[$id]['employer_cost'], $ids)), 2),
                'employees' => (int) PayrollEntry::query()->whereIn('payroll_run_id', $ids)->distinct()->count('employee_id'),
            ];
        }

        return $out;
    }

    /** @return Collection<int, PayrollRun> finalized or paid runs (finalized before the moment, when given), by id */
    private function runs(?CarbonInterface $finalizedBefore): Collection
    {
        return PayrollRun::query()->with('period')->whereIn('status', ['finalized', 'paid'])
            ->when($finalizedBefore !== null, fn ($q) => $q->whereNotNull('finalized_at')->where('finalized_at', '<', $finalizedBefore))
            ->get(['id', 'payroll_period_id', 'status', 'finalized_at'])->filter(fn (PayrollRun $run) => $run->period !== null)->keyBy('id');
    }

    /**
     * Sums of the entries the user may see, per run (runs with none are absent).
     *
     * @param  list<int>  $runIds
     * @return array<int, array{gross: float, net_pay: float, total_deductions: float, employer_cost: float}>
     */
    private function totalsByRun(array $runIds): array
    {
        return PayrollEntry::query()->whereIn('payroll_run_id', $runIds)->groupBy('payroll_run_id')
            ->selectRaw('payroll_run_id, sum(gross) as gross, sum(net_pay) as net_pay, sum(total_deductions) as total_deductions, sum(employer_cost) as employer_cost, count(*) as n')
            ->toBase()->get()
            ->mapWithKeys(fn ($row) => [(int) $row->payroll_run_id => ['gross' => (float) $row->gross, 'net_pay' => (float) $row->net_pay, 'total_deductions' => (float) $row->total_deductions, 'employer_cost' => (float) $row->employer_cost]])
            ->all();
    }
}

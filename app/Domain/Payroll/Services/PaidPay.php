<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Compliance\Services\VerifiedThresholds;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Payroll\Contracts\PaidPayReader;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\SalaryComponent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class PaidPay implements PaidPayReader
{
    /** Run statuses whose figures self-service may show: payroll that is final. */
    public const FINAL = ['finalized', 'paid'];

    /** Line types of the "Salary" tab, in their order. */
    public const SALARY_TYPES = ['earning' => 'Earnings', 'deduction' => 'Deductions', 'employer_contribution' => 'Employer contributions'];

    public function latestPaid(Employee $employee): ?array
    {
        $entry = PayrollEntry::query()->with(['lines', 'run.period'])->where('employee_id', $employee->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', self::FINAL))->latest('id')->first();
        if ($entry === null) {
            return null;
        }
        $wageLines = $entry->lines->where('type', 'earning')->whereIn('classification', (array) config('peopleos.payroll.guardrails.wage_share.classifications', ['basic', 'da']));
        $wages = (float) $wageLines->sum('amount');
        $earned = (float) $entry->lines->where('type', 'earning')->sum('amount');

        return ['net' => (float) $entry->net_pay, 'gross' => (float) $entry->gross, 'wages' => $wages, 'exclusions' => max(0, $earned - $wages),
            'wage_share' => $earned > 0 ? round($wages / $earned * 100, 1) : 0.0, 'period' => $entry->run?->period?->label() ?? '',
            // HROne parity MS-9: the month and state the wage-code verdict is judged for, and the wages of a full month
            // (before proration for unpaid days, as the line recorded them) for the minimum-wage check.
            'period_end' => $entry->run?->period?->end_date?->toDateString(), 'state' => self::stateOf($entry),
            'wages_full_month' => round((float) $wageLines->sum(fn (PayrollEntryLine $l) => (float) ($l->basis['full_month'] ?? $l->amount)), 2)];
    }

    public function yearToDate(Employee $employee, string $financialYear): array
    {
        [$start, $months] = self::months($financialYear);
        $first = $start->year * 12 + $start->month;

        $entries = PayrollEntry::query()->with(['lines', 'run.period'])->where('employee_id', $employee->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', self::FINAL))
            ->whereHas('run.period', fn ($q) => $q->whereRaw('('.$q->qualifyColumn('year').' * 12 + '.$q->qualifyColumn('month').') between ? and ?', [$first, $first + 11]))
            ->get()->filter(fn (PayrollEntry $e) => $e->run?->period !== null && isset($months[$e->run->period->key()]))
            ->sortBy(fn (PayrollEntry $e) => $e->run->period->key().'|'.str_pad((string) $e->id, 12, '0', STR_PAD_LEFT))->values();

        $zero = array_fill_keys(array_keys($months), 0.0);
        $rows = [];
        $arrears = [];
        $statutory = ['pf' => [], 'vpf' => [], 'esi' => [], 'pt' => [], 'lwf' => []];
        $overtime = [];
        $bonus = $zero;
        $days = [];
        $totals = ['gross' => $zero, 'deductions' => $zero, 'net' => $zero, 'reimbursements' => $zero];
        $components = SalaryComponent::query()->whereIn('id', $entries->flatMap(fn (PayrollEntry $e) => $e->lines->pluck('salary_component_id'))->filter()->unique()->values()->all())->get()->keyBy('id');

        foreach ($entries as $entry) {
            $month = $entry->run->period->key();
            $totals['gross'][$month] += (float) $entry->gross;
            $totals['deductions'][$month] += (float) $entry->total_deductions;
            $totals['net'][$month] += (float) $entry->net_pay;
            $days[$month] = [
                'in_period' => ($days[$month]['in_period'] ?? 0) + (int) $entry->days_in_period,
                'paid' => ($days[$month]['paid'] ?? 0.0) + (float) $entry->paid_days,
                'lop' => ($days[$month]['lop'] ?? 0.0) + (float) $entry->lop_days,
            ];
            $minutes = (int) data_get($entry->inputs, 'attendance.approved_overtime_minutes', 0);

            foreach ($entry->lines as $line) {
                $basis = (array) ($line->basis ?? []);
                // As on the payslip: components hidden from the payslip are paid but not shown (HROne setup parity PS-1).
                if ((bool) ($basis['hidden_on_payslip'] ?? false)) {
                    continue;
                }
                $amount = (float) $line->amount;
                $key = $line->type.'|'.$line->code;
                $rows[$key] ??= [
                    'key' => $key, 'code' => (string) $line->code, 'type' => (string) $line->type, 'classification' => (string) $line->classification,
                    'name' => $line->salary_component_id && $components->has($line->salary_component_id) ? (string) $components[$line->salary_component_id]->name : self::plainName((string) $line->name),
                    'component_id' => $line->salary_component_id ? (int) $line->salary_component_id : null,
                    'settings' => $line->salary_component_id && $components->has($line->salary_component_id) ? self::settings($components[$line->salary_component_id]) : null,
                    'months' => $zero, 'total' => 0.0, 'arrears' => [], 'detail' => [],
                ];
                $rows[$key]['months'][$month] += $amount;
                $rows[$key]['total'] += $amount;
                if (isset($basis['formula']) || isset($basis['divisor'])) {
                    $rows[$key]['detail'][$month] = ['formula' => $basis['formula'] ?? null, 'divisor' => $basis['divisor'] ?? null, 'full_month' => isset($basis['full_month']) ? (float) $basis['full_month'] : null,
                        'prorated' => (bool) ($basis['prorated'] ?? false), 'paid_days' => (float) $entry->paid_days, 'days_in_period' => (int) $entry->days_in_period];
                }
                if (self::isArrear($line)) {
                    $arrear = ['month' => $month, 'code' => (string) $line->code, 'name' => (string) $line->name, 'type' => (string) $line->type, 'amount' => $amount, 'note' => isset($basis['note']) ? (string) $basis['note'] : null];
                    $rows[$key]['arrears'][] = $arrear;
                    $arrears[] = $arrear;
                }
                if ($line->type === 'reimbursement') {
                    $totals['reimbursements'][$month] += $amount;
                }
                if ($line->type === 'earning' && $line->classification === 'bonus') {
                    $bonus[$month] += $amount;
                }
                if ($line->type === 'earning' && str_contains((string) ($basis['formula'] ?? ''), 'overtime_')) {
                    $overtime[$month]['amount'] = ($overtime[$month]['amount'] ?? 0.0) + $amount;
                }
                self::statutory($statutory, $line, $basis, $month, $amount);
            }
            if ($minutes > 0) {
                $overtime[$month]['minutes'] = ($overtime[$month]['minutes'] ?? 0) + $minutes;
            }
        }

        $round = fn (array $values) => array_map(fn ($v) => round((float) $v, 2), $values);
        $finish = function (array $row) use ($round) {
            $row['months'] = $round($row['months']);
            $row['total'] = round($row['total'], 2);

            return $row;
        };
        $grouped = collect($rows)->map($finish)->groupBy('type');
        $fyEnd = $start->copy()->addYear()->subDay();

        return [
            'financial_year' => $financialYear,
            'months' => $months,
            'processed' => $entries->map(fn (PayrollEntry $e) => $e->run->period->key())->unique()->values()->all(),
            'salary' => collect(self::SALARY_TYPES)->map(fn ($label, $type) => ($grouped[$type] ?? collect())->values()->all())->all(),
            'reimbursement' => ($grouped['reimbursement'] ?? collect())->values()->all(),
            'totals' => collect($totals)->map(fn (array $values) => ['months' => $round($values), 'total' => round(array_sum($values), 2)])->all(),
            'arrears' => $arrears,
            'statutory' => $this->finishStatutory($statutory),
            'overtime' => [
                'months' => collect($overtime)->map(fn ($m) => ['minutes' => (int) ($m['minutes'] ?? 0), 'amount' => round((float) ($m['amount'] ?? 0), 2)])->all(),
                'minutes' => (int) collect($overtime)->sum(fn ($m) => (int) ($m['minutes'] ?? 0)),
                'amount' => round((float) collect($overtime)->sum(fn ($m) => (float) ($m['amount'] ?? 0)), 2),
            ],
            'bonus' => ['months' => $round($bonus), 'total' => round(array_sum($bonus), 2), 'rule' => $this->bonusRule($fyEnd)],
            'days' => $days,
        ];
    }

    /**
     * The months of a financial year in order, 'Y-m' => 'M Y' (the year starts in the configured month).
     *
     * @return array{0: Carbon, 1: array<string, string>}
     */
    public static function months(string $financialYear): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $financialYear, $m) !== 1 || (int) substr((string) ((int) $m[1] + 1), -2) !== (int) $m[2]) {
            throw new InvalidArgumentException("Unknown financial year [{$financialYear}].");
        }
        $start = Carbon::create((int) $m[1], (int) config('peopleos.compliance.financial_year_start_month', 4), 1)->startOfDay();
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $months[$month->format('Y-m')] = $month->format('M Y');
        }

        return [$start, $months];
    }

    public function finalEntries(int $companyId, string $month): Collection
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $month, $m) !== 1) {
            throw new InvalidArgumentException("Unknown payroll month [{$month}].");
        }

        return PayrollEntry::query()->with(['lines', 'run.period', 'employee.person'])
            ->whereHas('run', fn ($q) => $q->where('company_id', $companyId)->whereIn('status', self::FINAL))
            ->whereHas('run.period', fn ($q) => $q->where('year', (int) $m[1])->where('month', (int) $m[2]))
            ->orderBy('payroll_run_id')->orderBy('id')->get();
    }

    /** The financial year label of a date ("2026-27"). */
    public static function financialYearOf(Carbon|string $date): string
    {
        return FinancialYear::make()->label($date);
    }

    /** Arrear lines: an arrear adjustment, an attendance arrear or any line classified as arrear. */
    private static function isArrear(PayrollEntryLine $line): bool
    {
        $basis = (array) ($line->basis ?? []);

        return $line->classification === 'arrear' || ($basis['adjustment_type'] ?? null) === 'arrear' || data_get($basis, 'source.key') === 'attendance_arrear';
    }

    /** A line name without its trailing "(…)" detail (loan number, months of an arrear), for the row label. */
    private static function plainName(string $name): string
    {
        return trim((string) preg_replace('/\s*\([^)]*\)$/', '', $name)) ?: $name;
    }

    /**
     * How a component is set up ("How it is calculated"): configuration, never an amount.
     *
     * @return array<string, mixed>
     */
    private static function settings(SalaryComponent $component): array
    {
        return [
            'method' => (string) $component->calculation_method, 'formula' => $component->calculation_method === 'formula' ? $component->formula : null,
            'taxable' => (bool) $component->taxable, 'pf_applicable' => (bool) $component->pf_applicable, 'esi_applicable' => (bool) $component->esi_applicable,
            'proratable' => (bool) $component->is_proratable, 'arrear_eligible' => (bool) $component->is_arrear_eligible,
            'pay_in_joining_month' => $component->pay_in_joining_month === null ? null : (bool) $component->pay_in_joining_month,
            'pay_in_exit_month' => $component->pay_in_exit_month === null ? null : (bool) $component->pay_in_exit_month,
            'exempt_in_regimes' => array_values((array) ($component->exempt_in_regimes ?? [])),
        ];
    }

    /** Statutory lines by scheme and month, from what the line recorded when payroll calculated it. */
    private static function statutory(array &$out, PayrollEntryLine $line, array $basis, string $month, float $amount): void
    {
        $rule = ['rule' => $basis['rule'] ?? null, 'rule_id' => isset($basis['rule_id']) ? (int) $basis['rule_id'] : null, 'verification' => $basis['verification_status'] ?? null];
        switch ($line->code) {
            case 'PF_EE':
                $out['pf'][$month] = ($out['pf'][$month] ?? []) + $rule + ['wages' => (float) ($basis['wages'] ?? 0), 'base' => (float) ($basis['base'] ?? 0), 'rate' => isset($basis['rate']) ? (float) $basis['rate'] : null];
                $out['pf'][$month]['employee'] = ($out['pf'][$month]['employee'] ?? 0.0) + $amount;
                break;
            case 'PF_ER':
                $out['pf'][$month] = ($out['pf'][$month] ?? []) + $rule;
                $out['pf'][$month]['employer'] = ($out['pf'][$month]['employer'] ?? 0.0) + $amount;
                $out['pf'][$month]['eps'] = ($out['pf'][$month]['eps'] ?? 0.0) + (float) ($basis['eps'] ?? 0);
                $out['pf'][$month]['epf'] = ($out['pf'][$month]['epf'] ?? 0.0) + (float) ($basis['epf'] ?? 0);
                break;
            case 'VPF':
                $out['vpf'][$month] = ['wages' => (float) ($basis['wages'] ?? 0), 'mode' => $basis['mode'] ?? null, 'value' => isset($basis['value']) ? (float) $basis['value'] : null,
                    'amount' => ($out['vpf'][$month]['amount'] ?? 0.0) + $amount];
                break;
            case 'ESI_EE':
                $out['esi'][$month] = ($out['esi'][$month] ?? []) + $rule + ['wages' => (float) ($basis['wages'] ?? 0), 'eligibility_wages' => (float) ($basis['eligibility_wages'] ?? 0)];
                $out['esi'][$month]['employee'] = ($out['esi'][$month]['employee'] ?? 0.0) + $amount;
                break;
            case 'ESI_ER':
                $out['esi'][$month] = ($out['esi'][$month] ?? []) + $rule;
                $out['esi'][$month]['employer'] = ($out['esi'][$month]['employer'] ?? 0.0) + $amount;
                break;
            case 'PT':
                $out['pt'][$month] = $rule + ['wages' => (float) ($basis['gross'] ?? 0), 'state' => $basis['state'] ?? null, 'amount' => ($out['pt'][$month]['amount'] ?? 0.0) + $amount];
                break;
            case 'LWF_EE':
                $out['lwf'][$month] = ($out['lwf'][$month] ?? []) + $rule + ['state' => $basis['state'] ?? null];
                $out['lwf'][$month]['employee'] = ($out['lwf'][$month]['employee'] ?? 0.0) + $amount;
                break;
            case 'LWF_ER':
                $out['lwf'][$month] = ($out['lwf'][$month] ?? []) + $rule + ['state' => $basis['state'] ?? null];
                $out['lwf'][$month]['employer'] = ($out['lwf'][$month]['employer'] ?? 0.0) + $amount;
                break;
        }
    }

    /**
     * Each scheme's months, totals and (PF, ESI) the wage ceiling of the rule version the latest month used — only when
     * that version is verified.
     *
     * @return array<string, array<string, mixed>>
     */
    private function finishStatutory(array $statutory): array
    {
        $thresholds = app(VerifiedThresholds::class);
        $out = [];
        foreach ($statutory as $scheme => $months) {
            ksort($months);
            $sums = [];
            foreach ($months as $values) {
                foreach (['employee', 'employer', 'amount', 'eps', 'epf'] as $field) {
                    if (isset($values[$field])) {
                        $sums[$field] = round(($sums[$field] ?? 0.0) + (float) $values[$field], 2);
                    }
                }
            }
            $ceiling = null;
            if (in_array($scheme, ['pf', 'esi'], true) && $months !== []) {
                $lastMonth = array_key_last($months);
                $ceiling = $thresholds->recorded($months[$lastMonth]['rule_id'] ?? null, 'wage_ceiling', Carbon::parse($lastMonth.'-01')->endOfMonth());
            }
            $out[$scheme] = ['months' => array_map(fn (array $m) => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $m), $months), 'totals' => $sums, 'ceiling' => $ceiling];
        }

        return $out;
    }

    /** @return array{minimum_rate: float, maximum_rate: ?float, rule: string}|null the statutory bonus rates, only from a verified BONUS rule */
    private function bonusRule(Carbon $on): ?array
    {
        $rule = app(VerifiedThresholds::class)->rule('BONUS', 'minimum_rate', $on);

        return $rule === null ? null : ['minimum_rate' => (float) $rule->param('minimum_rate'), 'maximum_rate' => is_numeric($rule->param('maximum_rate')) ? (float) $rule->param('maximum_rate') : null, 'rule' => $rule->label()];
    }

    /** The state an entry was calculated for: its establishment's state, else its recorded PT state. */
    private static function stateOf(PayrollEntry $entry): ?string
    {
        $state = $entry->establishment_id ? Establishment::query()->whereKey($entry->establishment_id)->value('state') : null;

        return $state ?: (data_get($entry->inputs, 'statutory_context.pt_state') ?: null);
    }

    /** @return Collection<int, string> financial years with a finalized or paid entry for the employee, newest first */
    public static function financialYears(Employee $employee): Collection
    {
        $fy = FinancialYear::make();

        return PayrollEntry::query()->with('run.period')->where('employee_id', $employee->id)->whereHas('run', fn ($q) => $q->whereIn('status', self::FINAL))->get()->toBase()
            ->map(fn (PayrollEntry $e) => $e->run?->period ? $fy->label(Carbon::create($e->run->period->year, $e->run->period->month, 1)) : null)
            ->filter()->unique()->sortDesc()->values();
    }
}

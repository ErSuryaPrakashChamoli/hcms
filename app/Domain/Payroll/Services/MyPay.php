<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Compliance\Models\EmployeePfOption;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Compliance\Services\PfOptions;
use App\Domain\Compliance\Services\VerifiedThresholds;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Contracts\PaidPayReader;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\Payslip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * HROne parity MS-9 (My space → CTC, Payslip): the read model behind the employee's own pay pages — payslips by
 * financial year, the monthly tax slip from a payslip's tax snapshot, the statutory block and the wage-code verdict on
 * My compensation.
 *
 * Every figure comes from finalized or paid payroll (payslips are issued at finalization; entries are read only when
 * their run is finalized or paid) — never an open run and never a recomputation. Statutory thresholds come only from a
 * VERIFIED rule version (VerifiedThresholds); without one the verdict is "no_rule". Every method answers only for the
 * viewer's own employee record (an employee of another user, or another tenant, gets nothing). Nothing here writes.
 */
final class MyPay
{
    public function __construct(
        private readonly PaidPayReader $paid,
        private readonly VerifiedThresholds $thresholds,
        private readonly PfOptions $pfOptions,
        private readonly FinancialYear $years,
    ) {}

    /** The viewer's own employee record, in the viewer's tenant. */
    public static function isOwn(?User $viewer, ?Employee $employee): bool
    {
        return $viewer !== null && $employee !== null && $employee->user_id !== null && (int) $employee->user_id === (int) $viewer->getKey()
            && (int) $employee->tenant_id === (int) $viewer->tenant_id;
    }

    // ---- Payslips by financial year (HROne "Payslip") ----------------------------------------------------------------

    /** @return list<string> financial years with an own payslip, and the current one, newest first */
    public function payslipYears(User $viewer, Employee $employee): array
    {
        $years = $this->payslipQuery($viewer, $employee)->get(['id', 'snapshot'])->toBase()->map(fn (Payslip $p) => $this->yearOf($p))->filter();

        return $years->push($this->years->label(now()))->unique()->sortDesc()->values()->all();
    }

    /** @return Collection<int, Payslip> the viewer's own payslips of a financial year, by pay month */
    public function payslips(User $viewer, Employee $employee, string $financialYear): Collection
    {
        return $this->payslipQuery($viewer, $employee)->get()->filter(fn (Payslip $p) => $this->yearOf($p) === $financialYear)
            ->sortBy(fn (Payslip $p) => sprintf('%04d-%02d|%012d', (int) $p->get('period.year'), (int) $p->get('period.month'), (int) $p->id))->values();
    }

    /** One own payslip by id (null for anyone else's, or one of payroll that is not final). */
    public function payslip(User $viewer, Employee $employee, int $id): ?Payslip
    {
        return $this->payslipQuery($viewer, $employee)->whereKey($id)->first();
    }

    /**
     * The monthly income-tax slip, read from the payslip's tax snapshot (what payroll worked out that month) and its TDS
     * line. Null when payroll worked out no tax that month.
     *
     * @return list<array{heading: string, rows: list<array{label: string, value: mixed, money: bool}>}>|null
     */
    public static function taxSlip(Payslip $payslip): ?array
    {
        $tax = $payslip->get('tax');
        if (! is_array($tax) || $tax === []) {
            return null;
        }
        $tds = (float) collect((array) $payslip->get('deductions', []))->whereIn('code', ['TDS', 'TAX'])->sum('amount');
        $row = fn (string $label, mixed $value, bool $money = true) => $value === null ? null : ['label' => $label, 'value' => $money ? (float) $value : $value, 'money' => $money];
        $section = fn (string $heading, array $rows) => ($rows = array_values(array_filter($rows))) === [] ? null : ['heading' => $heading, 'rows' => $rows];
        $regime = match ($tax['regime'] ?? null) {
            'new' => 'New regime', 'old' => 'Old regime', null => null, default => ucfirst((string) $tax['regime']),
        };

        return array_values(array_filter([
            $section('Tax year', [
                $row('Financial year', $tax['financial_year'] ?? null, false), $row('Tax regime', $regime, false),
                $row('Salary paid on', isset($tax['payment_date']) ? Carbon::parse($tax['payment_date'])->format('j M Y') : null, false),
                $row('Months left in the year (including this one)', $tax['months_remaining'] ?? null, false),
            ]),
            $section('Income', [
                $row('Taxable salary paid earlier this year', $tax['ytd_taxable'] ?? null), $row('Taxable salary this month', $tax['this_month_taxable'] ?? null),
                $row('Perquisites', $tax['perquisites'] ?? null), $row('Taxable income outside this payroll', $tax['outside_payroll_taxable'] ?? null),
                $row('Employer NPS contribution', $tax['employer_nps'] ?? null), $row('Projected gross salary for the year', $tax['projected_gross'] ?? null),
                $row('Other income declared', $tax['other_income'] ?? null),
            ]),
            $section('Exemptions and deductions', [
                $row('House rent allowance exemption', $tax['hra_exempt'] ?? null), $row('Standard deduction', $tax['standard_deduction'] ?? null),
                $row('Loss from house property', $tax['house_property_loss'] ?? null), $row('Chapter VI-A deductions', $tax['chapter_via'] ?? null),
            ]),
            $section('Tax', [
                $row('Taxable income', $tax['taxable_income'] ?? null), $row('Tax on it', $tax['tax_before_rebate'] ?? null), $row('Rebate', $tax['rebate'] ?? null),
                $row('Surcharge', $tax['surcharge'] ?? null), $row('Health and education cess', $tax['cess'] ?? null), $row('Tax for the year', $tax['annual_tax'] ?? null),
            ]),
            $section('Tax deducted', [
                $row('Deducted earlier this year', $tax['already_deducted'] ?? null), $row('Deducted outside this payroll', $tax['outside_payroll_tds'] ?? null),
                $row('Deducted this month', $tds),
            ]),
            $section('Rule', [$row('Calculated under', $tax['rule'] ?? null, false), $row('Rule status', isset($tax['verification_status']) ? ucfirst((string) $tax['verification_status']) : null, false)]),
        ]));
    }

    // ---- My compensation: statutory block and wage-code verdict ------------------------------------------------------

    /**
     * Statutory options on the employee's pay (HROne "Statutory details"): the choices payroll applies (PF, ESI, PT
     * applicability, the PF wage basis and voluntary PF), the states and what was deducted in the latest finalized or
     * paid payroll, and the tax regime payroll used. Identifiers are never part of it (My profile shows them, masked).
     *
     * @return array{period: ?string, rows: list<array{scheme: string, applies: string, detail: ?string, latest: ?float}>, tax_regime: ?string}
     */
    public function statutory(User $viewer, Employee $employee): array
    {
        if (! self::isOwn($viewer, $employee)) {
            return ['period' => null, 'rows' => [], 'tax_regime' => null];
        }
        $detail = EmployeeStatutoryDetail::query()->where('employee_id', $employee->getKey())->first();
        $entry = PayrollEntry::query()->with(['lines', 'run.period'])->where('employee_id', $employee->getKey())
            ->whereHas('run', fn ($q) => $q->whereIn('status', PaidPay::FINAL))->latest('id')->first();
        $deducted = fn (array $codes) => $entry === null ? null : round((float) $entry->lines->whereIn('code', $codes)->sum('amount'), 2);
        $lineState = fn (string $code) => $entry === null ? null : ($entry->lines->firstWhere('code', $code)?->basis['state'] ?? null);
        $choice = fn (?bool $value) => match ($value) {
            true => 'Yes', false => 'No', null => 'As your establishment applies it',
        };

        $option = $this->pfOptions->for((int) $employee->getKey(), now());
        $pfDetail = array_values(array_filter([
            $option?->wage_basis ? (EmployeePfOption::WAGE_BASES[$option->wage_basis] ?? $option->wage_basis) : null,
            $option?->vpf_mode ? 'Voluntary PF: '.($option->vpf_mode === 'percent' ? rtrim(rtrim(number_format((float) $option->vpf_value, 2), '0'), '.').'% of PF wages' : number_format((float) $option->vpf_value, 2).' a month') : null,
        ]));
        $ptState = $lineState('PT') ?? $detail?->pt_state_code ?? data_get($entry?->inputs, 'statutory_context.pt_state');
        $lwfState = $lineState('LWF_EE') ?? data_get($entry?->inputs, 'statutory_context.lwf_state');
        $regime = data_get($entry?->inputs, 'tax.regime') ?? $detail?->tax_regime;

        return [
            'period' => $entry?->run?->period?->label(),
            'rows' => [
                ['scheme' => 'Provident fund', 'applies' => $choice($detail?->pf_applicable === null ? null : (bool) $detail->pf_applicable), 'detail' => $pfDetail === [] ? null : implode(' · ', $pfDetail), 'latest' => $deducted(['PF_EE', 'VPF'])],
                ['scheme' => 'ESI', 'applies' => $choice($detail?->esic_applicable === null ? null : (bool) $detail->esic_applicable), 'detail' => null, 'latest' => $deducted(['ESI_EE'])],
                ['scheme' => 'Professional tax', 'applies' => $choice($detail?->pt_applicable === null ? null : (bool) $detail->pt_applicable), 'detail' => $ptState ? 'State '.$ptState : null, 'latest' => $deducted(['PT'])],
                ['scheme' => 'Labour welfare fund', 'applies' => $lwfState ? 'Yes' : 'As your establishment applies it', 'detail' => $lwfState ? 'State '.$lwfState : null, 'latest' => $deducted(['LWF_EE'])],
            ],
            'tax_regime' => match ($regime) {
                'new' => 'New regime', 'old' => 'Old regime', null => null, default => ucfirst((string) $regime),
            },
        ];
    }

    /**
     * HROne "Salary insights": the Code on Wages verdict on the latest finalized or paid pay — wages (basic and DA) as a
     * share of the earnings, against the minimum share of the verified wage rule — and the minimum-wage check against the
     * verified minimum wage of the state the pay was calculated for. Each part is `ok`, `attention` or `no_rule` (no
     * verified rule: the check does not run; there is no fallback number). Null before the first finalized pay.
     *
     * @return array{period: string, wage_share: float, share: array{state: string, text: string, threshold: ?float, rule: ?string}, minimum: array{state: string, text: string, threshold: ?float, rule: ?string}}|null
     */
    public function wageCode(User $viewer, Employee $employee): ?array
    {
        if (! self::isOwn($viewer, $employee) || ($latest = $this->paid->latestPaid($employee)) === null) {
            return null;
        }
        $on = Carbon::parse($latest['period_end'] ?? now());
        [$shareCode, $shareParameter] = PayrollReadiness::RULES['wage_share'];
        [$minimumCode, $minimumParameter] = PayrollReadiness::RULES['minimum_wage'];

        $rule = $this->thresholds->value($shareCode, $shareParameter, $on);
        $share = match (true) {
            $rule === null => ['state' => 'no_rule', 'text' => 'No verified rule: the Code on Wages check does not run until a verified wage rule exists.', 'threshold' => null, 'rule' => null],
            $latest['wage_share'] >= $rule['value'] => ['state' => 'ok', 'text' => 'Compliant with the Code on Wages: wages are greater than the exclusions the rule allows.', 'threshold' => $rule['value'], 'rule' => $rule['rule']],
            default => ['state' => 'attention', 'text' => 'Exclusions are greater than the rule allows. Payroll may want to review the salary structure breakup.', 'threshold' => $rule['value'], 'rule' => $rule['rule']],
        };

        $state = $latest['state'] ?? null;
        $minimumRule = $state ? $this->thresholds->value($minimumCode, $minimumParameter, $on, $state) : null;
        $minimum = match (true) {
            $minimumRule === null => ['state' => 'no_rule', 'text' => 'No verified minimum wage rule'.($state ? ' for '.$state : '').': the minimum wage check does not run.', 'threshold' => null, 'rule' => null],
            ($latest['wages_full_month'] ?? $latest['wages']) >= $minimumRule['value'] => ['state' => 'ok', 'text' => 'Wages are at or above the minimum wage of '.$state.'.', 'threshold' => $minimumRule['value'], 'rule' => $minimumRule['rule']],
            default => ['state' => 'attention', 'text' => 'Wages are less than the minimum wage of '.$state.'.', 'threshold' => $minimumRule['value'], 'rule' => $minimumRule['rule']],
        };

        return ['period' => $latest['period'], 'wage_share' => (float) $latest['wage_share'], 'share' => $share, 'minimum' => $minimum];
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------

    private function payslipQuery(User $viewer, Employee $employee)
    {
        return Payslip::query()->where('employee_id', self::isOwn($viewer, $employee) ? $employee->getKey() : 0)
            ->whereHas('entry.run', fn ($q) => $q->whereIn('status', PaidPay::FINAL));
    }

    private function yearOf(Payslip $payslip): ?string
    {
        $year = (int) $payslip->get('period.year');
        $month = (int) $payslip->get('period.month');

        return $year > 0 && $month >= 1 && $month <= 12 ? $this->years->label(Carbon::create($year, $month, 1)) : null;
    }
}

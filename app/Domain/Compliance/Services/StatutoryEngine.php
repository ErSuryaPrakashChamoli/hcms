<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Support\StatutoryContext;
use App\Domain\Enterprise\Services\CountryPacks;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Services\PayrollComputation;
use Carbon\CarbonInterface;
use WeakMap;

/**
 * Protected statutory logic (§32, §101): EPF, ESI, professional tax, LWF and TDS. Parameters come
 * from versioned ComplianceRules; applicability from the company profile and the employee's
 * statutory detail. Tenants configure applicability, never the rules.
 */
final class StatutoryEngine
{
    /** @var WeakMap<PayrollComputation, StatutoryContext> one resolution per computation */
    private WeakMap $resolved;

    public function __construct(
        private readonly ComplianceRules $rules,
        private readonly TaxComputer $tax,
        private readonly StatutoryContexts $contexts,
        private readonly StatutoryOverrides $overrides,
        private readonly PfOptions $pfOptions,
    ) {
        $this->resolved = new WeakMap;
    }

    public function context(PayrollComputation $c): StatutoryContext
    {
        return $this->resolved[$c] ??= $this->contexts->for($c->employee, $c->period);
    }

    public function apply(PayrollComputation $c): void
    {
        // Phase 5: Employee → establishment (period end) → state → profiles. Recorded on the entry.
        $context = $this->context($c);
        $c->inputs['statutory_context'] = $context->toArray();
        $detail = $c->employee->statutoryDetail;
        $on = $c->period->end_date;
        $jurisdiction = $context->jurisdiction();

        if (app(CountryPacks::class)->statutoryEngine($jurisdiction) !== 'india') {
            $this->applyGeneric($c, $context, $jurisdiction);

            return;
        }

        // --- EPF -----------------------------------------------------------------------------
        if ($context->applies('EPF') && ($detail?->pf_applicable ?? true) && ($rule = $this->rule($c, 'EPF', $on))) {
            $wages = $c->pfWages();
            // HROne parity PY-15: the employee's own wage basis, when set, replaces the establishment's.
            $pfOption = $this->pfOptions->for($c->employee->id, $on);
            $base = $this->restrictPf($c, $context) ? min($wages, (float) $rule->param('wage_ceiling')) : $wages;
            $employee = $this->round($base * $rule->param('employee_rate'), $rule);
            $employer = $this->round($base * $rule->param('employer_rate'), $rule);
            $eps = $this->round(min($base, (float) $rule->param('eps_wage_ceiling')) * $rule->param('eps_rate'), $rule);
            $edliBase = min($wages, (float) $rule->param('edli_wage_ceiling'));
            $admin = max($this->round($edliBase * $rule->param('edli_rate'), $rule) + $this->round($base * $rule->param('admin_rate'), $rule), $base > 0 ? (float) $rule->param('admin_minimum', 0) : 0);

            if ($base > 0) {
                $basis = [...$this->ruleRef($rule), 'wages' => $wages, 'base' => $base, 'establishment_id' => $context->establishment?->getKey()];
                $c->addLine('PF_EE', 'Provident fund (employee)', 'deduction', $employee, ['classification' => 'pf_employee', 'basis' => $basis + ['rate' => $rule->param('employee_rate')], 'sort_order' => 500]);
                $c->addLine('PF_ER', 'Provident fund (employer)', 'employer_contribution', $employer, ['classification' => 'pf_employer', 'basis' => $basis + ['eps' => $eps, 'epf' => round($employer - $eps, 2)], 'sort_order' => 700]);
                $c->addLine('PF_ADMIN', 'PF admin & EDLI charges', 'employer_contribution', $admin, ['classification' => 'pf_employer', 'basis' => $basis, 'sort_order' => 701]);

                // Voluntary PF: the employee's own choice on top of the statutory share; it counts under 80C.
                $vpf = match ($pfOption?->vpf_mode) {
                    'percent' => round($wages * (float) $pfOption->vpf_value / 100, 2),
                    'amount' => (float) $pfOption->vpf_value,
                    default => 0.0,
                };
                if ($vpf > 0) {
                    $c->addLine('VPF', 'Voluntary provident fund', 'deduction', $vpf, ['classification' => 'other_deduction', 'basis' => ['pf_option_id' => $pfOption->id, 'mode' => $pfOption->vpf_mode, 'value' => (float) $pfOption->vpf_value, 'wages' => $wages], 'sort_order' => 501]);
                }
            }
        }

        // --- ESI -----------------------------------------------------------------------------
        if ($context->applies('ESI') && ($detail?->esic_applicable ?? true) && ($rule = $this->rule($c, 'ESI', $on))) {
            // HROne setup parity (PS-1): a component may count for the ESI ceiling check without being ESI wages, or the reverse.
            $fullMonth = collect($c->lines)->filter(fn ($l) => $l['type'] === 'earning' && ($l['esi_eligibility_wages'] ?? $l['esi_applicable']))->sum(fn ($l) => $l['basis']['full_month'] ?? $l['amount']);
            $wages = $c->esiWages();

            if ($fullMonth > 0 && $fullMonth <= (float) $rule->param('wage_ceiling')) {
                $basis = [...$this->ruleRef($rule), 'wages' => $wages, 'eligibility_wages' => round($fullMonth, 2), 'establishment_id' => $context->establishment?->getKey()];
                $c->addLine('ESI_EE', 'ESI (employee)', 'deduction', $this->round($wages * $rule->param('employee_rate'), $rule), ['classification' => 'esi_employee', 'basis' => $basis, 'sort_order' => 510]);
                $c->addLine('ESI_ER', 'ESI (employer)', 'employer_contribution', $this->round($wages * $rule->param('employer_rate'), $rule), ['classification' => 'esi_employer', 'basis' => $basis, 'sort_order' => 710]);
            }
        }

        // --- Professional tax ----------------------------------------------------------------
        $ptState = $context->ptState;

        if ($context->applies('PT') && ($detail?->pt_applicable ?? true) && ! $ptState) {
            $c->exception('statutory_state_missing', 'Professional tax applies but no state is known for the employee\'s establishment.', ComplianceRules::enforced());
        }

        if ($context->applies('PT') && ($detail?->pt_applicable ?? true) && $ptState && ($rule = $this->rule($c, 'PT', $on, $ptState))) {
            $gross = $c->ptWages(); // HROne setup parity (PS-1): PT wages (part of gross unless flagged otherwise)
            $amount = $this->slab($rule->param('slabs', []), $gross);
            $month = (int) $c->period->month;

            // A special-month amount (February ₹300 in Maharashtra and Karnataka, Madhya Pradesh's 12th month) replaces
            // the top band's amount only: Maharashtra's ₹175 band stays ₹175 in February (Mah. Act XIX of 2023).
            $topBand = $amount > 0 && self::inTopSlab($rule->param('slabs', []), $gross);
            if ($month === 2 && $rule->param('february_amount') !== null && $topBand) {
                $amount = (float) $rule->param('february_amount');
            }
            if ($month === 3 && $rule->param('march_amount') !== null && $topBand) {
                $amount = (float) $rule->param('march_amount');
            }
            if ($rule->param('female_exempt_upto') !== null && $c->employee->person?->gender === 'female' && $gross <= (float) $rule->param('female_exempt_upto')) {
                $amount = 0;
            }

            if ($amount > 0) {
                $c->addLine('PT', 'Professional tax', 'deduction', $amount, ['classification' => 'pt', 'basis' => [...$this->ruleRef($rule), 'state' => $ptState, 'state_source' => $context->ptStateSource, 'gross' => $gross, 'establishment_id' => $context->establishment?->getKey()], 'sort_order' => 520]);
            }
        }

        // --- Labour welfare fund -------------------------------------------------------------
        $lwfState = $context->lwfState;

        if ($context->applies('LWF') && $lwfState && ($rule = $this->rule($c, 'LWF', $on, $lwfState)) && in_array((int) $c->period->month, $rule->param('months', []), true) && $c->lwfWages() > 0) {
            $ceiling = $rule->param('wage_ceiling');

            if ($ceiling === null || $c->lwfWages() <= (float) $ceiling) {
                $basis = [...$this->ruleRef($rule), 'state' => $lwfState, 'establishment_id' => $context->establishment?->getKey()];
                // Flat amounts, or (Haryana from 2019) a rate of wages up to a cap with the employer paying a multiple.
                if ($rule->param('employee_rate') !== null) {
                    $employee = min(round($c->lwfWages() * (float) $rule->param('employee_rate'), 2), (float) $rule->param('employee_cap', PHP_FLOAT_MAX));
                    $employer = round($employee * (float) $rule->param('employer_multiplier', 1), 2);
                    $basis += ['gross' => $c->lwfWages(), 'rate' => $rule->param('employee_rate'), 'cap' => $rule->param('employee_cap')];
                } else {
                    $employee = (float) $rule->param('employee_amount');
                    $employer = (float) $rule->param('employer_amount');
                }
                $c->addLine('LWF_EE', 'Labour welfare fund (employee)', 'deduction', $employee, ['classification' => 'lwf_employee', 'basis' => $basis, 'sort_order' => 530]);
                $c->addLine('LWF_ER', 'Labour welfare fund (employer)', 'employer_contribution', $employer, ['classification' => 'lwf_employer', 'basis' => $basis, 'sort_order' => 720]);
            }
        }

        // HROne parity PY-14: audited overrides of the lines above, before TDS reads PF and PT.
        $this->overrides->apply($c, except: ['TDS']);

        // --- Income tax (TDS) ----------------------------------------------------------------
        // Phase 7: salary TDS follows the payment date, not the earning month.
        if ($context->applies('TDS') && ($rule = $this->rule($c, 'TDS', $c->period->paymentDate()))) {
            $result = $this->tax->monthlyTds($c, $rule);

            if ($result['pan_missing']) {
                $c->exception('no_pan', 'PAN not on file; tax deducted at the higher rate (s.206AA).');
            }

            if ($result['tds'] > 0) {
                $c->addLine('TDS', 'Income tax (TDS)', 'deduction', $result['tds'], ['classification' => 'tds', 'basis' => [...$this->ruleRef($rule), ...$result['basis']], 'sort_order' => 540]);
            }
            // Phase 7 §12: the tax basis carries its rule reference even when nothing is deducted.
            $c->inputs['tax'] = [...$this->ruleRef($rule), ...$result['basis']];
        }
        $this->overrides->apply($c, only: ['TDS']);
    }

    /**
     * Generic country engine (§96): social security `SS` (employee / employer rates on gross within a floor
     * and ceiling) when the profile has social security on, and income tax `TAX` (annual slabs on
     * gross × 12 less standard deduction, spread monthly) when the profile has tax on. Rules come from
     * database/data/compliance/<jurisdiction>.php.
     */
    private function applyGeneric(PayrollComputation $c, StatutoryContext $context, string $jurisdiction): void
    {
        $on = $c->period->end_date;
        $gross = $c->gross();

        // Release programme: PeopleOS holds no statutory rule for this jurisdiction (no verified, or even draft, source),
        // so its taxes and contributions cannot be computed. Under enforcement (always in production) the employee's pay is
        // blocked instead of finalised with no deductions, whatever the company's applicability switches say.
        if (! app(ComplianceRules::class)->covers($jurisdiction)) {
            $c->exception('statutory_jurisdiction_unsupported', "PeopleOS holds no statutory rules for {$jurisdiction}: its taxes and contributions are not calculated.", ComplianceRules::enforced());
        }

        if ($context->applies('EPF') && $gross > 0 && ($rule = $this->rule($c, 'SS', $on, null, $jurisdiction))) {
            $base = min(max($gross, (float) $rule->param('wage_floor', 0)), (float) ($rule->param('wage_ceiling') ?? $gross));
            $basis = [...$this->ruleRef($rule), 'jurisdiction' => $jurisdiction, 'base' => $base];
            $c->addLine('SS_EE', 'Social security (employee)', 'deduction', $this->round($base * (float) $rule->param('employee_rate', 0), $rule), ['classification' => 'other_deduction', 'basis' => $basis, 'sort_order' => 500]);
            $c->addLine('SS_ER', 'Social security (employer)', 'employer_contribution', $this->round($base * (float) $rule->param('employer_rate', 0), $rule), ['classification' => 'other', 'basis' => $basis, 'sort_order' => 700]);
        }

        if ($context->applies('TDS') && $gross > 0 && ($rule = $this->rule($c, 'TAX', $on, null, $jurisdiction))) {
            $annual = max(0, $c->taxableEarnings() * 12 - (float) $rule->param('standard_deduction', 0));
            $tax = $this->tax->slabTax($rule->param('slabs', []), $annual);
            $monthly = round($tax / 12, 2);
            if ($monthly > 0) {
                $c->addLine('TAX', 'Income tax', 'deduction', $monthly, ['classification' => 'tds', 'basis' => [...$this->ruleRef($rule), 'jurisdiction' => $jurisdiction, 'annual_taxable' => $annual, 'annual_tax' => $tax], 'sort_order' => 540]);
            }
        }
    }

    /** Employer PF on the full-month PF wages so far; used by CTC-balancing formulas (`pf_employer`). */
    public function estimateEmployerPf(PayrollComputation $c, float $pfWagesFullMonth): float
    {
        $context = $this->context($c);
        $detail = $c->employee->statutoryDetail;

        if (! $context->applies('EPF') || ! ($detail?->pf_applicable ?? true) || app(CountryPacks::class)->statutoryEngine($context->jurisdiction()) !== 'india') {
            return 0.0;
        }

        return $this->employerPfOn($pfWagesFullMonth, $c->period->end_date, $this->restrictPf($c, $context));
    }

    /** Whether PF stops at the wage ceiling: the employee's own wage basis (HROne parity PY-15), else the establishment's. */
    private function restrictPf(PayrollComputation $c, StatutoryContext $context): bool
    {
        return match ($this->pfOptions->for($c->employee->id, $c->period->end_date)?->wage_basis) {
            'ceiling' => true, 'actual' => false, default => $context->restrictPfToCeiling(),
        };
    }

    /** Employer PF on a month's PF wages under the EPF rule in force on a date (0 without one); the structure preview's `pf_employer`. */
    public function employerPfOn(float $pfWagesFullMonth, CarbonInterface|string $on, bool $restrictToCeiling): float
    {
        if (! ($rule = $this->rules->resolve('EPF', $on))) {
            return 0.0;
        }

        $base = $restrictToCeiling ? min($pfWagesFullMonth, (float) $rule->param('wage_ceiling')) : $pfWagesFullMonth;

        return $this->round($base * $rule->param('employer_rate'), $rule);
    }

    /**
     * Full-month PF and ESI for an employee covered by both, under the rules in force on a date, with
     * apply()'s ceilings and rounding: the formula guide's worked example. Null for a scheme without a rule.
     *
     * @return array{pf: ?array{wages: float, base: float, wage_ceiling: float, employee_rate: float, employer_rate: float, employee: float, employer: float}, esi: ?array{wages: float, wage_ceiling: float, employee_rate: float, covered: bool, employee: float}}
     */
    public function contributionsOn(float $pfWagesFullMonth, float $esiWagesFullMonth, CarbonInterface|string $on, bool $restrictToCeiling): array
    {
        $pf = null;
        if ($rule = $this->rules->resolve('EPF', $on)) {
            $base = $restrictToCeiling ? min($pfWagesFullMonth, (float) $rule->param('wage_ceiling')) : $pfWagesFullMonth;
            $pf = ['wages' => $pfWagesFullMonth, 'base' => $base, 'wage_ceiling' => (float) $rule->param('wage_ceiling'), 'employee_rate' => (float) $rule->param('employee_rate'),
                'employer_rate' => (float) $rule->param('employer_rate'), 'employee' => $this->round($base * $rule->param('employee_rate'), $rule), 'employer' => $this->round($base * $rule->param('employer_rate'), $rule)];
        }

        $esi = null;
        if ($rule = $this->rules->resolve('ESI', $on)) {
            $covered = $esiWagesFullMonth > 0 && $esiWagesFullMonth <= (float) $rule->param('wage_ceiling');
            $esi = ['wages' => $esiWagesFullMonth, 'wage_ceiling' => (float) $rule->param('wage_ceiling'), 'employee_rate' => (float) $rule->param('employee_rate'),
                'covered' => $covered, 'employee' => $covered ? $this->round($esiWagesFullMonth * $rule->param('employee_rate'), $rule) : 0.0];
        }

        return ['pf' => $pf, 'esi' => $esi];
    }

    /** @deprecated Phase 5: use context(); kept for callers of the Phase 4 API. */
    public function profile(PayrollComputation $c): CompanyStatutoryProfile
    {
        return CompanyStatutoryProfile::query()->where('company_id', $c->period->company_id)->first()
            ?? new CompanyStatutoryProfile(CompanyStatutoryProfile::defaults() + ['company_id' => $c->period->company_id]);
    }

    /** @param  array<int, array{0: float|null, 1: float}>  $slabs */
    /** Whether the value falls in the open-ended top slab ([null, amount]). */
    public static function inTopSlab(array $slabs, float $value): bool
    {
        foreach ($slabs as [$upper]) {
            if ($upper === null || $value <= $upper) {
                return $upper === null;
            }
        }

        return false;
    }

    public static function slab(array $slabs, float $value): float
    {
        foreach ($slabs as [$upper, $amount]) {
            if ($upper === null || $value <= $upper) {
                return (float) $amount;
            }
        }

        return 0.0;
    }

    private function round(float $amount, ComplianceRule $rule): float
    {
        return match ($rule->param('round', 'nearest')) {
            'ceil' => ceil($amount),
            'floor' => floor($amount),
            'none' => round($amount, 2),
            default => round($amount),
        };
    }

    /** Year-to-date taxable earnings and TDS from finalized entries in the same financial year, before the given period. */
    public static function yearToDate(int $employeeId, string $fyStart, string $paidBefore, ?int $exceptPeriodId = null): array
    {
        // Phase 7: payments made in the tax year before this payment (payment date, else period end).
        $entries = PayrollEntry::query()->with('lines')
            ->where('employee_id', $employeeId)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['finalized', 'paid']))
            ->whereHas('run.period', fn ($q) => $q->whereRaw('coalesce(payment_date, end_date) >= ?', [$fyStart])->whereRaw('coalesce(payment_date, end_date) < ?', [$paidBefore])
                ->when($exceptPeriodId, fn ($q, $id) => $q->whereKeyNot($id)))
            ->get();

        return [
            'taxable' => round($entries->sum(fn ($e) => (float) $e->taxable_earnings), 2),
            'tds' => round($entries->sum(fn ($e) => $e->amount('TDS')), 2),
            'pf_employee' => round($entries->sum(fn ($e) => $e->amount('PF_EE')), 2),
            'pt' => round($entries->sum(fn ($e) => $e->amount('PT')), 2),
            // HROne parity HP-3: voluntary PF and NPS count towards the employee's deductions.
            'vpf' => round($entries->sum(fn ($e) => $e->amount('VPF')), 2),
            'nps_employee' => round($entries->sum(fn ($e) => $e->lines->where('classification', 'nps_employee')->sum('amount')), 2),
            'nps_employer' => round($entries->sum(fn ($e) => $e->lines->where('classification', 'nps_employer')->sum('amount')), 2),
            'hra' => round($entries->sum(fn ($e) => $e->lines->where('classification', 'hra')->sum('amount')), 2),
            'basic' => round($entries->sum(fn ($e) => $e->lines->whereIn('classification', ['basic', 'da'])->sum('amount')), 2),
            'months' => $entries->count(),
        ];
    }

    public static function declarationFor(int $employeeId, string $financialYear): ?EmployeeTaxDeclaration
    {
        return EmployeeTaxDeclaration::query()->where('employee_id', $employeeId)->where('financial_year', $financialYear)->first();
    }

    /** Identifies the exact statutory rule version behind a line (Phase 4 §28, §59). @return array<string, mixed> */
    public function ruleRef(ComplianceRule $rule): array
    {
        return [
            'rule' => $rule->label(), 'rule_id' => $rule->id, 'rule_code' => $rule->code, 'rule_version' => $rule->version,
            'jurisdiction' => $rule->jurisdiction, 'state' => $rule->state, 'effective_from' => $rule->effective_from?->toDateString(),
            'verification_status' => $rule->verification_status ?? ComplianceRule::DRAFT, 'source' => $rule->source,
            'rule_checksum' => $rule->checksum, 'source_url' => $rule->source_url,
            // Phase 7 §12: enough to reproduce and explain the calculation.
            'effective_to' => $rule->effective_to?->toDateString(),
            'evidence_reference' => $rule->source_url ?? $rule->source,
        ];
    }

    /**
     * Phase 5 Part F: resolve a rule for an applicable statute. A missing rule is never skipped
     * silently, and an unverified one is flagged; both block finalization when enforcement is on.
     */
    private function rule(PayrollComputation $c, string $code, CarbonInterface $on, ?string $state = null, string $jurisdiction = 'IN'): ?ComplianceRule
    {
        $rule = $this->rules->resolve($code, $on, $state, $jurisdiction);
        $where = $jurisdiction.($state ? "/{$state}" : '');

        if ($rule === null) {
            $c->exception('statutory_rule_missing', "{$code} applies but no rule version for {$where} is effective on {$on->toDateString()}; nothing was deducted.", ComplianceRules::enforced());

            return null;
        }

        // Every rule consulted is recorded, even when it produces no line (e.g. an ESI ceiling test).
        $c->inputs['rules_consulted'][$rule->id] = $this->ruleRef($rule);

        if ($notice = $this->rules->pendingNotice($rule, $on)) {
            $c->exception('statutory_change_pending', "{$rule->label()} ({$where}) is affected by a regulatory change from {$notice->effective_date->toDateString()}: {$notice->title}.", ComplianceRules::enforced());
        }

        if (! $rule->isVerified()) {
            $c->exception('unverified_statutory_rule', "{$rule->label()} ({$where}) is {$rule->verification_status}, not verified against an official source.", ComplianceRules::enforced());
        }

        return $rule;
    }
}

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

    public function __construct(private readonly ComplianceRules $rules, private readonly TaxComputer $tax, private readonly StatutoryContexts $contexts)
    {
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
            $base = $context->restrictPfToCeiling() ? min($wages, (float) $rule->param('wage_ceiling')) : $wages;
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
            }
        }

        // --- ESI -----------------------------------------------------------------------------
        if ($context->applies('ESI') && ($detail?->esic_applicable ?? true) && ($rule = $this->rule($c, 'ESI', $on))) {
            $fullMonth = collect($c->lines)->filter(fn ($l) => $l['type'] === 'earning' && $l['esi_applicable'])->sum(fn ($l) => $l['basis']['full_month'] ?? $l['amount']);
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
            $gross = $c->gross();
            $amount = $this->slab($rule->param('slabs', []), $gross);
            $month = (int) $c->period->month;

            if ($month === 2 && $rule->param('february_amount') !== null && $amount > 0) {
                $amount = (float) $rule->param('february_amount');
            }
            if ($month === 3 && $rule->param('march_amount') !== null && $amount > 0) {
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

        if ($context->applies('LWF') && $lwfState && ($rule = $this->rule($c, 'LWF', $on, $lwfState)) && in_array((int) $c->period->month, $rule->param('months', []), true) && $c->gross() > 0) {
            $ceiling = $rule->param('wage_ceiling');

            if ($ceiling === null || $c->gross() <= (float) $ceiling) {
                $basis = [...$this->ruleRef($rule), 'state' => $lwfState, 'establishment_id' => $context->establishment?->getKey()];
                $c->addLine('LWF_EE', 'Labour welfare fund (employee)', 'deduction', (float) $rule->param('employee_amount'), ['classification' => 'lwf_employee', 'basis' => $basis, 'sort_order' => 530]);
                $c->addLine('LWF_ER', 'Labour welfare fund (employer)', 'employer_contribution', (float) $rule->param('employer_amount'), ['classification' => 'lwf_employer', 'basis' => $basis, 'sort_order' => 720]);
            }
        }

        // --- Income tax (TDS) ----------------------------------------------------------------
        if ($context->applies('TDS') && ($rule = $this->rule($c, 'TDS', $on))) {
            $result = $this->tax->monthlyTds($c, $rule);

            if ($result['pan_missing']) {
                $c->exception('no_pan', 'PAN not on file; tax deducted at the higher rate (s.206AA).');
            }

            if ($result['tds'] > 0) {
                $c->addLine('TDS', 'Income tax (TDS)', 'deduction', $result['tds'], ['classification' => 'tds', 'basis' => [...$this->ruleRef($rule), ...$result['basis']], 'sort_order' => 540]);
            }
            $c->inputs['tax'] = $result['basis'];
        }
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

        if (! $context->applies('EPF') || ! ($detail?->pf_applicable ?? true) || app(CountryPacks::class)->statutoryEngine($context->jurisdiction()) !== 'india' || ! ($rule = $this->rules->resolve('EPF', $c->period->end_date))) {
            return 0.0;
        }

        $base = $context->restrictPfToCeiling() ? min($pfWagesFullMonth, (float) $rule->param('wage_ceiling')) : $pfWagesFullMonth;

        return $this->round($base * $rule->param('employer_rate'), $rule);
    }

    /** @deprecated Phase 5: use context(); kept for callers of the Phase 4 API. */
    public function profile(PayrollComputation $c): CompanyStatutoryProfile
    {
        return CompanyStatutoryProfile::query()->where('company_id', $c->period->company_id)->first()
            ?? new CompanyStatutoryProfile(CompanyStatutoryProfile::defaults() + ['company_id' => $c->period->company_id]);
    }

    /** @param  array<int, array{0: float|null, 1: float}>  $slabs */
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
    public static function yearToDate(int $employeeId, string $fyStart, string $periodStart): array
    {
        $entries = PayrollEntry::query()->with('lines')
            ->where('employee_id', $employeeId)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['finalized', 'paid']))
            ->whereHas('run.period', fn ($q) => $q->where('start_date', '>=', $fyStart)->where('start_date', '<', $periodStart))
            ->get();

        return [
            'taxable' => round($entries->sum(fn ($e) => (float) $e->taxable_earnings), 2),
            'tds' => round($entries->sum(fn ($e) => $e->amount('TDS')), 2),
            'pf_employee' => round($entries->sum(fn ($e) => $e->amount('PF_EE')), 2),
            'pt' => round($entries->sum(fn ($e) => $e->amount('PT')), 2),
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

        if (! $rule->isVerified()) {
            $c->exception('unverified_statutory_rule', "{$rule->label()} ({$where}) is {$rule->verification_status}, not verified against an official source.", ComplianceRules::enforced());
        }

        return $rule;
    }
}

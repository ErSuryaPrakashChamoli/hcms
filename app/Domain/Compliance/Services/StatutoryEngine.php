<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Enterprise\Services\CountryPacks;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Services\PayrollComputation;

/**
 * Protected statutory logic (§32, §101): EPF, ESI, professional tax, LWF and TDS. Parameters come
 * from versioned ComplianceRules; applicability from the company profile and the employee's
 * statutory detail. Tenants configure applicability, never the rules.
 */
final class StatutoryEngine
{
    public function __construct(private readonly ComplianceRules $rules, private readonly TaxComputer $tax) {}

    public function apply(PayrollComputation $c): void
    {
        $profile = $this->profile($c);
        $detail = $c->employee->statutoryDetail;
        $on = $c->period->end_date;
        $jurisdiction = strtoupper($profile->jurisdiction ?: 'IN');

        if (app(CountryPacks::class)->statutoryEngine($jurisdiction) !== 'india') {
            $this->applyGeneric($c, $profile, $jurisdiction);

            return;
        }

        // --- EPF -----------------------------------------------------------------------------
        if ($profile->pf_applicable && ($detail?->pf_applicable ?? true) && ($rule = $this->rules->resolve('EPF', $on))) {
            $wages = $c->pfWages();
            $base = $profile->pf_restrict_to_ceiling ? min($wages, (float) $rule->param('wage_ceiling')) : $wages;
            $employee = $this->round($base * $rule->param('employee_rate'), $rule);
            $employer = $this->round($base * $rule->param('employer_rate'), $rule);
            $eps = $this->round(min($base, (float) $rule->param('eps_wage_ceiling')) * $rule->param('eps_rate'), $rule);
            $edliBase = min($wages, (float) $rule->param('edli_wage_ceiling'));
            $admin = max($this->round($edliBase * $rule->param('edli_rate'), $rule) + $this->round($base * $rule->param('admin_rate'), $rule), $base > 0 ? (float) $rule->param('admin_minimum', 0) : 0);

            if ($base > 0) {
                $basis = ['rule' => $rule->label(), 'wages' => $wages, 'base' => $base];
                $c->addLine('PF_EE', 'Provident fund (employee)', 'deduction', $employee, ['classification' => 'pf_employee', 'basis' => $basis + ['rate' => $rule->param('employee_rate')], 'sort_order' => 500]);
                $c->addLine('PF_ER', 'Provident fund (employer)', 'employer_contribution', $employer, ['classification' => 'pf_employer', 'basis' => $basis + ['eps' => $eps, 'epf' => round($employer - $eps, 2)], 'sort_order' => 700]);
                $c->addLine('PF_ADMIN', 'PF admin & EDLI charges', 'employer_contribution', $admin, ['classification' => 'pf_employer', 'basis' => $basis, 'sort_order' => 701]);
            }
        }

        // --- ESI -----------------------------------------------------------------------------
        if ($profile->esi_applicable && ($detail?->esic_applicable ?? true) && ($rule = $this->rules->resolve('ESI', $on))) {
            $fullMonth = collect($c->lines)->filter(fn ($l) => $l['type'] === 'earning' && $l['esi_applicable'])->sum(fn ($l) => $l['basis']['full_month'] ?? $l['amount']);
            $wages = $c->esiWages();

            if ($fullMonth > 0 && $fullMonth <= (float) $rule->param('wage_ceiling')) {
                $basis = ['rule' => $rule->label(), 'wages' => $wages, 'eligibility_wages' => round($fullMonth, 2)];
                $c->addLine('ESI_EE', 'ESI (employee)', 'deduction', $this->round($wages * $rule->param('employee_rate'), $rule), ['classification' => 'esi_employee', 'basis' => $basis, 'sort_order' => 510]);
                $c->addLine('ESI_ER', 'ESI (employer)', 'employer_contribution', $this->round($wages * $rule->param('employer_rate'), $rule), ['classification' => 'esi_employer', 'basis' => $basis, 'sort_order' => 710]);
            }
        }

        // --- Professional tax ----------------------------------------------------------------
        $ptState = $detail?->pt_state_code ?: $profile->pt_state;

        if ($profile->pt_applicable && ($detail?->pt_applicable ?? true) && $ptState && ($rule = $this->rules->resolve('PT', $on, $ptState))) {
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
                $c->addLine('PT', 'Professional tax', 'deduction', $amount, ['classification' => 'pt', 'basis' => ['rule' => $rule->label(), 'state' => $ptState, 'gross' => $gross], 'sort_order' => 520]);
            }
        }

        // --- Labour welfare fund -------------------------------------------------------------
        $lwfState = $profile->lwf_state ?: $ptState;

        if ($profile->lwf_applicable && $lwfState && ($rule = $this->rules->resolve('LWF', $on, $lwfState)) && in_array((int) $c->period->month, $rule->param('months', []), true) && $c->gross() > 0) {
            $ceiling = $rule->param('wage_ceiling');

            if ($ceiling === null || $c->gross() <= (float) $ceiling) {
                $basis = ['rule' => $rule->label(), 'state' => $lwfState];
                $c->addLine('LWF_EE', 'Labour welfare fund (employee)', 'deduction', (float) $rule->param('employee_amount'), ['classification' => 'lwf_employee', 'basis' => $basis, 'sort_order' => 530]);
                $c->addLine('LWF_ER', 'Labour welfare fund (employer)', 'employer_contribution', (float) $rule->param('employer_amount'), ['classification' => 'lwf_employer', 'basis' => $basis, 'sort_order' => 720]);
            }
        }

        // --- Income tax (TDS) ----------------------------------------------------------------
        if ($profile->tds_applicable && ($rule = $this->rules->resolve('TDS', $on))) {
            $result = $this->tax->monthlyTds($c, $rule);

            if ($result['pan_missing']) {
                $c->exception('no_pan', 'PAN not on file; tax deducted at the higher rate (s.206AA).');
            }

            if ($result['tds'] > 0) {
                $c->addLine('TDS', 'Income tax (TDS)', 'deduction', $result['tds'], ['classification' => 'tds', 'basis' => $result['basis'], 'sort_order' => 540]);
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
    private function applyGeneric(PayrollComputation $c, CompanyStatutoryProfile $profile, string $jurisdiction): void
    {
        $on = $c->period->end_date;
        $gross = $c->gross();

        if ($profile->pf_applicable && ($rule = $this->rules->resolve('SS', $on, null, $jurisdiction)) && $gross > 0) {
            $base = min(max($gross, (float) $rule->param('wage_floor', 0)), (float) ($rule->param('wage_ceiling') ?? $gross));
            $basis = ['rule' => $rule->label(), 'jurisdiction' => $jurisdiction, 'base' => $base];
            $c->addLine('SS_EE', 'Social security (employee)', 'deduction', $this->round($base * (float) $rule->param('employee_rate', 0), $rule), ['classification' => 'other_deduction', 'basis' => $basis, 'sort_order' => 500]);
            $c->addLine('SS_ER', 'Social security (employer)', 'employer_contribution', $this->round($base * (float) $rule->param('employer_rate', 0), $rule), ['classification' => 'other', 'basis' => $basis, 'sort_order' => 700]);
        }

        if ($profile->tds_applicable && ($rule = $this->rules->resolve('TAX', $on, null, $jurisdiction)) && $gross > 0) {
            $annual = max(0, $c->taxableEarnings() * 12 - (float) $rule->param('standard_deduction', 0));
            $tax = $this->tax->slabTax($rule->param('slabs', []), $annual);
            $monthly = round($tax / 12, 2);
            if ($monthly > 0) {
                $c->addLine('TAX', 'Income tax', 'deduction', $monthly, ['classification' => 'tds', 'basis' => ['rule' => $rule->label(), 'jurisdiction' => $jurisdiction, 'annual_taxable' => $annual, 'annual_tax' => $tax], 'sort_order' => 540]);
            }
        }
    }

    /** Employer PF on the full-month PF wages so far; used by CTC-balancing formulas (`pf_employer`). */
    public function estimateEmployerPf(PayrollComputation $c, float $pfWagesFullMonth): float
    {
        $profile = $this->profile($c);
        $detail = $c->employee->statutoryDetail;

        if (! $profile->pf_applicable || ! ($detail?->pf_applicable ?? true) || app(CountryPacks::class)->statutoryEngine(strtoupper($profile->jurisdiction ?: 'IN')) !== 'india' || ! ($rule = $this->rules->resolve('EPF', $c->period->end_date))) {
            return 0.0;
        }

        $base = $profile->pf_restrict_to_ceiling ? min($pfWagesFullMonth, (float) $rule->param('wage_ceiling')) : $pfWagesFullMonth;

        return $this->round($base * $rule->param('employer_rate'), $rule);
    }

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
}

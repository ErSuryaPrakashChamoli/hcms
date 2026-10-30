<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\PayrollEntry;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity (PS-2): the tax estimator on the investment declaration. A rough comparison of the year's tax under
 * each regime from the latest finalized monthly taxable salary (× 12) and the declared amounts, with the TDS rule in
 * force: standard deduction, the deductions the regime allows within their limits, slabs, rebate, cess. HRA exemption,
 * surcharge and previous employment are left out; payroll's monthly TDS remains the actual figure.
 */
final class TaxEstimator
{
    public function __construct(private readonly ComplianceRules $rules, private readonly TaxComputer $tax, private readonly FinancialYear $fy) {}

    /** @param  array<string, float|int>  $declared  section => amount @return array<string, array{taxable: float, tax: float}> regime => estimate */
    public function compare(Employee $employee, string $financialYear, array $declared): array
    {
        $start = Carbon::create((int) substr($financialYear, 0, 4), (int) config('peopleos.compliance.financial_year_start_month', 4), 1);
        $rule = $this->rules->resolve('TDS', $start);
        if ($rule === null) {
            return [];
        }
        $monthly = (float) (PayrollEntry::query()->where('employee_id', $employee->id)->whereHas('run', fn ($q) => $q->whereIn('status', ['finalized', 'paid']))->latest('id')->value('taxable_earnings') ?? 0);
        $annual = $monthly > 0 ? $monthly * 12
            : (float) (app(CompensationOutput::class)->on($employee)?->ctcAnnual ?? 0);
        $out = [];
        foreach (array_keys((array) config('peopleos.compliance.tax_regimes')) as $regime) {
            $params = $rule->param($regime) ?? [];
            $income = max(0, $annual - min((float) ($params['standard_deduction'] ?? 0), $annual));
            $limits = $params['limits'] ?? [];
            $deductions = 0.0;
            foreach ($params['allow_chapter_via'] ?? [] as $section) {
                $amount = (float) ($declared[$section] ?? 0);
                $deductions += isset($limits[$section]) ? min($amount, (float) $limits[$section]) : $amount;
            }
            $taxable = max(0, floor(($income + (float) ($declared['OTHER_INCOME'] ?? 0) - $deductions) / 10) * 10);
            $slab = $this->tax->slabTax($params['slabs'] ?? [], $taxable);
            $rebate = $taxable <= ($params['rebate_income_limit'] ?? 0) ? min($slab, (float) ($params['rebate_max'] ?? 0)) : 0.0;
            $tax = max(0, $slab - $rebate);
            $out[$regime] = ['taxable' => $taxable, 'tax' => round($tax * (1 + (float) $rule->param('cess_rate', 0)))];
        }

        return $out;
    }
}

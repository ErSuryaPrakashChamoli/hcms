<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Payroll\Services\PayrollComputation;

/**
 * Section 192 TDS by annual projection: (YTD actuals + this month + projected remaining months)
 * → deductions per regime → slab tax → rebate → surcharge → cess; the balance is spread over the
 * remaining months of the financial year.
 */
final class TaxComputer
{
    public function __construct(private readonly FinancialYear $fy) {}

    /** @return array{tds: float, pan_missing: bool, basis: array<string, mixed>} */
    public function monthlyTds(PayrollComputation $c, ComplianceRule $rule): array
    {
        $periodStart = $c->period->start_date;
        $fyLabel = $this->fy->label($periodStart);
        $declaration = StatutoryEngine::declarationFor($c->employee->id, $fyLabel);
        $regime = $declaration?->regime ?? 'new';
        $params = $rule->param($regime) ?? $rule->param('new');
        $ytd = StatutoryEngine::yearToDate($c->employee->id, $this->fy->start($periodStart)->toDateString(), $periodStart->toDateString());
        $remaining = $this->fy->monthsRemainingIncluding($periodStart); // including this month

        $thisMonth = $c->taxableEarnings();
        $projected = round($ytd['taxable'] + $thisMonth * $remaining, 2);
        $previousEmployer = (float) ($declaration?->previous_employer_income ?? 0);
        $grossSalary = $projected + $previousEmployer;

        // HRA exemption (old regime only, s.10(13A)).
        $hraExempt = 0.0;
        if (($params['hra_exempt'] ?? false) && $declaration && $declaration->amount('HRA_RENT') > 0) {
            $hraAnnual = $ytd['hra'] + collect($c->lines)->where('classification', 'hra')->sum('amount') * $remaining;
            $basicAnnual = $ytd['basic'] + collect($c->lines)->whereIn('classification', ['basic', 'da'])->sum('amount') * $remaining;
            $rent = $declaration->amount('HRA_RENT');
            $metro = $declaration->amount('METRO') > 0;
            $hraExempt = max(0, min($hraAnnual, $rent - 0.10 * $basicAnnual, ($metro ? 0.50 : 0.40) * $basicAnnual));
        }

        $standard = min((float) ($params['standard_deduction'] ?? 0), max(0, $grossSalary - $hraExempt));
        $ptAnnual = $ytd['pt'] + $c->amount('PT') * $remaining;
        $incomeFromSalary = max(0, $grossSalary - $hraExempt - $standard - ($regime === 'old' ? $ptAnnual : 0));

        // Chapter VI-A: only sections the regime allows, capped by limits; PF employee share counts under 80C.
        $chapterVia = 0.0;
        $allowed = $params['allow_chapter_via'] ?? [];
        $limits = $params['limits'] ?? [];
        $declared = $declaration?->declarations ?? [];
        if (in_array('80C', $allowed, true)) {
            $declared['80C'] = ($declared['80C'] ?? 0) + $ytd['pf_employee'] + $c->amount('PF_EE') * $remaining;
        }
        foreach ($allowed as $section) {
            $amount = (float) ($declared[$section] ?? 0);
            $chapterVia += isset($limits[$section]) ? min($amount, (float) $limits[$section]) : $amount;
        }

        $taxable = max(0, floor(($incomeFromSalary - $chapterVia) / 10) * 10);
        $tax = $this->slabTax($params['slabs'], $taxable);

        // Rebate u/s 87A (+ marginal relief in the new regime).
        $rebate = 0.0;
        if ($taxable <= ($params['rebate_income_limit'] ?? 0)) {
            $rebate = min($tax, (float) ($params['rebate_max'] ?? 0));
        } elseif (($params['marginal_relief'] ?? false)) {
            $excess = $taxable - $params['rebate_income_limit'];
            if ($tax > $excess) {
                $rebate = $tax - $excess;
            }
        }
        $tax = max(0, $tax - $rebate);

        $surchargeRate = StatutoryEngine::slab($rule->param('surcharge', []), $taxable);
        if ($regime === 'new') {
            $surchargeRate = min($surchargeRate, (float) $rule->param('surcharge_new_regime_cap', $surchargeRate));
        }
        $surcharge = round($tax * $surchargeRate, 2);
        $cess = round(($tax + $surcharge) * (float) $rule->param('cess_rate', 0), 2);
        $annualTax = round($tax + $surcharge + $cess);

        $panMissing = blank($c->employee->statutoryDetail?->pan);
        if ($panMissing && $taxable > 0) {
            $annualTax = max($annualTax, round($taxable * 0.20)); // s.206AA
        }

        $alreadyDeducted = $ytd['tds'] + (float) ($declaration?->previous_employer_tds ?? 0);
        $balance = max(0, $annualTax - $alreadyDeducted);
        $tds = $remaining > 0 ? round($balance / $remaining) : $balance;

        return [
            'tds' => (float) $tds,
            'pan_missing' => $panMissing && $taxable > 0,
            'basis' => [
                'rule' => $rule->label(), 'financial_year' => $fyLabel, 'regime' => $regime, 'months_remaining' => $remaining,
                'ytd_taxable' => $ytd['taxable'], 'this_month_taxable' => $thisMonth, 'projected_gross' => $grossSalary,
                'hra_exempt' => round($hraExempt, 2), 'standard_deduction' => $standard, 'chapter_via' => round($chapterVia, 2),
                'taxable_income' => $taxable, 'tax_before_rebate' => round($tax + $rebate, 2), 'rebate' => round($rebate, 2), 'surcharge' => $surcharge, 'cess' => $cess,
                'annual_tax' => $annualTax, 'already_deducted' => $alreadyDeducted,
            ],
        ];
    }

    /** @param  array<int, array{0: float|null, 1: float}>  $slabs */
    public function slabTax(array $slabs, float $income): float
    {
        $tax = 0.0;
        $lower = 0.0;

        foreach ($slabs as [$upper, $rate]) {
            if ($income <= $lower) {
                break;
            }
            $portion = ($upper === null ? $income : min($income, $upper)) - $lower;
            $tax += $portion * $rate;
            $lower = $upper ?? $income;
        }

        return round($tax, 2);
    }
}

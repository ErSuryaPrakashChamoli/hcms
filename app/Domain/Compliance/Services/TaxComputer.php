<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Payroll\Services\PayrollComputation;

/**
 * Salary TDS by annual projection: (YTD actuals + this month + projected remaining months)
 * → deductions per regime → slab tax → rebate → surcharge → cess; the balance is spread over the
 * remaining months of the year. Phase 7: the tax year, the year-to-date and the months remaining
 * follow the salary PAYMENT date (s.192 of the 1961 Act up to 31 Mar 2026; s.392(1) of the
 * Income-tax Act, 2025 from 1 Apr 2026, with the computation reset for the new tax year).
 *
 * HROne parity HP-3 adds, each only when present: verified proof amounts after the organisation's proof date
 * (TaxDeclarationAmounts), perquisites and income paid outside payroll with the tax / PF / PT deducted on them
 * (TaxIncomeEntries), the previous employer's PF and PT, voluntary PF and NPS lines, other income the employee
 * declares, and home-loan interest (24(b)) when the rule in force carries its limits. Statutory values still come
 * only from the rule: a head the rule has no parameter for is recorded on the basis and not counted.
 */
final class TaxComputer
{
    public function __construct(
        private readonly FinancialYear $fy,
        private readonly TaxDeclarationAmounts $amounts,
        private readonly TaxIncomeEntries $incomeEntries,
    ) {}

    /** @return array{tds: float, pan_missing: bool, basis: array<string, mixed>} */
    public function monthlyTds(PayrollComputation $c, ComplianceRule $rule): array
    {
        $paid = $c->period->paymentDate();
        $fyLabel = $this->fy->label($paid);
        $declaration = StatutoryEngine::declarationFor($c->employee->id, $fyLabel);
        $regime = $declaration?->regime ?? 'new';
        $params = $rule->param($regime) ?? $rule->param('new');
        $ytd = StatutoryEngine::yearToDate($c->employee->id, $this->fy->start($paid)->toDateString(), $paid->toDateString(), $c->period->getKey());
        $remaining = $this->fy->monthsRemainingIncluding($paid); // including this month

        // HROne setup parity (PS-1): a non-taxable component exempt only in some regimes is taxable in the others.
        $thisMonth = $c->taxableEarnings() + $c->sum(fn ($l) => $l['type'] === 'earning' && ! $l['taxable'] && isset($l['exempt_in_regimes']) && $l['exempt_in_regimes'] !== [] && ! in_array($regime, $l['exempt_in_regimes'], true));
        $projected = round($ytd['taxable'] + $thisMonth * $remaining, 2);
        $previousEmployer = (float) ($declaration?->previous_employer_income ?? 0);
        $grossSalary = $projected + $previousEmployer;

        // HP-3: declared or (after the proof date) verified amounts; income outside this payroll; NPS.
        ['amounts' => $declared, 'basis' => $amountsBasis] = $this->amounts->for($declaration, $paid);
        $outside = $this->incomeEntries->totals($c->employee->id, $fyLabel);
        $classified = fn (string $classification) => (float) collect($c->lines)->where('classification', $classification)->sum('amount');
        $npsEmployer = round(($ytd['nps_employer'] ?? 0) + $classified('nps_employer') * $remaining, 2);
        $grossSalary += $outside['perquisites'] + $outside['outside_taxable'] + $npsEmployer;
        if ($npsEmployer > 0) {
            $declared['80CCD2'] = (float) ($declared['80CCD2'] ?? 0) + $npsEmployer; // s.17(1)(viii) income, 80CCD(2) deduction
        }

        // HRA exemption (old regime only, s.10(13A)).
        $hraExempt = 0.0;
        $rent = (float) ($declared['HRA_RENT'] ?? 0);
        if (($params['hra_exempt'] ?? false) && $declaration && $rent > 0) {
            $hraAnnual = $ytd['hra'] + collect($c->lines)->where('classification', 'hra')->sum('amount') * $remaining;
            $basicAnnual = $ytd['basic'] + collect($c->lines)->whereIn('classification', ['basic', 'da'])->sum('amount') * $remaining;
            $metro = (float) ($declared['METRO'] ?? 0) > 0;
            $hraExempt = max(0, min($hraAnnual, $rent - 0.10 * $basicAnnual, ($metro ? 0.50 : 0.40) * $basicAnnual));
        }

        $standard = min((float) ($params['standard_deduction'] ?? 0), max(0, $grossSalary - $hraExempt));
        $ptAnnual = $ytd['pt'] + $c->amount('PT') * $remaining + $outside['outside_pt'] + (float) ($declaration?->previousEmployer('pt') ?? 0);
        $incomeFromSalary = max(0, $grossSalary - $hraExempt - $standard - ($regime === 'old' ? $ptAnnual : 0));

        // Other heads the employee declares (s.192(2B)): other income, and home-loan interest only with the rule's limits.
        $otherIncome = (float) ($declared['OTHER_INCOME'] ?? 0);
        $housingInterest = (float) ($declared['24B'] ?? 0);
        $housePropertyLoss = 0.0;
        if ($housingInterest > 0 && isset($params['house_property_interest_limit'])) {
            $housePropertyLoss = min($housingInterest, (float) $params['house_property_interest_limit'], (float) ($params['house_property_loss_setoff_limit'] ?? PHP_FLOAT_MAX));
        }

        // Chapter VI-A: only sections the regime allows, capped by limits; PF employee share counts under 80C.
        $chapterVia = 0.0;
        $allowed = $params['allow_chapter_via'] ?? [];
        $limits = $params['limits'] ?? [];
        if (in_array('80C', $allowed, true)) {
            $declared['80C'] = ($declared['80C'] ?? 0) + $ytd['pf_employee'] + $c->amount('PF_EE') * $remaining
                + ($ytd['vpf'] ?? 0) + $c->amount('VPF') * $remaining + ($ytd['nps_employee'] ?? 0) + $classified('nps_employee') * $remaining
                + $outside['outside_pf'] + (float) ($declaration?->previousEmployer('pf') ?? 0);
        }
        foreach ($allowed as $section) {
            $amount = (float) ($declared[$section] ?? 0);
            $chapterVia += isset($limits[$section]) ? min($amount, (float) $limits[$section]) : $amount;
        }

        $taxable = max(0, floor(($incomeFromSalary + $otherIncome - $housePropertyLoss - $chapterVia) / 10) * 10);
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

        $alreadyDeducted = $ytd['tds'] + (float) ($declaration?->previous_employer_tds ?? 0) + $outside['outside_tds'];
        $balance = max(0, $annualTax - $alreadyDeducted);
        $tds = $remaining > 0 ? round($balance / $remaining) : $balance;

        return [
            'tds' => (float) $tds,
            'pan_missing' => $panMissing && $taxable > 0,
            'basis' => [
                'rule' => $rule->label(), 'financial_year' => $fyLabel, 'payment_date' => $paid->toDateString(), 'legal_basis' => $rule->param('legal_basis'), 'regime' => $regime, 'months_remaining' => $remaining,
                'ytd_taxable' => $ytd['taxable'], 'this_month_taxable' => $thisMonth, 'projected_gross' => $grossSalary,
                'hra_exempt' => round($hraExempt, 2), 'standard_deduction' => $standard, 'chapter_via' => round($chapterVia, 2),
                'taxable_income' => $taxable, 'tax_before_rebate' => round($tax + $rebate, 2), 'rebate' => round($rebate, 2), 'surcharge' => $surcharge, 'cess' => $cess,
                'annual_tax' => $annualTax, 'already_deducted' => $alreadyDeducted,
            ] + array_filter([
                // HP-3 heads appear only when they apply, so earlier bases stay as they were.
                'amounts_basis' => $amountsBasis === 'declared' ? null : $amountsBasis,
                'perquisites' => $outside['perquisites'] ?: null,
                'outside_payroll_taxable' => $outside['outside_taxable'] ?: null,
                'outside_payroll_tds' => $outside['outside_tds'] ?: null,
                'employer_nps' => $npsEmployer ?: null,
                'other_income' => $otherIncome ?: null,
                'house_property_loss' => $housePropertyLoss ?: null,
                'house_property_not_counted' => $housingInterest > 0 && ! isset($params['house_property_interest_limit']) ? $housingInterest : null,
            ], fn ($v) => $v !== null),
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

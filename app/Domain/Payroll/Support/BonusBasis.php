<?php

namespace App\Domain\Payroll\Support;

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\PayrollEntry;

/**
 * The statutory bonus basis of an employee for an accounting year (Code on Wages, 2019, Chapter IV; Payment of Bonus Act,
 * 1965 before it), from finalized payroll entries: days paid, the wages earned (the components classified basic and DA),
 * the wages for bonus after the calculation ceiling, and the eligibility determination with its reason. Every statutory
 * figure (eligibility ceiling, calculation ceiling and its rule, minimum and maximum rate, minimum amount, minimum days)
 * comes from the BONUS rule version passed in — nothing is held here.
 *
 * One calculation for two readers: the compliance bonus register (ComplianceReports, provisional under a proposed
 * version) and the bonus run (HROne parity PO-22, which refuses to calculate unless the version is verified).
 */
final class BonusBasis
{
    /** Wages for bonus: the components classified as basic and dearness allowance in payroll. */
    public const WAGE_CLASSES = ['basic', 'da'];

    /** What counts as bonus paid: bonus-classified earnings (ex gratia has its own classification and is not bonus). */
    public const PAID_CLASSES = ['bonus'];

    public const ELIGIBLE = 'ELIGIBLE';

    public const INELIGIBLE = 'INELIGIBLE';

    public const PENDING = 'PENDING_DETERMINATION';

    public const INSUFFICIENT = 'INSUFFICIENT_DATA';

    public const UNVERIFIED = 'LEGALLY_UNVERIFIED';

    /** Why a determination is pending: wages crossed the eligibility ceiling, or the minimum wage that may raise the calculation ceiling is not held. */
    public const CROSSED_CEILING = 'crossed_ceiling';

    public const MINIMUM_WAGE = 'minimum_wage';

    /** A new per-employee accumulator. */
    public static function row(?Employee $employee): array
    {
        return ['employee' => $employee, 'months' => 0, 'days' => 0.0, 'wages' => 0.0, 'bonus_wages' => 0.0, 'over_ceiling' => 0, 'under_ceiling' => 0, 'paid' => 0.0, 'paid_on' => [], 'detail' => []];
    }

    /**
     * Add one month's finalized entry, measured under the version in force at the month's end (an accounting year can
     * straddle a change of law): a month's wages above the calculation ceiling count at the ceiling, prorated as the wages
     * were. The month's figures are kept in `detail` so a pay-rate override can recompute the wages for bonus.
     */
    public static function addMonth(array &$row, PayrollEntry $entry, ?ComplianceRule $monthRule, ?string $month = null): void
    {
        $wages = self::wages($entry);
        $fullMonth = self::fullMonth($entry);
        $row['months']++;
        $row['days'] += (float) $entry->paid_days;
        $row['wages'] += $wages;
        $ceiling = $monthRule !== null ? (float) $monthRule->param('calculation_ceiling') : null;
        if ($monthRule !== null && $fullMonth > 0) {
            $fullMonth > (float) $monthRule->param('eligibility_ceiling') ? $row['over_ceiling']++ : $row['under_ceiling']++;
            $row['bonus_wages'] += $ceiling > 0 ? min($wages, $ceiling * $wages / $fullMonth) : $wages;
        }
        $row['detail'][] = ['month' => $month, 'wages' => $wages, 'full_month' => $fullMonth, 'days' => (float) $entry->paid_days, 'ceiling' => $ceiling, 'rule_id' => $monthRule?->getKey()];
    }

    /**
     * The wages for bonus recomputed with a monthly pay rate in place of each month's calculation ceiling (the minimum
     * wage, when it is higher than the ceiling); null = each month's own ceiling, as addMonth() counted it.
     *
     * @param  list<array{wages: float, full_month: float, ceiling: ?float}>  $detail
     */
    public static function bonusWagesAt(array $detail, ?float $payRate = null): float
    {
        $sum = 0.0;
        foreach ($detail as $month) {
            if ($month['ceiling'] === null || (float) $month['full_month'] <= 0) {
                continue;
            }
            $cap = $payRate ?? (float) $month['ceiling'];
            $sum += $cap > 0 ? min((float) $month['wages'], $cap * (float) $month['wages'] / (float) $month['full_month']) : (float) $month['wages'];
        }

        return $sum;
    }

    /** Wages earned in the entry: basic + DA earnings. */
    public static function wages(PayrollEntry $entry): float
    {
        return round((float) $entry->lines->filter(fn ($l) => in_array($l->classification, self::WAGE_CLASSES, true) && $l->type === 'earning')->sum('amount'), 2);
    }

    /** The full-month (unprorated) basic + DA of the entry. */
    public static function fullMonth(PayrollEntry $entry): float
    {
        return round((float) $entry->lines->filter(fn ($l) => $l->type === 'earning' && in_array($l->classification, self::WAGE_CLASSES, true))
            ->sum(fn ($l) => (float) (($l->basis['full_month'] ?? null) ?? $l->amount)), 2);
    }

    /** Bonus paid in the entry: bonus-classified earnings. */
    public static function paid(PayrollEntry $entry): float
    {
        return round((float) $entry->lines->filter(fn ($l) => in_array($l->classification, self::PAID_CLASSES, true) && $l->type === 'earning')->sum('amount'), 2);
    }

    /** The minimum bonus on the wages for bonus: the rule's minimum rate, or its minimum amount if higher. */
    public static function minimum(float $bonusWages, ComplianceRule $rule): float
    {
        return max(round($bonusWages * (float) $rule->param('minimum_rate'), 2), (float) $rule->param('minimum_amount', 0));
    }

    /** The maximum bonus on the wages for bonus: the rule's maximum rate. */
    public static function maximum(float $bonusWages, ComplianceRule $rule): float
    {
        return round($bonusWages * (float) $rule->param('maximum_rate'), 2);
    }

    /**
     * The determination for the year, why, and (when pending) what it waits for.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    public static function determine(array $row, ?ComplianceRule $rule): array
    {
        if ($rule === null) {
            return [self::UNVERIFIED, '', null];
        }
        if ($row['months'] === 0 || ($row['over_ceiling'] + $row['under_ceiling']) === 0 || $row['wages'] <= 0) {
            return [self::INSUFFICIENT, 'No basic or DA wages in finalized payroll for the year.', null];
        }
        $minimumDays = (float) $rule->param('minimum_days', 0);
        if ($row['days'] < $minimumDays) {
            return [self::INELIGIBLE, "Paid for {$row['days']} days; at least {$minimumDays} days' work in the year are needed.", null];
        }
        $ceiling = number_format((float) $rule->param('eligibility_ceiling'));
        if ($row['under_ceiling'] === 0) {
            return [self::INELIGIBLE, "Wages (basic + DA) above ₹{$ceiling} a month throughout the year.", null];
        }
        if ($row['over_ceiling'] > 0) {
            return [self::PENDING, "Wages crossed the ₹{$ceiling} eligibility ceiling during the year ({$row['over_ceiling']} month(s) above): decide eligibility for the months below it.", self::CROSSED_CEILING];
        }
        if ($rule->param('calculation_ceiling_rule') === 'max_with_minimum_wage') {
            return [self::PENDING, 'Eligible on wages and days; the calculation ceiling is ₹'.number_format((float) $rule->param('calculation_ceiling')).' or the applicable minimum wage if higher, which PeopleOS does not hold — the bonus shown uses ₹'.number_format((float) $rule->param('calculation_ceiling')).'.', self::MINIMUM_WAGE];
        }

        return [self::ELIGIBLE, "Wages at or below ₹{$ceiling} a month and at least {$minimumDays} days' work.", null];
    }
}

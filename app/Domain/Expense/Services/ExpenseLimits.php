<?php

namespace App\Domain\Expense\Services;

use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Contracts\CountsTripUsage;
use App\Domain\Expense\Contracts\ExpenseUsage;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseCategoryLimit;
use App\Domain\Expense\Models\ExpenseLocationGroup;
use App\Domain\Expense\Support\Answers;
use App\Domain\Expense\Support\CompensationAmounts;
use App\Domain\Expense\Support\ExpenseLimit;
use App\Domain\Expense\Support\ExpensePeriods;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * HROne setup parity EX-B: the limit an employee has in an expense category on an expense date, for a place's location
 * group. Resolution order:
 *
 * 1. The employee's expense policy on the date must offer the category (no policy, or not offered: refused). An
 *    exceptional-only category needs exceptional expenses switched on (expense.exceptional.enabled).
 * 2. The limit: the policy's override for the location group, else the policy's override amount (distance for mileage),
 *    else the category's own limit row that fits best — the employee's value of the category's dimension (grade,
 *    designation …) and the place's location group, then the employee's value for every other place, then every other
 *    employee in the group, then everyone everywhere. A percentage of CTC is taken of the yearly CTC (or one CTC
 *    component) through CompensationAmounts (the CompensationOutput contract only). No fitting row: no limit.
 * 3. Hourly bands (daily allowance): with the hours worked, the limit is the band's percentage of it.
 * 4. Usage (ExpenseUsage) over the limit's period, the claim frequency's period and the policy's overall limit.
 *
 * Over the limit, a claim is capped unless the category (or the policy) lets it be filed as exceptional and
 * exceptional expenses are on. Receipts (EX-C) enforce what this returns.
 */
final class ExpenseLimits
{
    public function __construct(
        private readonly ExpensePolicies $policies,
        private readonly EmployeeRuleContext $context,
        private readonly SettingsRepository $settings,
        private readonly ExpenseUsage $usage,
        private readonly CompensationAmounts $pay,
    ) {}

    /**
     * $context: the minutes worked (hourly bands), the trip's dates (per-trip periods) and, with EX-F, the trip itself (a
     * per-trip period then counts only that trip's expenses), and the receipt being checked (left out of usage).
     *
     * @param  array{minutes?: ?int, trip?: ?array{0: CarbonInterface|string, 1: CarbonInterface|string}, trip_id?: ?int, except_receipt_id?: ?int}  $context
     */
    public function for(Employee $employee, ExpenseCategory $category, CarbonInterface|string $date, ?ExpenseLocationGroup $group = null, array $context = []): ExpenseLimit
    {
        $date = CarbonImmutable::parse($date)->startOfDay();
        $version = $this->policies->expense($employee, $date);
        if ($version === null) {
            return ExpenseLimit::refused('No expense policy applies to you on '.$date->format('j M Y').': the categories you can claim come from an expense policy.', null, $category->id);
        }
        $row = $this->policies->categoryRow($version, $category);
        if ($row === null) {
            return ExpenseLimit::refused("{$category->name} is not in your expense policy.", $version->id, $category->id);
        }
        if (! $category->isActive()) {
            return ExpenseLimit::refused("{$category->name} is no longer in use.", $version->id, $category->id);
        }
        $exceptionalOn = (bool) $this->settings->get('expense.exceptional.enabled', false);
        $exceptionalOnly = Answers::yes($row['exceptional_only'] ?? false);
        if ($exceptionalOnly && ! $exceptionalOn) {
            return ExpenseLimit::refused("{$category->name} can be claimed only as an exceptional expense, and exceptional expenses are switched off.", $version->id, $category->id);
        }

        $yearStart = (int) $this->settings->get('expense.year_start_month', 4);
        $trip = $context['trip'] ?? null;
        $tripId = isset($context['trip_id']) ? (int) $context['trip_id'] : null;
        $except = $context['except_receipt_id'] ?? null;
        $mileage = $category->isMileage();
        $basis = (string) $category->limit_basis;
        $unit = $mileage ? 'km' : 'amount';

        // The limit: the policy's override, else the category's best row.
        [$limit, $source, $rowId, $rowPeriod, $missingPay] = [null, null, null, null, false];
        if ($mileage && self::number($row['km_limit'] ?? null) !== null) {
            [$limit, $source] = [self::number($row['km_limit']), 'policy'];
        } elseif (! $mileage && $group !== null && ($override = $this->locationOverride($row, $group)) !== null) {
            [$limit, $source] = [$override, 'policy_location'];
        } elseif (! $mileage && self::number($row['limit_amount'] ?? null) !== null) {
            [$limit, $source] = [self::number($row['limit_amount']), 'policy'];
        } elseif ($basis !== 'none' && ($best = $this->bestRow($employee, $category, $date, $group)) !== null) {
            [$source, $rowId, $rowPeriod] = ['category', (int) $best->id, (string) $best->period];
            if ($basis === 'percent_of_ctc') {
                $annual = $this->pay->annual($employee, $date, $category->ctc_component_id ? SalaryComponent::query()->find($category->ctc_component_id) : null);
                $missingPay = $annual === null;
                $limit = round(((float) $best->percent) / 100 * ($annual ?? 0.0), 2);
            } else {
                $limit = (float) ($basis === 'km' ? $best->km_limit : $best->amount);
            }
        }
        $period = filled($row['limit_period'] ?? null) && array_key_exists($row['limit_period'], (array) config('peopleos.expense.periods')) ? (string) $row['limit_period'] : ($rowPeriod ?? 'per_claim');

        // Hourly bands of a daily allowance.
        $hourly = null;
        if ($limit !== null && ! $mileage && $category->hourly_constraints_enabled && isset($context['minutes'])) {
            $hourly = $this->bandPercent((array) $category->hourly_constraints, (int) $context['minutes']);
            $limit = round($limit * $hourly / 100, 2);
        }

        // Usage over the limit's period.
        $window = $limit !== null ? ExpensePeriods::window($period, $date, $yearStart, $trip) : null;
        $used = $window !== null ? (float) ($this->used($employee, $category, $period, $window, $except, $tripId)[$unit === 'km' ? 'km' : 'amount'] ?? 0) : 0.0;
        $remaining = $limit === null ? null : max(0.0, round($limit - $used, 2));
        $amountLimit = $limit === null ? null : ($mileage ? round($limit * (float) $category->rate_per_km, 2) : $limit);

        return new ExpenseLimit(
            allowed: true,
            policyVersionId: $version->id,
            categoryId: $category->id,
            exceptionalOnly: $exceptionalOnly,
            basis: $source === null || $source === 'category' ? $basis : ($mileage ? 'km' : 'amount'),
            unit: $limit === null ? null : $unit,
            limit: $limit,
            amountLimit: $amountLimit,
            period: $period,
            source: $source,
            limitRowId: $rowId,
            window: $window === null ? null : [$window[0]->toDateString(), $window[1]->toDateString()],
            used: round($used, 2),
            remaining: $remaining,
            hourlyPercent: $hourly,
            frequency: $this->frequency($employee, $category, $row, $date, $yearStart, $trip, $except, $tripId),
            overall: $this->overall($employee, $version->settings ?? [], $date, $yearStart, $trip, $except, $tripId),
            overLimit: $exceptionalOn && $this->exceptionAllowed($category, $row) ? 'exceptional' : 'cap',
            receiptRequired: (bool) $category->receipt_required,
            receiptRequiredAbove: self::number($row['receipt_above'] ?? null) ?? ($category->receipt_required_above !== null ? (float) $category->receipt_required_above : null),
            compensationMissing: $missingPay,
        );
    }

    /** The category's limit row that fits the employee and place best, or null. */
    public function bestRow(Employee $employee, ExpenseCategory $category, CarbonInterface $date, ?ExpenseLocationGroup $group): ?ExpenseCategoryLimit
    {
        $dimension = $category->limit_dimension;
        $value = $dimension !== null ? ($this->context->build($employee, $date)[$dimension] ?? null) : null;
        $rows = $category->limits()->get();
        $groupId = $group?->id;
        foreach ([[$value, $groupId], [$value, null], [null, $groupId], [null, null]] as [$v, $g]) {
            $match = $rows->first(fn (ExpenseCategoryLimit $l) => $l->getAttributes()['match_key'] === ExpenseCategoryLimit::matchKey($v, $g));
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    private function locationOverride(array $row, ExpenseLocationGroup $group): ?float
    {
        foreach ((array) ($row['location_limits'] ?? []) as $limit) {
            if (is_array($limit) && filled($limit['location_group'] ?? null) && (int) $limit['location_group'] === (int) $group->id && self::number($limit['amount'] ?? null) !== null) {
                return self::number($limit['amount']);
            }
        }

        return null;
    }

    /** The percentage of the band holding the minutes worked; 0 when no band holds them. */
    private function bandPercent(array $bands, int $minutes): float
    {
        foreach ($bands as $band) {
            if ($minutes >= (int) $band['from_minutes'] && $minutes < (int) $band['to_minutes']) {
                return (float) $band['percent'];
            }
        }

        return 0.0;
    }

    /** @return array{period: string, count: int, used: int, remaining: int, window: ?array{0: string, 1: string}}|null */
    private function frequency(Employee $employee, ExpenseCategory $category, array $row, CarbonImmutable $date, int $yearStart, ?array $trip, ?int $except, ?int $tripId = null): ?array
    {
        $period = $category->claim_frequency_period;
        if ($period === null) {
            return null;
        }
        $count = (int) (self::number($row['claim_frequency_count'] ?? null) ?? $category->claim_frequency_count);
        $window = ExpensePeriods::window($period, $date, $yearStart, $trip);
        $used = $window !== null ? (int) ($this->used($employee, $category, $period, $window, $except, $tripId)['count'] ?? 0) : 0;

        return ['period' => $period, 'count' => $count, 'used' => $used, 'remaining' => max(0, $count - $used),
            'window' => $window === null ? null : [$window[0]->toDateString(), $window[1]->toDateString()]];
    }

    /** @return array{amount: float, period: string, used: float, remaining: float, window: ?array{0: string, 1: string}}|null */
    private function overall(Employee $employee, array $settings, CarbonImmutable $date, int $yearStart, ?array $trip, ?int $except, ?int $tripId = null): ?array
    {
        $amount = self::number($settings['overall_limit_amount'] ?? null);
        if (! Answers::yes($settings['overall_limit_enabled'] ?? null) || $amount === null) {
            return null;
        }
        $period = filled($settings['overall_limit_period'] ?? null) && array_key_exists($settings['overall_limit_period'], (array) config('peopleos.expense.periods')) ? (string) $settings['overall_limit_period'] : 'per_claim';
        $window = ExpensePeriods::window($period, $date, $yearStart, $trip);
        $used = $window !== null ? (float) ($this->used($employee, null, $period, $window, $except, $tripId)['amount'] ?? 0) : 0.0;

        return ['amount' => $amount, 'period' => $period, 'used' => round($used, 2), 'remaining' => max(0.0, round($amount - $used, 2)),
            'window' => $window === null ? null : [$window[0]->toDateString(), $window[1]->toDateString()]];
    }

    /**
     * Usage over a window; EX-F: a per-trip period counts only the trip's own expenses when the usage source can tell them
     * apart (CountsTripUsage), else every expense dated within the trip.
     *
     * @param  array{0: CarbonInterface, 1: CarbonInterface}  $window
     * @return array{amount: float, km: float, count: int}
     */
    private function used(Employee $employee, ?ExpenseCategory $category, string $period, array $window, ?int $except, ?int $tripId): array
    {
        if ($period === 'trip' && $tripId !== null && $this->usage instanceof CountsTripUsage) {
            return $this->usage->forTrip($employee, $category, $tripId, $except);
        }

        return $this->usage->between($employee, $category, $window[0], $window[1], $except);
    }

    private function exceptionAllowed(ExpenseCategory $category, array $row): bool
    {
        $override = $row['limit_exception_allowed'] ?? null;

        return match (true) {
            Answers::yes($override) => true,
            Answers::no($override) => false,
            default => (bool) $category->limit_exception_allowed,
        };
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 ? round((float) $value, 2) : null;
    }
}

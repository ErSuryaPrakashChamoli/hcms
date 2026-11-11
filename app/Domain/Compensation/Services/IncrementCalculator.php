<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\CompensationBudget;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Support\CompensationSnapshot;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Services\EmployeeSelection;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Support\Csv\SpreadsheetCell;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne parity PO-17 (matrix 25 §25.9.2 #2, #5–#8): the increment calculator's rules on a compensation cycle and the
 * arithmetic they give. It never writes compensation: CompensationCycles populates the lines through
 * CompensationChanges, and every line is still reviewed, approved and executed by other people.
 *
 * - Population (`population`): organisation filters on the employee's position on the effective date (business unit,
 *   division, department with its sub-departments, designation, grade, level, location, employment type) and pasted
 *   employee codes to keep or leave out, inside the cycle's company and the preparer's access scope.
 * - Basis (`increment_basis`): `ctc_percent` (the earlier rule: annual CTC × (1 + % / 100)), `fixed_percent` (the
 *   percentage of fixed pay = annual CTC less the variable-pay target) or `amount` (a flat annual amount).
 * - Grid (`rating_increase_percent` in the shape {"shape": "ctc_band_rating", "bands": [...]}): bands of annual CTC
 *   (from / to, the last may be open), each with rating rows (from / to on the finalized rating → value) and an
 *   optional value for any other rating. A legacy {rating label: percent} matrix is read exactly as before.
 * - Tenure proration (`prorate_on_tenure`): the value × min(1, full months / `proration_full_months`), the months
 *   counted from joining or from the start of the current designation up to `proration_cutoff`.
 *
 * Employees the rules cannot give a line are skipped with the reason (the cycle's error file).
 */
final class IncrementCalculator
{
    public const GRID_SHAPE = 'ctc_band_rating';

    /** Position columns the population may filter on (the cycle's company is fixed). */
    public const POPULATION_COLUMNS = ['business_unit_id', 'division_id', 'department_id', 'designation_id', 'grade_id', 'level_id', 'location_id', 'employment_type_id'];

    /** The earlier percentage bounds of a cycle (and the database check on the default). */
    private const PERCENT_MIN = -100.0;

    private const PERCENT_MAX = 1000.0;

    private const AMOUNT_LIMIT = 1e11;

    public function __construct(private readonly AuditRecorder $audit, private readonly AccessScopes $scopes) {}

    // --- Rules ----------------------------------------------------------------------------------------------------------

    /**
     * The calculator's columns from the data of a new cycle (validated). Without any calculator input the result is the
     * earlier cycle: percent of CTC, no proration, no population filter, the rating matrix as given.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function rules(array $data): array
    {
        $basis = filled($data['increment_basis'] ?? null) ? (string) $data['increment_basis'] : 'ctc_percent';
        if (! array_key_exists($basis, CompensationCycle::BASES)) {
            throw new CompensationRuleViolation("Unknown increment basis [{$basis}].");
        }
        $amount = $data['default_increase_amount'] ?? null;
        if (filled($amount) && (! is_numeric($amount) || abs((float) $amount) >= self::AMOUNT_LIMIT)) {
            throw new CompensationRuleViolation('The default increase amount must be a number below 100,000,000,000.');
        }

        $prorate = filter_var($data['prorate_on_tenure'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $prorationBasis = null;
        $cutoff = null;
        $months = null;
        if ($prorate) {
            $prorationBasis = filled($data['proration_basis'] ?? null) ? (string) $data['proration_basis'] : 'joining';
            if (! array_key_exists($prorationBasis, CompensationCycle::PRORATION_BASES)) {
                throw new CompensationRuleViolation("Unknown proration basis [{$prorationBasis}].");
            }
            try {
                $cutoff = Carbon::parse((string) (filled($data['proration_cutoff'] ?? null) ? $data['proration_cutoff'] : $data['effective_from']))->toDateString();
            } catch (Throwable) {
                throw new CompensationRuleViolation('The proration cut-off must be a date.');
            }
            $months = (int) ($data['proration_full_months'] ?? 12) ?: 12;
            if ($months < 1 || $months > 120) {
                throw new CompensationRuleViolation('A full increment needs between 1 and 120 months of service.');
            }
        }

        return [
            'increment_basis' => $basis,
            'default_increase_amount' => filled($amount) ? round((float) $amount, 2) : null,
            'prorate_on_tenure' => $prorate, 'proration_basis' => $prorationBasis, 'proration_cutoff' => $cutoff, 'proration_full_months' => $months,
            'rating_increase_percent' => $this->matrix($data['rating_increase_percent'] ?? null, $basis, filled($data['performance_cycle_id'] ?? null)),
            'population' => $this->population($data['population'] ?? null),
        ];
    }

    /** @return array<string, mixed>|null the legacy label matrix, or the CTC band × rating grid */
    private function matrix(mixed $input, string $basis, bool $rated): ?array
    {
        $input = (array) ($input ?? []);
        if (is_array($input['bands'] ?? null)) {
            return $this->grid($input['bands'], $basis, $rated);
        }
        $matrix = [];
        foreach ($input as $label => $value) {
            $this->assertValue($value, $basis, $basis === 'amount' ? "The increase for rating [{$label}]" : null, $label);
            $matrix[(string) $label] = round((float) $value, 2);
        }

        return $matrix ?: null;
    }

    /**
     * HROne's grid rules: "To should be greater than or equal to From"; "From annual salary should be greater than the
     * last filled annual salary in To"; "From rating should be greater than the last filled rating in To".
     *
     * @param  array<int|string, mixed>  $bands
     * @return array{shape: string, bands: list<array{ctc_from: float, ctc_to: ?float, value: ?float, ratings: list<array{from: float, to: float, value: float}>}>}|null
     */
    private function grid(array $bands, string $basis, bool $rated): ?array
    {
        $out = [];
        $previousTo = null;
        $open = false;
        foreach (array_values($bands) as $i => $band) {
            $band = (array) $band;
            $n = $i + 1;
            $from = $band['ctc_from'] ?? null;
            $to = $band['ctc_to'] ?? null;
            if (! is_numeric($from) || (float) $from < 0) {
                throw new CompensationRuleViolation("Band {$n}: give the annual CTC it starts from (zero or more).");
            }
            if (filled($to) && ! is_numeric($to)) {
                throw new CompensationRuleViolation("Band {$n}: the annual CTC it goes up to must be a number.");
            }
            $to = filled($to) ? (float) $to : null;
            if ($to !== null && $to < (float) $from) {
                throw new CompensationRuleViolation("Band {$n}: To should be greater than or equal to From.");
            }
            if ($open || ($previousTo !== null && (float) $from <= $previousTo)) {
                throw new CompensationRuleViolation("Band {$n}: From annual salary should be greater than the last filled annual salary in To.");
            }
            $value = $band['value'] ?? null;
            if (filled($value)) {
                $this->assertValue($value, $basis, "Band {$n}");
            }
            $ratings = [];
            $lastRating = null;
            foreach (array_values((array) ($band['ratings'] ?? [])) as $j => $row) {
                $row = (array) $row;
                $m = $j + 1;
                if (! is_numeric($row['from'] ?? null) || ! is_numeric($row['to'] ?? null)) {
                    throw new CompensationRuleViolation("Band {$n}, rating row {$m}: give the rating it runs from and to.");
                }
                if ((float) $row['to'] < (float) $row['from']) {
                    throw new CompensationRuleViolation("Band {$n}, rating row {$m}: To should be greater than or equal to From.");
                }
                if ($lastRating !== null && (float) $row['from'] <= $lastRating) {
                    throw new CompensationRuleViolation("Band {$n}, rating row {$m}: From rating should be greater than the last filled rating in To.");
                }
                $this->assertValue($row['value'] ?? null, $basis, "Band {$n}, rating row {$m}");
                $ratings[] = ['from' => round((float) $row['from'], 2), 'to' => round((float) $row['to'], 2), 'value' => round((float) $row['value'], 2)];
                $lastRating = (float) $row['to'];
            }
            if (! filled($value) && ($ratings === [] || ! $rated)) {
                throw new CompensationRuleViolation($rated
                    ? "Band {$n}: give a value, rating rows, or both."
                    : "Band {$n}: without a performance cycle every band needs a value (rating rows apply only to finalized ratings).");
            }
            $out[] = ['ctc_from' => round((float) $from, 2), 'ctc_to' => $to !== null ? round($to, 2) : null, 'value' => filled($value) ? round((float) $value, 2) : null, 'ratings' => $ratings];
            $previousTo = $to;
            $open = $to === null;
        }

        return $out === [] ? null : ['shape' => self::GRID_SHAPE, 'bands' => $out];
    }

    private function assertValue(mixed $value, string $basis, ?string $what, int|string|null $label = null): void
    {
        if ($basis === 'amount') {
            if (! is_numeric($value) || abs((float) $value) >= self::AMOUNT_LIMIT) {
                throw new CompensationRuleViolation(($what ?? 'The increase').': the amount must be a number below 100,000,000,000.');
            }

            return;
        }
        if (! is_numeric($value) || (float) $value < self::PERCENT_MIN || (float) $value > self::PERCENT_MAX) {
            // The earlier message for the rating matrix, word for word.
            throw new CompensationRuleViolation($what === null ? "The increase for rating [{$label}] must be a percentage between -100 and 1000." : "{$what}: the increase must be a percentage between -100 and 1000.");
        }
    }

    /**
     * @return array{organisation?: array<string, list<int>>, codes?: list<string>, exclude_codes?: list<string>}|null
     */
    public function population(mixed $input): ?array
    {
        $input = (array) ($input ?? []);
        $organisation = [];
        foreach (self::POPULATION_COLUMNS as $column) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) (($input['organisation'] ?? [])[$column] ?? [])))));
            if ($ids !== []) {
                $organisation[$column] = $ids;
            }
        }
        $codes = EmployeeSelection::parseCodes($input['codes'] ?? null);
        $exclude = EmployeeSelection::parseCodes($input['exclude_codes'] ?? null);
        $out = array_filter(['organisation' => $organisation, 'codes' => $codes, 'exclude_codes' => $exclude]);

        return $out === [] ? null : $out;
    }

    /**
     * Narrow a cycle's eligible employees by its population: the positions on the effective date and the employees.
     */
    public function applyPopulation(CompensationCycle $cycle, Builder $positions, Builder $employees): void
    {
        $population = (array) ($cycle->population ?? []);
        foreach ((array) ($population['organisation'] ?? []) as $column => $ids) {
            if (in_array($column, self::POPULATION_COLUMNS, true) && $ids !== []) {
                $ids = array_map('intval', (array) $ids);
                $positions->whereIn($column, $column === 'department_id' ? EmployeeSelection::withSubDepartments($ids) : $ids);
            }
        }
        $employees
            ->when(($population['codes'] ?? []) !== [], fn (Builder $q) => $q->whereIn('employee_code', (array) $population['codes']))
            ->when(($population['exclude_codes'] ?? []) !== [], fn (Builder $q) => $q->whereNotIn('employee_code', (array) $population['exclude_codes']));
    }

    /**
     * Pasted codes that did not give an eligible employee (the error file says so, without telling why: an employee
     * outside the preparer's access is not disclosed).
     *
     * @param  Collection<int, Employee>  $eligible
     * @return list<array{employee_id: null, employee_code: string, reason: string}>
     */
    public function missingCodes(CompensationCycle $cycle, Collection $eligible): array
    {
        $found = $eligible->pluck('employee_code')->map(fn ($c) => (string) $c)->all();

        return array_values(array_map(fn (string $code) => ['employee_id' => null, 'employee_code' => $code,
            'reason' => 'Not in the plan: no employee with this code in the company and filters on the effective date, in a state that takes part in cycles and in your access.'],
            array_diff((array) (($cycle->population ?? [])['codes'] ?? []), $found)));
    }

    // --- Arithmetic -----------------------------------------------------------------------------------------------------

    /**
     * What the rules give one employee: the value (percent or amount), where it came from, the tenure factor and the
     * proposed annual CTC — or why the employee is skipped.
     *
     * @param  array<string, mixed>|null  $outcome  the finalized performance outcome (PerformanceOutcomesReader), when read
     * @return array{skip: ?string, value: float, label: ?string, rating: ?float, band: ?array<string, mixed>, factor: float, months: ?int, increase: float, proposed: float, percent: float}
     */
    public function evaluate(CompensationCycle $cycle, Employee $employee, CompensationSnapshot $current, ?array $outcome): array
    {
        $result = ['skip' => null, 'value' => 0.0, 'label' => null, 'rating' => null, 'band' => null, 'factor' => 1.0, 'months' => null, 'increase' => 0.0, 'proposed' => $current->ctcAnnual, 'percent' => 0.0];
        $skip = fn (string $why) => ['skip' => $why] + $result;

        // 1. The value.
        $matrix = (array) ($cycle->rating_increase_percent ?? []);
        $default = $cycle->isAmountBasis() ? (float) ($cycle->default_increase_amount ?? 0) : (float) $cycle->default_increase_percent;
        if ($cycle->hasGrid()) {
            $band = $this->bandFor($cycle, $current->ctcAnnual);
            if ($band === null) {
                return $skip('Annual CTC is outside every band of the grid.');
            }
            $result['band'] = $band;
            $value = $band['value'];
            if ($cycle->performance_cycle_id && $band['ratings'] !== []) {
                $rating = is_numeric($outcome['final_rating'] ?? null) ? (float) $outcome['final_rating'] : null;
                $result['label'] = $outcome['final_label'] ?? null;
                $result['rating'] = $rating;
                foreach ($band['ratings'] as $row) {
                    if ($rating !== null && $rating >= (float) $row['from'] && $rating <= (float) $row['to']) {
                        $value = (float) $row['value'];
                        break;
                    }
                }
                if ($value === null) {
                    return $skip($rating === null
                        ? 'No finalized rating in the performance cycle, and the band has no value for other ratings.'
                        : 'The finalized rating is outside the band\'s rating rows, and the band has no value for other ratings.');
                }
            }
            if ($value === null) {
                return $skip('The band has no value for this plan.');
            }
            $result['value'] = (float) $value;
        } elseif ($cycle->performance_cycle_id && $matrix !== []) {
            // The earlier rating matrix, read as before: the finalized label's value, else the default.
            $label = $outcome['final_label'] ?? null;
            $result['label'] = $label;
            $result['value'] = $label !== null && array_key_exists($label, $matrix) ? (float) $matrix[$label] : $default;
        } else {
            $result['value'] = $default;
        }

        // 2. Tenure proration.
        if ($cycle->prorate_on_tenure) {
            $months = $this->monthsOfService($cycle, $employee);
            $full = max(1, (int) ($cycle->proration_full_months ?: 12));
            if ($months <= 0) {
                return $skip('No full month of service by the proration cut-off date.');
            }
            $result['months'] = $months;
            $result['factor'] = min(1.0, $months / $full);
        }

        // 3. The proposed CTC.
        $ctc = $current->ctcAnnual;
        $factor = $result['factor'];
        if ($cycle->increment_basis === 'ctc_percent' && $factor === 1.0) {
            $proposed = round($ctc * (1 + $result['value'] / 100), 2);   // the earlier rule, unchanged
            $percent = $result['value'];
        } else {
            $proposed = round($ctc + $this->increaseFor($cycle, $current, $result['value']) * $factor, 2);
            $percent = $ctc > 0 ? round(($proposed - $ctc) / $ctc * 100, 2) : 0.0;
        }
        if ($proposed <= 0) {
            return $skip('The proposed annual CTC would not be positive.');
        }
        if ($percent > self::PERCENT_MAX || $percent < self::PERCENT_MIN) {
            return $skip('The increase would be more than 1000% of the annual CTC.');
        }

        return ['proposed' => $proposed, 'percent' => (float) $percent, 'increase' => round($proposed - $ctc, 2)] + $result;
    }

    /** The increase a value gives before proration: a percent of CTC or of fixed pay, or the amount itself. */
    public function increaseFor(CompensationCycle $cycle, CompensationSnapshot $current, float $value): float
    {
        return match ($cycle->increment_basis) {
            'fixed_percent' => self::fixedPay($current) * $value / 100,
            'amount' => $value,
            default => $current->ctcAnnual * $value / 100,
        };
    }

    /** Fixed pay: the annual CTC less the annual variable-pay target (never below zero). */
    public static function fixedPay(CompensationSnapshot $current): float
    {
        return max(0.0, $current->ctcAnnual - (float) ($current->variableTargetAnnual ?? 0));
    }

    /** @return array{ctc_from: float, ctc_to: ?float, value: ?float, ratings: list<array{from: float, to: float, value: float}>}|null */
    public function bandFor(CompensationCycle $cycle, float $ctc): ?array
    {
        foreach ((array) (($cycle->rating_increase_percent ?? [])['bands'] ?? []) as $band) {
            if ($ctc >= (float) $band['ctc_from'] && ($band['ctc_to'] === null || $ctc <= (float) $band['ctc_to'])) {
                return $band;
            }
        }

        return null;
    }

    /** Full months of service up to the cut-off: since joining, or since the current designation started (not before joining). */
    public function monthsOfService(CompensationCycle $cycle, Employee $employee): int
    {
        $cutoff = Carbon::parse($cycle->proration_cutoff ?? $cycle->effective_from)->startOfDay();
        $start = $employee->joining_date?->copy()->startOfDay();
        if ($cycle->proration_basis === 'role' && ($role = $this->roleStart($employee, $cutoff)) !== null) {
            // Never more months in the designation than since joining.
            $start = $start === null ? $role : $start->max($role)->copy();
        }
        if ($start === null || $start->gt($cutoff)) {
            return 0;
        }

        return (int) floor($start->diffInMonths($cutoff->copy()->addDay()));
    }

    /** The start of the employee's current designation on the date: the earliest unbroken position row with it. */
    private function roleStart(Employee $employee, Carbon $on): ?Carbon
    {
        $rows = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $on->toDateString().' 23:59:59')->orderByDesc('effective_from')->orderByDesc('id')->get(['designation_id', 'effective_from']);
        $start = null;
        $designation = $rows->first()?->designation_id;
        foreach ($rows as $row) {
            if ($row->designation_id !== $designation) {
                break;
            }
            $start = $row->effective_from->copy()->startOfDay();
        }

        return $start;
    }

    /**
     * The increase a manager may propose for an employee: from the lowest to the highest value the plan's grid (or the
     * rating matrix and default) gives the employee's CTC band, as amounts after proration; never below "no increase"
     * when every value is an increase.
     *
     * @return array{low: float, high: float}
     */
    public function range(CompensationCycle $cycle, Employee $employee, CompensationSnapshot $current): array
    {
        if ($cycle->hasGrid()) {
            $band = $this->bandFor($cycle, $current->ctcAnnual);
            $values = $band === null ? [] : array_values(array_filter([$band['value'], ...array_column($band['ratings'], 'value')], fn ($v) => $v !== null));
        } else {
            $values = [$cycle->isAmountBasis() ? (float) ($cycle->default_increase_amount ?? 0) : (float) $cycle->default_increase_percent, ...array_values((array) ($cycle->rating_increase_percent ?? []))];
        }
        $factor = 1.0;
        if ($cycle->prorate_on_tenure) {
            $factor = min(1.0, $this->monthsOfService($cycle, $employee) / max(1, (int) ($cycle->proration_full_months ?: 12)));
        }
        $amounts = array_map(fn ($v) => round($this->increaseFor($cycle, $current, (float) $v) * $factor, 2), $values ?: [0]);

        return ['low' => min(0.0, ...$amounts), 'high' => max(0.0, ...$amounts)];
    }

    /** The line's reason: the earlier wording when no calculator rule is used, with the band, basis and proration otherwise. */
    public function reasonFor(CompensationCycle $cycle, array $result, string $currency): string
    {
        $parts = [];
        if ($result['band'] !== null) {
            $parts[] = 'CTC band '.number_format((float) $result['band']['ctc_from'], 2).($result['band']['ctc_to'] !== null ? '–'.number_format((float) $result['band']['ctc_to'], 2) : ' and above').', ';
        }
        $rating = $result['label'] ?? ($result['rating'] !== null ? number_format((float) $result['rating'], 2) : null);
        $value = $cycle->isAmountBasis()
            ? $currency.' '.number_format($result['value'], 2)
            : number_format($result['value'], 2).'%'.($cycle->increment_basis === 'fixed_percent' ? ' of fixed pay' : '');
        $prorated = $result['months'] !== null ? ', prorated '.min($result['months'], (int) $cycle->proration_full_months).'/'.(int) $cycle->proration_full_months.' months' : '';

        return implode('', $parts).($rating !== null ? "rating {$rating}, " : '').$value.$prorated;
    }

    // --- Summary and error file -----------------------------------------------------------------------------------------

    /**
     * HROne's "Summary" (§25.9.2 #8): headcount, current and proposed cost, the increase and its percentage, the average
     * increment and the budget used, from the cycle's lines the viewer may reach (amounts in the main currency; other
     * currencies are listed apart).
     *
     * @return array<string, mixed>
     */
    public function summary(CompensationCycle $cycle): array
    {
        $rows = CompensationChange::query()->where('compensation_cycle_id', $cycle->id)
            ->get(['id', 'currency', 'ctc_annual', 'previous_ctc_annual', 'base_ctc_annual', 'increase_percent'])
            ->map(fn (CompensationChange $c) => ['currency' => (string) $c->currency, 'proposed' => (float) $c->ctc_annual,
                'current' => $c->previous_ctc_annual !== null ? (float) $c->previous_ctc_annual : ($c->base_ctc_annual !== null ? (float) $c->base_ctc_annual : null),
                'percent' => $c->increase_percent !== null ? (float) $c->increase_percent : null]);

        return $this->summarise($cycle, $rows->all(), count((array) ($cycle->population_errors ?? [])));
    }

    /**
     * @param  list<array{currency: string, proposed: float, current: ?float, percent: ?float}>  $rows
     * @return array<string, mixed>
     */
    public function summarise(CompensationCycle $cycle, array $rows, int $skipped): array
    {
        $budget = $cycle->compensation_budget_id ? CompensationBudget::query()->find($cycle->compensation_budget_id) : null;
        $byCurrency = [];
        foreach ($rows as $row) {
            $c = &$byCurrency[$row['currency']];
            $c ??= ['headcount' => 0, 'current' => 0.0, 'proposed' => 0.0, 'percents' => [], 'unknown' => 0];
            $c['headcount']++;
            if ($row['current'] === null) {
                $c['unknown']++;
            } else {
                $c['current'] += $row['current'];
                $c['proposed'] += $row['proposed'];
            }
            if ($row['percent'] !== null) {
                $c['percents'][] = $row['percent'];
            }
            unset($c);
        }
        uasort($byCurrency, fn ($a, $b) => $b['headcount'] <=> $a['headcount']);
        $main = $budget?->currency ?? (array_key_first($byCurrency) ?? app(CurrencyRates::class)->baseCurrency());
        $totals = $byCurrency[$main] ?? ['headcount' => 0, 'current' => 0.0, 'proposed' => 0.0, 'percents' => [], 'unknown' => 0];
        $increase = round($totals['proposed'] - $totals['current'], 2);
        $out = [
            'currency' => $main, 'headcount' => array_sum(array_column($byCurrency, 'headcount')), 'headcount_in_currency' => $totals['headcount'],
            'current_cost' => round($totals['current'], 2), 'proposed_cost' => round($totals['proposed'], 2), 'increase' => $increase,
            'increase_percent' => $totals['current'] > 0 ? round($increase / $totals['current'] * 100, 2) : null,
            'average_percent' => $totals['percents'] !== [] ? round(array_sum($totals['percents']) / count($totals['percents']), 2) : null,
            'unknown_base' => $totals['unknown'], 'skipped' => $skipped,
            'other_currencies' => collect($byCurrency)->except($main)->map(fn ($c) => $c['headcount'])->all(),
            'budget' => null, 'budget_used_percent' => null, 'budget_left_after' => null, 'over_budget' => null,
        ];
        if ($budget !== null) {
            $measures = app(CompensationBudgets::class)->measures($budget);
            // The plan's lines are charged at approval: before that, what is left after the plan = variance − increase.
            $leftAfter = in_array($cycle->status, ['draft', 'submitted', 'under_review'], true) ? round($measures['variance'] - $increase, 2) : $measures['variance'];
            $out = [
                'budget' => (float) $budget->amount, 'budget_code' => $budget->code,
                'budget_used_percent' => (float) $budget->amount > 0 ? round($increase / (float) $budget->amount * 100, 2) : null,
                'budget_left_after' => $leftAfter, 'over_budget' => $leftAfter < 0,
            ] + $out;
        }

        return $out;
    }

    /**
     * HROne's "DOWNLOAD ERROR EXCEL": the employees the last calculation skipped and why, as CSV (formula-injection
     * safe), for the people who prepare cycles; audited as an export. Employees outside the downloader's access are
     * left out.
     */
    public function errorCsv(CompensationCycle $cycle, User $actor): string
    {
        if (! $actor->hasPermission('compensation.cycles')) {
            throw new CompensationRuleViolation('This needs compensation.cycles.');
        }
        if (! $this->scopes->allows($actor, $cycle)) {
            throw new CompensationRuleViolation('That record is outside your organisation scope.');
        }
        $errors = (array) ($cycle->population_errors ?? []);
        $employees = Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')
            ->whereKey(array_values(array_filter(array_column($errors, 'employee_id'))))->get()->keyBy('id');

        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Employee code', 'Employee', 'Reason']);
        $rows = 0;
        foreach ($errors as $error) {
            $employee = $error['employee_id'] ? $employees->get((int) $error['employee_id']) : null;
            if ($error['employee_id'] && ($employee === null || ! $this->scopes->allows($actor, $employee))) {
                continue;
            }
            fputcsv($out, array_map(self::cell(...), [$error['employee_code'] ?? $employee?->employee_code, $employee?->person?->full_name, $error['reason'] ?? '']));
            $rows++;
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $this->audit->record(AuditAction::Export, 'compensation', $cycle, [], null, actor: $actor, metadata: ['export' => 'increment_errors', 'rows' => $rows]);

        return $csv;
    }

    private static function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        // Spreadsheet formula injection: a cell starting with = + - @ is read as text.
        return SpreadsheetCell::guard($text);
    }
}

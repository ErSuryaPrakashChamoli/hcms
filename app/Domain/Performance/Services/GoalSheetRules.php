<?php

namespace App\Domain\Performance\Services;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\Goal;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * HROne setup parity HP-8d (Goal → Goal sheet policy): the rules of the goal sheet policy (type `goal_sheet`) that
 * applies to an employee, read through PolicyResolver. Weight and count rules for KRAs (goals), KPIs (key results) and
 * competencies; review configuration per level; scorecard fields hidden from the employee; copying the previous level's
 * inputs; goal-plan approval. Every rule is optional: without a policy (or with a blank answer) nothing changes.
 * Field rights are GoalFieldRules. Also the policy type's validator (validatePolicySettings).
 *
 * Owner decision 2026-10-29 (system mapping): under `goal_creation = system` the reporting manager owns the final goal
 * sheet of a cycle (managerOwned): the employee's additions and changes are proposals the manager confirms through the goal
 * plan approval (Goals, GoalPlans). `goal_sheet_owner` now only says who fills the mapped sheet in first.
 */
final class GoalSheetRules
{
    public const OPEN_STATUSES = ['draft', 'pending_approval', 'active'];

    public function __construct(private readonly PolicyResolver $policies, private readonly SettingsRepository $settings) {}

    public function policyFor(?Employee $employee): ?PolicyVersion
    {
        return $employee === null ? null : $this->policies->resolve('goal_sheet', $employee, today());
    }

    /** A policy answer, or $default when there is no policy or the answer is blank. */
    public function value(?Employee $employee, string $key, mixed $default = null): mixed
    {
        return self::answer($this->policyFor($employee), $key, $default);
    }

    public static function answer(?PolicyVersion $policy, string $key, mixed $default = null): mixed
    {
        $value = $policy?->setting($key);

        return $value === null || $value === '' || $value === [] ? $default : $value;
    }

    private static function number(?PolicyVersion $policy, string $key): ?float
    {
        $value = self::answer($policy, $key);

        return is_numeric($value) ? (float) $value : null;
    }

    private static function employeeOf(Appraisal $appraisal): ?Employee
    {
        return $appraisal->loadMissing('employee')->employee;
    }

    private static function yes(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'yes', 'true'], true);
    }

    private static function no(mixed $value): bool
    {
        return in_array($value, [false, 0, '0', 'no', 'false'], true);
    }

    // --- Goal creation and approval --------------------------------------------------------------

    /**
     * mode manual | system; the template to map; owner = who fills the mapped sheet in first (told it is ready): the
     * employee or the reporting manager. Any other stored answer (blank, or a value of an older wording) reads as the
     * employee. Whoever fills it first, the reporting manager confirms it (managerOwned).
     *
     * @return array{mode: string, template: ?string, owner: string}
     */
    public function creation(?Employee $employee): array
    {
        $policy = $this->policyFor($employee);

        return ['mode' => self::answer($policy, 'goal_creation', 'manual') === 'system' ? 'system' : 'manual',
            'template' => self::answer($policy, 'goal_template_code'), 'owner' => self::answer($policy, 'goal_sheet_owner', 'employee') === 'manager' ? 'manager' : 'employee'];
    }

    /**
     * Owner decision 2026-10-29: whether the employee's goal sheet of this cycle is system-mapped, so the reporting manager
     * owns its final version (the employee proposes, the manager confirms). Goals outside a cycle and manually created
     * goal sheets (no system mapping) are not.
     */
    public function managerOwned(?Employee $employee, int|string|null $cycleId): bool
    {
        return ! empty($cycleId) && $employee !== null && $this->creation($employee)['mode'] === 'system';
    }

    /** none (today) | manager | workflow */
    public function planApproval(?Employee $employee): string
    {
        $value = $this->value($employee, 'plan_approval', 'none');

        return in_array($value, ['manager', 'workflow'], true) ? $value : 'none';
    }

    // --- KRA (goal) rules ------------------------------------------------------------------------

    /** The policy's total KRA weightage, taking precedence over the template version and the cycle (Goals::requiredWeight). */
    public function totalGoalWeight(?Employee $employee): ?float
    {
        $total = self::number($this->policyFor($employee), 'total_goal_weight');

        return $total !== null && $total > 0 ? $total : null;
    }

    public function equalGoalWeights(?Employee $employee): bool
    {
        return $this->value($employee, 'goal_weight_mode') === 'equal';
    }

    /**
     * One goal of an employee's goal sheet, as it will be saved: single KRA weightage (min / max, a weightage when the
     * mode is "specific"), the maximum number of KRAs in a cycle (on create), and the UOM and target when mandatory.
     *
     * @param  array<string, mixed>  $attributes  the goal after the change
     */
    public function assertGoal(array $attributes, ?Goal $current = null): void
    {
        if (($attributes['level'] ?? 'employee') !== 'employee' || empty($attributes['employee_id']) || in_array($attributes['status'] ?? 'active', ['cancelled', 'completed'], true)) {
            return;
        }
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($attributes['employee_id']);
        $policy = $this->policyFor($employee);
        if ($policy === null) {
            return;
        }
        $mode = self::answer($policy, 'goal_weight_mode', 'any');
        $weight = (float) ($attributes['weight'] ?? 0);
        if ($mode !== 'equal') {
            if ($mode === 'specific' && $weight <= 0) {
                throw new RuntimeException('Each KRA needs a weightage under your goal sheet policy.');
            }
            $min = self::number($policy, 'min_goal_weight');
            $max = self::number($policy, 'max_goal_weight');
            if ($min !== null && $weight < $min) {
                throw new RuntimeException("A KRA's weightage must be at least {$min}.");
            }
            if ($max !== null && $max > 0 && $weight > $max) {
                throw new RuntimeException("A KRA's weightage can be at most {$max}.");
            }
        }
        $maxGoals = self::number($policy, 'max_goals');
        if ($current === null && $maxGoals !== null && $maxGoals > 0 && ! empty($attributes['performance_cycle_id'])
            && $this->openGoals((int) $attributes['employee_id'], (int) $attributes['performance_cycle_id'])->count() >= $maxGoals) {
            throw new RuntimeException('The goal sheet already has the most KRAs allowed ('.(int) $maxGoals.').');
        }
        if (self::yes(self::answer($policy, 'unit_and_target_required'))) {
            if (blank($attributes['unit_id'] ?? null) && blank($attributes['unit'] ?? null)) {
                throw new RuntimeException('The unit of measurement (UOM) of a KRA is mandatory.');
            }
            if (! is_numeric($attributes['target_value'] ?? null)) {
                throw new RuntimeException('The target of a KRA is mandatory.');
            }
        }
    }

    /**
     * The KPIs (key results) of one goal as they will be saved: their number, single and total weightage, UOM and target.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function assertKeyResults(?Employee $employee, ?float $goalWeight, array $rows): void
    {
        $policy = $this->policyFor($employee);
        if ($policy === null) {
            return;
        }
        $count = count($rows);
        $min = self::number($policy, 'min_kpis');
        $max = self::number($policy, 'max_kpis');
        if ($min !== null && $count < $min) {
            throw new RuntimeException('Each KRA needs at least '.(int) $min.' KPI(s).');
        }
        if ($max !== null && $max > 0 && $count > $max) {
            throw new RuntimeException('A KRA can have at most '.(int) $max.' KPI(s).');
        }
        $mode = self::answer($policy, 'kpi_weight_mode', 'any');
        if ($mode !== 'equal' && $count > 0) {
            $minWeight = self::number($policy, 'min_kpi_weight');
            $maxWeight = self::number($policy, 'max_kpi_weight');
            foreach ($rows as $row) {
                $weight = (float) ($row['weight'] ?? 0);
                if ($mode === 'specific' && $weight <= 0) {
                    throw new RuntimeException('Each KPI needs a weightage under your goal sheet policy.');
                }
                if ($minWeight !== null && $weight < $minWeight) {
                    throw new RuntimeException("A KPI's weightage must be at least {$minWeight}.");
                }
                if ($maxWeight !== null && $maxWeight > 0 && $weight > $maxWeight) {
                    throw new RuntimeException("A KPI's weightage can be at most {$maxWeight}.");
                }
            }
            $total = $this->kpiTotal($policy, $goalWeight);
            $sum = array_sum(array_map(fn ($r) => (float) ($r['weight'] ?? 0), $rows));
            if ($total !== null && abs($sum - $total) > 0.001) {
                throw new RuntimeException("The KPI weightages of a KRA add up to {$sum}; they must add up to {$total}.");
            }
        }
        if (self::yes(self::answer($policy, 'unit_and_target_required'))) {
            foreach ($rows as $row) {
                if ((blank($row['unit_id'] ?? null) && blank($row['unit'] ?? null)) || ! is_numeric($row['target_value'] ?? null)) {
                    throw new RuntimeException('The UOM and the target of each KPI are mandatory.');
                }
            }
        }
    }

    /** The total the KPIs of a goal must reach (null = no rule, or the KRA's weightage is the system's to set). */
    private function kpiTotal(?PolicyVersion $policy, ?float $goalWeight): ?float
    {
        return match (self::answer($policy, 'kpi_total_weight', 'none')) {
            'equal_to_goal' => $goalWeight, 'hundred' => 100.0, default => null,
        };
    }

    /** At submission: the number of KRAs on the goal sheet (minimum and maximum). */
    public function assertPlan(Employee $employee, int $cycleId): void
    {
        $policy = $this->policyFor($employee);
        if ($policy === null) {
            return;
        }
        $count = $this->openGoals($employee->id, $cycleId)->count();
        $min = self::number($policy, 'min_goals');
        $max = self::number($policy, 'max_goals');
        if ($min !== null && $count < $min) {
            throw new RuntimeException('The goal sheet needs at least '.(int) $min.' KRA(s); it has '.$count.'.');
        }
        if ($max !== null && $max > 0 && $count > $max) {
            throw new RuntimeException('The goal sheet can have at most '.(int) $max.' KRA(s); it has '.$count.'.');
        }
    }

    /** @return Collection<int, Goal> the employee's goals of a cycle that are still on the sheet */
    public function openGoals(int $employeeId, int $cycleId): Collection
    {
        return Goal::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employeeId)->where('performance_cycle_id', $cycleId)
            ->where('level', 'employee')->whereIn('status', self::OPEN_STATUSES)->orderBy('id')->get();
    }

    /**
     * "All KRAs of equal weightage": the open, unlocked goals of the employee's sheet share the total (the required total,
     * else 100) in whole numbers. Returns how many goals changed.
     */
    public function rebalanceGoals(Employee $employee, ?int $cycleId, ?float $total): int
    {
        if ($cycleId === null || ! $this->equalGoalWeights($employee)) {
            return 0;
        }
        $goals = $this->openGoals($employee->id, $cycleId)->reject(fn (Goal $g) => $g->is_locked)->values();

        return $this->spread($goals, (int) round($total ?? 100));
    }

    /** "All KPIs of equal weightage": the goal's key results share the KPI total (the KRA's weightage, or 100). */
    public function rebalanceKeyResults(Goal $goal): int
    {
        if ($goal->employee_id === null) {
            return 0;
        }
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($goal->employee_id);
        $policy = $this->policyFor($employee);
        if (self::answer($policy, 'kpi_weight_mode') !== 'equal') {
            return 0;
        }
        $krs = $goal->keyResults()->get();

        return $this->spread($krs, (int) round($this->kpiTotal($policy, (float) $goal->weight) ?? 100));
    }

    /** Integer split of a total over records (the first ones take the remainder): 100 over 3 = 34, 33, 33. */
    private function spread(Collection $records, int $total): int
    {
        $count = $records->count();
        if ($count === 0) {
            return 0;
        }
        $base = intdiv($total, $count);
        $extra = $total - $base * $count;
        $changed = 0;
        foreach ($records->values() as $i => $record) {
            $weight = $base + ($i < $extra ? 1 : 0);
            if ((int) $record->weight !== $weight) {
                $record->update(['weight' => $weight]);
                $changed++;
            }
        }

        return $changed;
    }

    // --- Competencies ----------------------------------------------------------------------------

    /** Whether the employee's competencies count (Include competency; blank = yes). */
    public function includesCompetencies(Appraisal $appraisal): bool
    {
        return ! self::no($this->value(self::employeeOf($appraisal), 'include_competencies'));
    }

    /** @return list<int>|null the competencies the employee is rated on (null = the cycle's) */
    public function competencyIds(Appraisal $appraisal): ?array
    {
        $codes = (array) $this->value(self::employeeOf($appraisal), 'competency_codes', []);
        if ($codes === []) {
            return null;
        }

        return Competency::query()->whereIn('code', $codes)->orderBy('name')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return Collection<int, Competency> the competencies on the employee's review form (none when excluded) */
    public function competenciesFor(Appraisal $appraisal): Collection
    {
        $appraisal->loadMissing(['cycle', 'employee']);
        if (! $this->includesCompetencies($appraisal)) {
            return collect();
        }
        $ids = $this->competencyIds($appraisal);

        return $ids === null ? $appraisal->cycle->competencies() : Competency::query()->whereIn('id', $ids)->orderBy('name')->get();
    }

    /** The competency block's share of the overall rating (Total competency weightage), or null for the cycle's weights. */
    public function competencyBlockWeight(Appraisal $appraisal): ?float
    {
        $weight = self::number($this->policyFor(self::employeeOf($appraisal)), 'competency_block_weight');

        return $weight !== null && $weight >= 0 && $weight <= 100 ? $weight : null;
    }

    // --- Reviews and the scorecard ---------------------------------------------------------------

    /**
     * What a review level rates and how: level goal | key_result, input scale | percentage, evidence off | optional |
     * required. Self, manager and approver reviews follow the policy; other reviews (peers) rate goals on the scale.
     *
     * @return array{level: string, input: string, evidence: string}
     */
    public function reviewConfig(Appraisal $appraisal, ?string $reviewType): array
    {
        $prefix = match ($reviewType) {
            'self' => 'self', 'manager' => 'manager', 'approver' => 'approver', default => null
        };
        $policy = $prefix === null ? null : $this->policyFor(self::employeeOf($appraisal));

        return [
            'level' => self::answer($policy, "{$prefix}_review_level") === 'key_result' ? 'key_result' : 'goal',
            'input' => self::answer($policy, "{$prefix}_review_input") === 'percentage' ? 'percentage' : 'scale',
            'evidence' => in_array(self::answer($policy, "{$prefix}_evidence"), ['optional', 'required'], true) ? self::answer($policy, "{$prefix}_evidence") : 'off',
        ];
    }

    /** @return list<string> scorecard parts hidden from the employee (Performance scorecard configuration) */
    public function scorecardHidden(?Employee $employee): array
    {
        return array_values(array_map('strval', (array) $this->value($employee, 'scorecard_hidden', [])));
    }

    /** Copy the previous level's goal (or competency) inputs to the next level: the policy, else the organisation setting. */
    public function copiesInputs(Appraisal $appraisal, string $what = 'goal'): bool
    {
        $answer = $this->value(self::employeeOf($appraisal), $what === 'competency' ? 'copy_competency_inputs' : 'copy_goal_inputs');
        if ($answer !== null) {
            return self::yes($answer);
        }
        // HP-8i: then the performance policy's "copy last level inputs to the next level", then the organisation setting.
        $performance = app(PerformancePolicyRules::class)->copiesInputs($appraisal, $what);
        if ($performance !== null) {
            return $performance;
        }

        return (bool) $this->settings->get('performance.review.copy_previous_level', false);
    }

    /** @return list<array<string, mixed>> the field-rights rows of the employee's policy (GoalFieldRules) */
    public function fieldRules(?Employee $employee): array
    {
        return array_values(array_filter((array) $this->value($employee, 'field_rules', []), fn ($row) => is_array($row) && filled($row['field'] ?? null)));
    }

    /**
     * Rating from an achievement percentage (review input "percentage"): the goal's KPI rating formula when it has one,
     * else the same share of the scale (0 % = the lowest level, 100 % or more = the highest), on two decimals.
     */
    public function ratingForPct(?Goal $goal, float $pct, float $min, float $max): float
    {
        if ($goal !== null && ($formula = app(KpiRatings::class)->formulaFor($goal)) !== null && ($rating = $formula->ratingFor($pct)) !== null && $rating >= $min && $rating <= $max) {
            return round((float) $rating, 2);
        }

        return round($min + ($max - $min) * max(0, min(100, $pct)) / 100, 2);
    }

    // --- Policy validator ------------------------------------------------------------------------

    /**
     * Checked when a goal sheet policy is saved or published: minimums not above maximums, a whole total weightage, and
     * the chosen competencies within the number and single-weightage limits.
     *
     * @param  array<string, mixed>  $settings
     */
    public function validatePolicySettings(array $settings): void
    {
        $num = fn (string $key) => is_numeric($settings[$key] ?? null) ? (float) $settings[$key] : null;
        foreach ([['min_goal_weight', 'max_goal_weight', 'single KRA weightage'], ['min_goals', 'max_goals', 'number of KRAs'], ['min_kpi_weight', 'max_kpi_weight', 'single KPI weightage'],
            ['min_kpis', 'max_kpis', 'number of KPIs'], ['min_competency_weight', 'max_competency_weight', 'single competency weightage'], ['min_competencies', 'max_competencies', 'number of competencies']] as [$minKey, $maxKey, $label]) {
            if ($num($minKey) !== null && $num($maxKey) !== null && $num($maxKey) > 0 && $num($minKey) > $num($maxKey)) {
                throw new ConfigurationException("The minimum {$label} is above the maximum.");
            }
        }
        if ($num('total_goal_weight') !== null && floor($num('total_goal_weight')) !== $num('total_goal_weight')) {
            throw new ConfigurationException('The total KRA weightage is a whole number.');
        }
        $codes = array_values(array_filter((array) ($settings['competency_codes'] ?? [])));
        if ($codes !== []) {
            $chosen = Competency::query()->whereIn('code', $codes)->get();
            if ($num('min_competencies') !== null && $chosen->count() < $num('min_competencies')) {
                throw new ConfigurationException('Choose at least '.(int) $num('min_competencies').' competencies.');
            }
            if ($num('max_competencies') !== null && $num('max_competencies') > 0 && $chosen->count() > $num('max_competencies')) {
                throw new ConfigurationException('Choose at most '.(int) $num('max_competencies').' competencies.');
            }
            foreach ($chosen as $competency) {
                $weight = (float) $competency->weight;
                if (($num('min_competency_weight') !== null && $weight < $num('min_competency_weight')) || ($num('max_competency_weight') !== null && $num('max_competency_weight') > 0 && $weight > $num('max_competency_weight'))) {
                    throw new ConfigurationException("The weightage of {$competency->name} ({$weight}) is outside the single competency weightage limits.");
                }
            }
        }
        foreach ((array) ($settings['field_rules'] ?? []) as $row) {
            if (! is_array($row) || blank($row['field'] ?? null)) {
                throw new ConfigurationException('Each field right names a goal field.');
            }
        }
    }
}

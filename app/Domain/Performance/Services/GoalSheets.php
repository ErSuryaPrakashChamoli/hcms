<?php

namespace App\Domain\Performance\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalPlan;
use App\Domain\Performance\Models\Initiative;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HROne parity GO-1 (HR Desk → Performance → Employee goals, matrix 27 §27.2.1): goal sheets — one per employee and
 * cycle, the appraisal of the cycle's population — read from the goals and goal plans that already exist. Nothing here
 * writes: a sheet's status is worked out from its goals (employee level, not cancelled) and its latest goal plan:
 *
 * - not created: no goal on the sheet;
 * - waiting for approval: a goal plan pending, or goals waiting in `pending_approval`;
 * - sent back: draft goals and the latest goal plan was sent back;
 * - draft: draft goals not submitted (or the latest plan was not sent back);
 * - active: goals, none in draft or waiting.
 *
 * The same rules run as SQL (withSheet() numbers on each appraisal row; whereStatus() for the register's filter) and in
 * PHP (statusFrom()), so the filter and the column always agree. Every read runs in the viewer's access scope (the
 * appraisal, goal, plan and initiative queries carry it).
 */
final class GoalSheets
{
    public const STATUSES = [
        'not_created' => 'Not created',
        'draft' => 'Draft (not submitted)',
        'pending' => 'Waiting for approval',
        'sent_back' => 'Sent back',
        'active' => 'Active',
    ];

    public function __construct(private readonly Goals $goals, private readonly GoalSheetRules $rules) {}

    /** The goals of the appraisal's employee in its cycle (employee level, not cancelled), correlated to the outer appraisal row. */
    private static function goalsOf(): QueryBuilder
    {
        return DB::table('goals')
            ->whereColumn('goals.employee_id', 'appraisals.employee_id')
            ->whereColumn('goals.performance_cycle_id', 'appraisals.performance_cycle_id')
            ->where('goals.level', 'employee')
            ->where('goals.status', '!=', 'cancelled');
    }

    private static function plansOf(): QueryBuilder
    {
        return DB::table('goal_plans')
            ->whereColumn('goal_plans.employee_id', 'appraisals.employee_id')
            ->whereColumn('goal_plans.performance_cycle_id', 'appraisals.performance_cycle_id');
    }

    /** Adds the sheet's numbers to each appraisal row: goals, total weightage, drafts, goals waiting, pending plans, the latest plan, versions. */
    public function withSheet(Builder $appraisals): Builder
    {
        if (($appraisals->getQuery()->columns ?? []) === []) {
            $appraisals->select('appraisals.*');
        }

        return $appraisals->addSelect([
            'sheet_goals' => self::goalsOf()->selectRaw('count(*)'),
            'sheet_weight' => self::goalsOf()->selectRaw('coalesce(sum(goals.weight), 0)'),
            'sheet_drafts' => self::goalsOf()->where('goals.status', 'draft')->selectRaw('count(*)'),
            'sheet_waiting_goals' => self::goalsOf()->where('goals.status', 'pending_approval')->selectRaw('count(*)'),
            'sheet_pending_plans' => self::plansOf()->where('goal_plans.status', 'pending')->selectRaw('count(*)'),
            'sheet_plans' => self::plansOf()->selectRaw('count(*)'),
            'sheet_plan_id' => self::plansOf()->select('goal_plans.id')->orderByDesc('goal_plans.id')->limit(1),
            'sheet_plan_status' => self::plansOf()->select('goal_plans.status')->orderByDesc('goal_plans.id')->limit(1),
        ]);
    }

    /** Narrow an appraisal query to the goal sheets in one of the STATUSES (the same rules as statusFrom()). */
    public function whereStatus(Builder $appraisals, string $status): Builder
    {
        $waiting = fn (Builder $q) => $q->where(fn (Builder $w) => $w
            ->whereExists(self::plansOf()->where('goal_plans.status', 'pending')->selectRaw('1'))
            ->orWhereExists(self::goalsOf()->where('goals.status', 'pending_approval')->selectRaw('1')));
        $notWaiting = fn (Builder $q) => $q
            ->whereNotExists(self::plansOf()->where('goal_plans.status', 'pending')->selectRaw('1'))
            ->whereNotExists(self::goalsOf()->where('goals.status', 'pending_approval')->selectRaw('1'));
        $drafts = fn (Builder $q) => $q->whereExists(self::goalsOf()->where('goals.status', 'draft')->selectRaw('1'));
        $latestRejected = self::plansOf()->select('goal_plans.status')->orderByDesc('goal_plans.id')->limit(1);

        return match ($status) {
            'not_created' => $appraisals->whereNotExists(self::goalsOf()->selectRaw('1')),
            'pending' => $appraisals->whereExists(self::goalsOf()->selectRaw('1'))->where(fn (Builder $q) => $waiting($q)),
            'sent_back' => $appraisals->where(fn (Builder $q) => $drafts($notWaiting($q)))->where($latestRejected, '=', 'rejected'),
            'draft' => $appraisals->where(fn (Builder $q) => $drafts($notWaiting($q)))
                ->where(fn (Builder $q) => $q->whereNotExists(self::plansOf()->selectRaw('1'))->orWhere(self::plansOf()->select('goal_plans.status')->orderByDesc('goal_plans.id')->limit(1), '!=', 'rejected')),
            'active' => $appraisals->whereExists(self::goalsOf()->selectRaw('1'))->where(fn (Builder $q) => $notWaiting($q))
                ->whereNotExists(self::goalsOf()->where('goals.status', 'draft')->selectRaw('1')),
            default => $appraisals,
        };
    }

    /** The status of a sheet from the withSheet() numbers on its appraisal row. */
    public static function statusFrom(Appraisal $row): string
    {
        $a = $row->getAttributes();
        if ((int) ($a['sheet_goals'] ?? 0) === 0) {
            return 'not_created';
        }
        if ((int) ($a['sheet_waiting_goals'] ?? 0) > 0 || (int) ($a['sheet_pending_plans'] ?? 0) > 0) {
            return 'pending';
        }
        if ((int) ($a['sheet_drafts'] ?? 0) > 0) {
            return ($a['sheet_plan_status'] ?? null) === 'rejected' ? 'sent_back' : 'draft';
        }

        return 'active';
    }

    /**
     * Everything the sheet view shows: its goals with their KPIs, weightage against the total required, the competencies
     * rated with the goals and their weightage (as scoring uses them), the competency share of the rating, and every goal
     * plan (version) in order with who submitted and decided it.
     *
     * @return array{status: string, goals: Collection<int, Goal>, total_weight: float, required_weight: ?float, competencies: list<array{name: string, weight: float}>, competency_share: ?float, plans: Collection<int, GoalPlan>}
     */
    public function sheet(Appraisal $appraisal): array
    {
        $appraisal->loadMissing(['cycle.templateVersion', 'employee']);
        $employee = $appraisal->employee;
        $cycle = $appraisal->cycle;
        $goals = Goal::query()->with('keyResults')->where('employee_id', $appraisal->employee_id)->where('performance_cycle_id', $appraisal->performance_cycle_id)
            ->where('level', 'employee')->where('status', '!=', 'cancelled')->orderByDesc('weight')->orderBy('id')->get();
        $competencies = $this->rules->competenciesFor($appraisal);
        $weights = $cycle === null ? [] : app(Appraisals::class)->competencyWeightsFor($cycle, $competencies->pluck('id'));
        $plans = GoalPlan::query()->with(['submitter', 'decider'])->where('employee_id', $appraisal->employee_id)
            ->where('performance_cycle_id', $appraisal->performance_cycle_id)->orderBy('id')->get();
        $row = $this->withSheet(Appraisal::query()->whereKey($appraisal->id))->first();

        return [
            'status' => $row === null ? 'not_created' : self::statusFrom($row),
            'goals' => $goals,
            'total_weight' => round((float) $goals->sum('weight'), 2),
            'required_weight' => $employee === null ? null : $this->goals->requiredWeight($appraisal->performance_cycle_id, $employee),
            'competencies' => $competencies->map(fn ($c) => ['name' => (string) $c->name, 'weight' => (float) ($weights[(int) $c->id] ?? 0)])->values()->all(),
            'competency_share' => $this->competencyShare($appraisal),
            'plans' => $plans,
        ];
    }

    /** The competencies' share of the rating: the goal sheet policy's, else the performance policy's, else the cycle's. */
    private function competencyShare(Appraisal $appraisal): ?float
    {
        if (! $this->rules->includesCompetencies($appraisal)) {
            return 0.0;
        }
        $block = $this->rules->competencyBlockWeight($appraisal);
        if ($block !== null) {
            return $block;
        }
        $policy = app(PerformancePolicyRules::class)->weights($appraisal);

        return isset($policy['competencies']) ? (float) $policy['competencies'] : ($appraisal->cycle?->weight('competencies'));
    }

    /**
     * HROne "Goals and initiative" flyer (§27.4.3 #2): one employee's goals and initiatives of a cycle, as the viewer may
     * see them — goals only for HR (performance.view), the employee and their performance managers (the goal rules);
     * initiatives as the initiative rules list them (Initiatives::visibleTo). An initiative belongs to the cycle when its
     * linked goal is in the cycle or its dates fall inside the cycle's period.
     *
     * @return array{goals: Collection<int, Goal>|null, initiatives: Collection<int, Initiative>}
     */
    public function goalsAndInitiatives(User $viewer, Employee $employee, ?PerformanceCycle $cycle): array
    {
        $goals = self::mayViewGoalsOf($viewer, $employee)
            ? Goal::query()->with('keyResults')->where('employee_id', $employee->id)->where('status', '!=', 'cancelled')
                ->when($cycle !== null, fn (Builder $q) => $q->where('performance_cycle_id', $cycle->id))
                ->orderByDesc('weight')->orderBy('id')->get()
            : null;
        $initiatives = app(Initiatives::class)->visibleTo($viewer)->with(['type', 'goal'])->where('employee_id', $employee->id)
            ->when($cycle !== null, function (Builder $q) use ($cycle) {
                $from = $cycle->period_start->toDateString();
                $until = $cycle->period_end->toDateString().' 23:59:59';
                $q->where(fn (Builder $w) => $w
                    ->whereIn('goal_id', Goal::query()->where('performance_cycle_id', $cycle->id)->select('id'))
                    ->orWhereBetween('due_date', [$from, $until])
                    ->orWhere(fn (Builder $s) => $s->whereNull('due_date')->whereBetween('start_date', [$from, $until])));
            })
            ->orderBy('due_date')->orderBy('id')->get();

        return ['goals' => $goals, 'initiatives' => $initiatives];
    }

    /** The goal rules (EmployeeOwnedPolicy): HR, the employee themself, or one of their performance managers. */
    public static function mayViewGoalsOf(User $viewer, Employee $employee): bool
    {
        return app(EmployeeOwnedPolicy::class)->view($viewer, (new Goal)->forceFill(['employee_id' => $employee->id]));
    }

    /** @return array<int, string> the cycles one employee has a goal sheet or goals in, latest first */
    public function cyclesOf(Employee $employee): array
    {
        $ids = Appraisal::query()->where('employee_id', $employee->id)->pluck('performance_cycle_id')
            ->merge(Goal::query()->where('employee_id', $employee->id)->whereNotNull('performance_cycle_id')->pluck('performance_cycle_id'))
            ->unique()->values();

        return PerformanceCycle::query()->whereKey($ids->all() ?: [0])->orderByDesc('period_start')->pluck('name', 'id')->all();
    }
}

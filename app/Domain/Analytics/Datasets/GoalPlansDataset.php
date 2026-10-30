<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Services\GoalSheets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * HROne parity AN-27 (HROne reports "Goal status", "Goals not created"): one goal sheet per employee and cycle — every
 * employee of the cycle's population, including those who have no goal yet — with its status worked out exactly as
 * Performance → Goal sheets does (GoalSheets: not created, draft, waiting for approval, sent back, active), the goals and
 * weightage on it, how many goal plans (versions) were submitted and the latest one's dates. For performance HR
 * (performance.view or performance.calibrate, as the Goal sheets screen) in their access scope.
 */
class GoalPlansDataset extends Dataset
{
    public function key(): string
    {
        return 'goal_plans';
    }

    public function label(): string
    {
        return 'Goal sheets and goal plan status (incl. employees without goals)';
    }

    public function category(): string
    {
        return 'Performance & learning';
    }

    public function permissions(): array
    {
        return ['performance.view', 'performance.calibrate'];
    }

    public function fields(): array
    {
        $attribute = fn (string $key) => fn (Appraisal $a) => $a->getAttributes()[$key] ?? null;
        $date = fn (string $key) => fn (Appraisal $a) => ($value = $a->getAttributes()[$key] ?? null) === null ? null : Carbon::parse($value);

        return [
            'cycle' => ['label' => 'Cycle', 'type' => 'string', 'value' => fn (Appraisal $a) => $a->cycle?->name],
            ...$this->employeeFields(fn (Appraisal $a) => $a->employee),
            'manager' => ['label' => 'Manager', 'type' => 'string', 'value' => fn (Appraisal $a) => $a->manager?->person?->full_name],
            'sheet_status' => ['label' => 'Goal sheet status', 'type' => 'string', 'value' => fn (Appraisal $a) => GoalSheets::STATUSES[GoalSheets::statusFrom($a)]],
            'sheet_status_key' => ['label' => 'Goal sheet status (code)', 'type' => 'string', 'value' => fn (Appraisal $a) => GoalSheets::statusFrom($a)],
            'has_goals' => ['label' => 'Goals created', 'type' => 'boolean', 'value' => fn (Appraisal $a) => (int) ($a->getAttributes()['sheet_goals'] ?? 0) > 0],
            'goals' => ['label' => 'Goals', 'type' => 'number', 'value' => fn (Appraisal $a) => (int) ($a->getAttributes()['sheet_goals'] ?? 0)],
            'total_weight' => ['label' => 'Total weightage (%)', 'type' => 'number', 'value' => fn (Appraisal $a) => round((float) ($a->getAttributes()['sheet_weight'] ?? 0), 2)],
            'draft_goals' => ['label' => 'Goals in draft', 'type' => 'number', 'value' => fn (Appraisal $a) => (int) ($a->getAttributes()['sheet_drafts'] ?? 0)],
            'goals_waiting' => ['label' => 'Goals waiting for approval', 'type' => 'number', 'value' => fn (Appraisal $a) => (int) ($a->getAttributes()['sheet_waiting_goals'] ?? 0)],
            'versions' => ['label' => 'Goal plans submitted', 'type' => 'number', 'value' => fn (Appraisal $a) => (int) ($a->getAttributes()['sheet_plans'] ?? 0)],
            'latest_plan_status' => ['label' => 'Latest goal plan', 'type' => 'string', 'value' => fn (Appraisal $a) => ($s = $attribute('sheet_plan_status')($a)) === null ? null : config("peopleos.performance.goal_plan_statuses.{$s}", $s)],
            'submitted_on' => ['label' => 'Latest plan submitted on', 'type' => 'date', 'value' => $date('sheet_submitted_at')],
            'decided_on' => ['label' => 'Latest plan decided on', 'type' => 'date', 'value' => $date('sheet_decided_at')],
            'appraisal_status' => ['label' => 'Appraisal status', 'type' => 'string', 'value' => fn (Appraisal $a) => $a->status],
            'count' => ['label' => 'Goal sheets (1 per row)', 'type' => 'number', 'value' => fn (Appraisal $a) => 1],
        ];
    }

    public function query(): Builder
    {
        $latest = fn (string $column) => DB::table('goal_plans')->select("goal_plans.{$column}")
            ->whereColumn('goal_plans.employee_id', 'appraisals.employee_id')->whereColumn('goal_plans.performance_cycle_id', 'appraisals.performance_cycle_id')
            ->orderByDesc('goal_plans.id')->limit(1);

        return app(GoalSheets::class)->withSheet(Appraisal::query())
            ->addSelect(['sheet_submitted_at' => $latest('submitted_at'), 'sheet_decided_at' => $latest('decided_at')])
            ->with(['cycle', ...self::employeeWith('employee'), 'manager.person'])
            ->orderByDesc('appraisals.performance_cycle_id')->orderBy('appraisals.employee_id');
    }
}

<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\ImprovementPlanCheckpoint;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity AN-27 (HROne report "PIP evaluation"): the review points of performance improvement plans — checkpoints
 * and the PIP policy's start / close checklist items — with their due date, owner, status and when and by whom they were
 * reviewed. Confidential like the plans dataset: only holders of performance.pip; a reader who may not see every plan
 * (no performance.view) gets only the plans the Improvement plans screen lists for them (their own, or those they
 * manage), and a run without a reader gets nothing. The plan's reasons, objectives and outcome and the checkpoint notes
 * and outcome text stay on the plan.
 */
class PipCheckpointsDataset extends Dataset
{
    public function key(): string
    {
        return 'pip_checkpoints';
    }

    public function label(): string
    {
        return 'PIP evaluations (checkpoints and checklists)';
    }

    public function category(): string
    {
        return 'Performance & learning';
    }

    public function permissions(): array
    {
        return ['performance.pip'];
    }

    public function fields(): array
    {
        $plan = fn (ImprovementPlanCheckpoint $c) => $c->plan;

        return [
            ...$this->employeeFields(fn (ImprovementPlanCheckpoint $c) => $c->plan?->employee),
            'manager' => ['label' => 'Manager', 'type' => 'string', 'value' => fn (ImprovementPlanCheckpoint $c) => $plan($c)?->manager?->person?->full_name],
            'plan_start' => ['label' => 'PIP start', 'type' => 'date', 'value' => fn (ImprovementPlanCheckpoint $c) => $plan($c)?->start_date],
            'plan_end' => ['label' => 'PIP end', 'type' => 'date', 'value' => fn (ImprovementPlanCheckpoint $c) => $plan($c)?->end_date],
            'plan_status' => ['label' => 'PIP status', 'type' => 'string', 'value' => fn (ImprovementPlanCheckpoint $c) => $plan($c) ? config("peopleos.performance.pip_statuses.{$plan($c)->status}", $plan($c)->status) : null],
            'checkpoint' => ['label' => 'Checkpoint', 'type' => 'string', 'value' => fn (ImprovementPlanCheckpoint $c) => $c->title],
            'kind' => ['label' => 'Kind', 'type' => 'string', 'value' => fn (ImprovementPlanCheckpoint $c) => config("peopleos.performance.checkpoint_kinds.{$c->kind}", $c->kind)],
            'owner' => ['label' => 'Checklist owner', 'type' => 'string', 'value' => fn (ImprovementPlanCheckpoint $c) => $c->owner],
            'due_date' => ['label' => 'Due', 'type' => 'date', 'value' => fn (ImprovementPlanCheckpoint $c) => $c->due_date],
            'days_left' => ['label' => 'Days left', 'type' => 'number', 'value' => fn (ImprovementPlanCheckpoint $c) => $c->status === 'pending' ? self::daysFromToday($c->due_date) : null],
            'is_overdue' => ['label' => 'Overdue', 'type' => 'boolean', 'value' => fn (ImprovementPlanCheckpoint $c) => $c->status === 'pending' && $c->due_date !== null && $c->due_date->lt(today())],
            'status' => ['label' => 'Status (code)', 'type' => 'string', 'value' => fn (ImprovementPlanCheckpoint $c) => $c->status],
            'status_label' => ['label' => 'Status', 'type' => 'string', 'value' => fn (ImprovementPlanCheckpoint $c) => config("peopleos.performance.checkpoint_statuses.{$c->status}", $c->status)],
            'reviewed_on' => ['label' => 'Reviewed on', 'type' => 'date', 'value' => fn (ImprovementPlanCheckpoint $c) => $c->reviewed_at],
            'reviewed_by' => ['label' => 'Reviewed by', 'type' => 'string', 'value' => fn (ImprovementPlanCheckpoint $c) => $c->reviewer?->name],
            'count' => ['label' => 'Checkpoints (1 per row)', 'type' => 'number', 'value' => fn (ImprovementPlanCheckpoint $c) => 1],
        ];
    }

    public function query(): Builder
    {
        $viewer = auth()->user();
        $query = ImprovementPlanCheckpoint::query()->with([...self::employeeWith('plan.employee'), 'plan.manager.person', 'reviewer'])
            ->orderBy('due_date')->orderBy('id');
        if (! $viewer instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        // Checkpoints are not access-scoped themselves; requiring a visible plan applies the reader's scope, and without
        // performance.view only the plans the screen lists for the reader (ImprovementPlanResource::getEloquentQuery).
        return $query->whereHas('plan', function (Builder $plan) use ($viewer) {
            if ($viewer->is_platform_admin || $viewer->hasPermission('performance.view')) {
                return;
            }
            $me = Employee::query()->where('user_id', $viewer->id)->value('id') ?? 0;
            $plan->where(fn (Builder $q) => $q->where('improvement_plans.employee_id', $me)->orWhere('improvement_plans.manager_id', $me));
        });
    }
}

<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Performance\Models\GoalCheckIn;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity AN-27 (HROne reports "Company checkin report", "Employee goal checkin", "Team checkin report"): every
 * progress update on a goal or KPI — the goal's level (company, business, department, team, employee), whose goal it is,
 * progress before and after, confidence, the note and who checked in. For performance HR (performance.view: goals and
 * their progress history are theirs to see in their access scope; organisation goals have no employee and are seen by
 * all of them, as on the Goals screen).
 */
class CheckInsDataset extends Dataset
{
    public function key(): string
    {
        return 'check_ins';
    }

    public function label(): string
    {
        return 'Goal check-ins (progress updates)';
    }

    public function category(): string
    {
        return 'Performance & learning';
    }

    public function permissions(): array
    {
        return ['performance.view'];
    }

    public function fields(): array
    {
        return [
            'checked_in_on' => ['label' => 'Checked in on', 'type' => 'date', 'value' => fn (GoalCheckIn $c) => $c->created_at],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn (GoalCheckIn $c) => $c->created_at?->format('Y-m')],
            'cycle' => ['label' => 'Cycle', 'type' => 'string', 'value' => fn (GoalCheckIn $c) => $c->goal?->cycle?->name],
            'level' => ['label' => 'Goal level', 'type' => 'string', 'value' => fn (GoalCheckIn $c) => $c->goal?->level],
            'level_label' => ['label' => 'Goal level (label)', 'type' => 'string', 'value' => fn (GoalCheckIn $c) => $c->goal ? config("peopleos.performance.goal_levels.{$c->goal->level}", $c->goal->level) : null],
            // Whose goal: the employee for employee goals, the organisation unit for the others.
            'owner' => ['label' => 'Goal of', 'type' => 'string', 'value' => fn (GoalCheckIn $c) => $c->goal?->employee_id !== null ? $c->goal?->employee?->person?->full_name : ($c->goal?->organisationNode?->name ?? 'Organisation')],
            ...$this->employeeFields(fn (GoalCheckIn $c) => $c->goal?->employee),
            'manager' => ['label' => 'Manager', 'type' => 'string', 'value' => fn (GoalCheckIn $c) => $c->goal?->employee?->currentManager?->manager?->person?->full_name],
            'goal' => ['label' => 'Goal', 'type' => 'string', 'value' => fn (GoalCheckIn $c) => $c->goal?->title],
            'key_result' => ['label' => 'KPI / key result', 'type' => 'string', 'value' => fn (GoalCheckIn $c) => $c->keyResult?->title],
            'previous_progress' => ['label' => 'Progress before (%)', 'type' => 'number', 'value' => fn (GoalCheckIn $c) => $c->previous_progress === null ? null : (float) $c->previous_progress],
            'progress' => ['label' => 'Progress (%)', 'type' => 'number', 'value' => fn (GoalCheckIn $c) => $c->progress === null ? null : (float) $c->progress],
            'change' => ['label' => 'Change (points)', 'type' => 'number', 'value' => fn (GoalCheckIn $c) => $c->progress === null ? null : round((float) $c->progress - (float) $c->previous_progress, 2)],
            'value' => ['label' => 'Value reached', 'type' => 'number', 'value' => fn (GoalCheckIn $c) => $c->value === null ? null : (float) $c->value],
            'confidence' => ['label' => 'Confidence', 'type' => 'string', 'value' => fn (GoalCheckIn $c) => $c->confidence === null ? null : (GoalCheckIn::CONFIDENCE[$c->confidence] ?? $c->confidence)],
            'source' => ['label' => 'Source', 'type' => 'string', 'value' => fn (GoalCheckIn $c) => $c->source],
            'note' => ['label' => 'Note', 'type' => 'string', 'value' => fn (GoalCheckIn $c) => $c->note],
            'by' => ['label' => 'Checked in by', 'type' => 'string', 'value' => fn (GoalCheckIn $c) => $c->author?->name],
            'count' => ['label' => 'Check-ins (1 per row)', 'type' => 'number', 'value' => fn (GoalCheckIn $c) => 1],
        ];
    }

    public function query(): Builder
    {
        // Check-ins are not access-scoped themselves; requiring a visible goal applies the reader's scope.
        return GoalCheckIn::query()->whereHas('goal')
            ->with(['goal.cycle', 'goal.organisationNode', ...self::employeeWith('goal.employee'), 'goal.employee.currentManager.manager.person', 'keyResult', 'author'])
            ->orderByDesc('created_at')->orderByDesc('id');
    }
}

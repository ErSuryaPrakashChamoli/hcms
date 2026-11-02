<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Support\GoalVisibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * Goals and OKRs for performance HR. HROne parity GO-4: owner person, goal status (health), visibility, tags, the goal it
 * is aligned to and how many goals are aligned to it, system / final achievement (D-GO7). Private goals reach a reader
 * only as GoalVisibilityScope allows (D-GO5); a private parent is named only to those who may see it.
 */
class GoalsDataset extends Dataset
{
    public function key(): string
    {
        return 'goals';
    }

    public function label(): string
    {
        return 'Goals and OKRs (progress, weight, due dates)';
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
            'cycle' => ['label' => 'Cycle', 'type' => 'string', 'value' => fn ($g) => $g->cycle?->name],
            'level' => ['label' => 'Goal level', 'type' => 'string', 'value' => fn ($g) => $g->level],
            ...$this->employeeFields(fn ($g) => $g->employee),
            'title' => ['label' => 'Goal', 'type' => 'string', 'value' => fn ($g) => $g->title],
            'type' => ['label' => 'Type', 'type' => 'string', 'value' => fn ($g) => $g->type],
            // HROne setup parity HP-8a / HP-8c: perspective, unit of measurement and reverse KPIs.
            'perspective' => ['label' => 'Perspective', 'type' => 'string', 'value' => fn ($g) => $g->perspective?->name],
            'unit' => ['label' => 'Unit of measurement', 'type' => 'string', 'value' => fn ($g) => $g->measurementUnit?->display() ?? $g->unit],
            'is_reverse' => ['label' => 'Lower is better', 'type' => 'boolean', 'value' => fn ($g) => (bool) $g->is_reverse],
            'weight' => ['label' => 'Weight (%)', 'type' => 'number', 'value' => fn ($g) => $g->weight !== null ? (int) $g->weight : null],
            'progress' => ['label' => 'Progress (%)', 'type' => 'number', 'value' => fn ($g) => $g->progress !== null ? (float) $g->progress : null],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($g) => $g->status],
            'owner' => ['label' => 'Owner (organisation goal)', 'type' => 'string', 'value' => fn ($g) => $g->ownerEmployee?->person?->full_name],
            'owner_code' => ['label' => 'Owner employee code', 'type' => 'string', 'value' => fn ($g) => $g->ownerEmployee?->employee_code],
            'health' => ['label' => 'Goal status (health)', 'type' => 'string', 'value' => fn ($g) => $g->healthLabel()],
            'health_key' => ['label' => 'Goal status key', 'type' => 'string', 'value' => fn ($g) => $g->healthKey()],
            'visibility' => ['label' => 'Visibility', 'type' => 'string', 'value' => fn ($g) => $g->visibility],
            'tags' => ['label' => 'Tags', 'type' => 'string', 'value' => fn ($g) => implode(', ', (array) $g->tags) ?: null],
            'aligned_to' => ['label' => 'Aligned to', 'type' => 'string', 'value' => fn ($g) => $g->parent_id === null ? null : ($g->parent?->title ?? GoalVisibility::parentTitleFor($g, auth()->user()))],
            'linked_goals' => ['label' => 'Linked goals', 'type' => 'number', 'value' => fn ($g) => (int) $g->children_count],
            'system_achievement' => ['label' => 'System calculated achievement (%)', 'type' => 'number', 'value' => fn ($g) => $g->systemAchievement()],
            'final_achievement' => ['label' => 'Final achievement (%)', 'type' => 'number', 'value' => fn ($g) => $g->finalAchievement()],
            'achievement_overridden' => ['label' => 'Actual achievement entered', 'type' => 'boolean', 'value' => fn ($g) => $g->achievementOverridden()],
            'start_date' => ['label' => 'Start', 'type' => 'date', 'value' => fn ($g) => $g->start_date],
            'due_date' => ['label' => 'Due', 'type' => 'date', 'value' => fn ($g) => $g->due_date],
            'is_overdue' => ['label' => 'Overdue', 'type' => 'boolean', 'value' => fn ($g) => $g->status === 'active' && $g->due_date !== null && $g->due_date->lt(today()) && (float) $g->progress < 100],
            'count' => ['label' => 'Goals (1 per row)', 'type' => 'number', 'value' => fn ($g) => 1],
        ];
    }

    public function query(): Builder
    {
        return Goal::query()->with([...self::employeeWith('employee'), 'cycle', 'perspective', 'measurementUnit', 'ownerEmployee.person', 'parent'])
            ->withCount(['children' => fn (Builder $q) => $q->where('status', '!=', 'cancelled')])->orderByDesc('id');
    }
}

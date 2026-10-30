<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Performance\Models\DevelopmentNeed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * HROne parity AN-27 (HROne report "TNI details": training recommended to employees): development needs identified in
 * performance (appraisal recommendations, PIPs, check-ins, one-on-ones, by hand) and by Learning — the competency or skill,
 * current and target level, priority, target date and status. For performance HR (performance.view, as the Development
 * needs screen) in their access scope. The description, the current / desired state and the reason stay on the need.
 */
class DevelopmentNeedsDataset extends Dataset
{
    public function key(): string
    {
        return 'development_needs';
    }

    public function label(): string
    {
        return 'Development needs (training needs, TNI)';
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
            ...$this->employeeFields(fn (DevelopmentNeed $n) => $n->employee),
            'title' => ['label' => 'Need', 'type' => 'string', 'value' => fn (DevelopmentNeed $n) => $n->title],
            'competency' => ['label' => 'Competency', 'type' => 'string', 'value' => fn (DevelopmentNeed $n) => $n->competency?->name],
            'skill' => ['label' => 'Skill', 'type' => 'string', 'value' => fn (DevelopmentNeed $n) => $n->skill?->name],
            'current_level' => ['label' => 'Current level', 'type' => 'number', 'value' => fn (DevelopmentNeed $n) => $n->current_level === null ? null : (float) $n->current_level],
            'target_level' => ['label' => 'Target level', 'type' => 'number', 'value' => fn (DevelopmentNeed $n) => $n->target_level === null ? null : (float) $n->target_level],
            'source' => ['label' => 'Identified in', 'type' => 'string', 'value' => fn (DevelopmentNeed $n) => $n->source_type === null ? null : Str::headline((string) $n->source_type)],
            'source_code' => ['label' => 'Identified in (code)', 'type' => 'string', 'value' => fn (DevelopmentNeed $n) => $n->source_type],
            'priority' => ['label' => 'Priority', 'type' => 'string', 'value' => fn (DevelopmentNeed $n) => config("peopleos.performance.development_need_priorities.{$n->priority}", $n->priority)],
            'status' => ['label' => 'Status (code)', 'type' => 'string', 'value' => fn (DevelopmentNeed $n) => $n->status],
            'status_label' => ['label' => 'Status', 'type' => 'string', 'value' => fn (DevelopmentNeed $n) => config("peopleos.performance.development_need_statuses.{$n->status}", $n->status)],
            'target_date' => ['label' => 'Target date', 'type' => 'date', 'value' => fn (DevelopmentNeed $n) => $n->target_date],
            'is_overdue' => ['label' => 'Past the target date', 'type' => 'boolean', 'value' => fn (DevelopmentNeed $n) => in_array($n->status, ['open', 'in_progress'], true) && $n->target_date !== null && $n->target_date->lt(today())],
            'created_on' => ['label' => 'Identified on', 'type' => 'date', 'value' => fn (DevelopmentNeed $n) => $n->created_at],
            'count' => ['label' => 'Needs (1 per row)', 'type' => 'number', 'value' => fn (DevelopmentNeed $n) => 1],
        ];
    }

    public function query(): Builder
    {
        return DevelopmentNeed::query()->with([...self::employeeWith('employee'), 'competency', 'skill'])->orderByDesc('created_at')->orderByDesc('id');
    }
}

<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Performance\Models\Goal;
use Illuminate\Database\Eloquent\Builder;

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
            'start_date' => ['label' => 'Start', 'type' => 'date', 'value' => fn ($g) => $g->start_date],
            'due_date' => ['label' => 'Due', 'type' => 'date', 'value' => fn ($g) => $g->due_date],
            'is_overdue' => ['label' => 'Overdue', 'type' => 'boolean', 'value' => fn ($g) => $g->status === 'active' && $g->due_date !== null && $g->due_date->lt(today()) && (float) $g->progress < 100],
            'count' => ['label' => 'Goals (1 per row)', 'type' => 'number', 'value' => fn ($g) => 1],
        ];
    }

    public function query(): Builder
    {
        return Goal::query()->with([...self::employeeWith('employee'), 'cycle', 'perspective', 'measurementUnit'])->orderByDesc('id');
    }
}

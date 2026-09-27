<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Performance\Models\Appraisal;
use Illuminate\Database\Eloquent\Builder;

class PerformanceDataset extends Dataset
{
    public function key(): string
    {
        return 'performance';
    }

    public function label(): string
    {
        return 'Appraisals';
    }

    public function permissions(): array
    {
        return ['performance.view'];
    }

    public function fields(): array
    {
        return [
            'cycle' => ['label' => 'Cycle', 'type' => 'string', 'value' => fn ($a) => $a->cycle?->name],
            'employee_code' => ['label' => 'Employee code', 'type' => 'string', 'value' => fn ($a) => $a->employee?->employee_code],
            'name' => ['label' => 'Name', 'type' => 'string', 'value' => fn ($a) => $a->employee?->person?->full_name],
            'department' => ['label' => 'Department', 'type' => 'string', 'value' => fn ($a) => $a->employee?->currentPosition?->department?->name],
            'manager' => ['label' => 'Manager', 'type' => 'string', 'value' => fn ($a) => $a->manager?->person?->full_name],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($a) => $a->status],
            'goal_score' => ['label' => 'Goal score %', 'type' => 'number', 'value' => fn ($a) => $a->goal_score === null ? null : (float) $a->goal_score],
            'competency_score' => ['label' => 'Competency score %', 'type' => 'number', 'value' => fn ($a) => $a->competency_score === null ? null : (float) $a->competency_score],
            'self_rating' => ['label' => 'Self rating', 'type' => 'number', 'value' => fn ($a) => $a->self_rating === null ? null : (float) $a->self_rating],
            'manager_rating' => ['label' => 'Manager rating', 'type' => 'number', 'value' => fn ($a) => $a->manager_rating === null ? null : (float) $a->manager_rating],
            'final_rating' => ['label' => 'Final rating', 'type' => 'number', 'value' => fn ($a) => $a->final_rating === null ? null : (float) $a->final_rating],
            'final_label' => ['label' => 'Final label', 'type' => 'string', 'value' => fn ($a) => $a->final_label],
            'promotion_recommended' => ['label' => 'Promotion recommended', 'type' => 'boolean', 'value' => fn ($a) => (bool) $a->promotion_recommended],
        ];
    }

    public function query(): Builder
    {
        return Appraisal::query()->with(['cycle', 'employee.person', 'employee.currentPosition.department', 'manager.person'])->orderByDesc('id');
    }
}

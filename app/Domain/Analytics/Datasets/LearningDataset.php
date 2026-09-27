<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Learning\Models\LearningEnrolment;
use Illuminate\Database\Eloquent\Builder;

class LearningDataset extends Dataset
{
    public function key(): string
    {
        return 'learning';
    }

    public function label(): string
    {
        return 'Learning enrolments';
    }

    public function permissions(): array
    {
        return ['learning.view'];
    }

    public function fields(): array
    {
        return [
            'course' => ['label' => 'Course', 'type' => 'string', 'value' => fn ($e) => $e->course?->title],
            'category' => ['label' => 'Category', 'type' => 'string', 'value' => fn ($e) => $e->course?->category],
            'employee_code' => ['label' => 'Employee code', 'type' => 'string', 'value' => fn ($e) => $e->employee?->employee_code],
            'name' => ['label' => 'Name', 'type' => 'string', 'value' => fn ($e) => $e->employee?->person?->full_name],
            'department' => ['label' => 'Department', 'type' => 'string', 'value' => fn ($e) => $e->employee?->currentPosition?->department?->name],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($e) => $e->status],
            'is_mandatory' => ['label' => 'Mandatory', 'type' => 'boolean', 'value' => fn ($e) => (bool) $e->is_mandatory],
            'progress' => ['label' => 'Progress %', 'type' => 'number', 'value' => fn ($e) => (float) $e->progress],
            'score' => ['label' => 'Score %', 'type' => 'number', 'value' => fn ($e) => $e->score === null ? null : (float) $e->score],
            'due_on' => ['label' => 'Due on', 'type' => 'date', 'value' => fn ($e) => $e->due_on],
            'completed_at' => ['label' => 'Completed at', 'type' => 'date', 'value' => fn ($e) => $e->completed_at],
            'is_completed' => ['label' => 'Completed (1/0)', 'type' => 'number', 'value' => fn ($e) => $e->status === 'completed' ? 1 : 0],
            'is_overdue' => ['label' => 'Overdue (1/0)', 'type' => 'number', 'value' => fn ($e) => $e->status === 'overdue' ? 1 : 0],
        ];
    }

    public function query(): Builder
    {
        return LearningEnrolment::query()->with(['course', 'employee.person', 'employee.currentPosition.department'])->orderByDesc('id');
    }
}

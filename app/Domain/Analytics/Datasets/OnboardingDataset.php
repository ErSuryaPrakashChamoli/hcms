<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Onboarding\Models\OnboardingPlan;
use Illuminate\Database\Eloquent\Builder;

class OnboardingDataset extends Dataset
{
    public function key(): string
    {
        return 'onboarding';
    }

    public function label(): string
    {
        return 'Onboarding progress (one row per new joiner)';
    }

    public function category(): string
    {
        return 'Joining & exit';
    }

    public function permissions(): array
    {
        return ['onboarding.view'];
    }

    public function fields(): array
    {
        $open = fn ($p) => $p->tasks->where('status', 'pending');

        return [
            ...$this->employeeFields(fn ($p) => $p->employee),
            'joining_date' => ['label' => 'Joining date', 'type' => 'date', 'value' => fn ($p) => $p->employee?->joining_date ?? $p->employee?->expected_joining_date],
            'template' => ['label' => 'Checklist', 'type' => 'string', 'value' => fn ($p) => $p->template?->name],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($p) => $p->status],
            'progress' => ['label' => 'Progress (%)', 'type' => 'number', 'value' => fn ($p) => (int) $p->progress],
            'tasks_total' => ['label' => 'Tasks', 'type' => 'number', 'value' => fn ($p) => $p->tasks->count()],
            'tasks_pending' => ['label' => 'Tasks pending', 'type' => 'number', 'value' => fn ($p) => $open($p)->count()],
            'tasks_overdue' => ['label' => 'Tasks overdue', 'type' => 'number', 'value' => fn ($p) => $open($p)->filter(fn ($t) => $t->due_on?->lt(today()))->count()],
            'mandatory_pending' => ['label' => 'Mandatory tasks pending', 'type' => 'number', 'value' => fn ($p) => $open($p)->where('is_mandatory', true)->count()],
            'started_on' => ['label' => 'Started on', 'type' => 'date', 'value' => fn ($p) => $p->started_at],
            'completed_on' => ['label' => 'Completed on', 'type' => 'date', 'value' => fn ($p) => $p->completed_at],
        ];
    }

    public function query(): Builder
    {
        return OnboardingPlan::query()->with([...self::employeeWith('employee'), 'template', 'tasks'])->orderByDesc('anchor_date');
    }
}

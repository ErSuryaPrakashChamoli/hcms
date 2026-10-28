<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Onboarding\Models\OnboardingTask;
use Illuminate\Database\Eloquent\Builder;

class OnboardingTasksDataset extends Dataset
{
    public function key(): string
    {
        return 'onboarding_tasks';
    }

    public function label(): string
    {
        return 'Onboarding tasks (owners, due dates, pending documents)';
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
        return [
            ...$this->employeeFields(fn ($t) => $t->plan?->employee),
            'phase' => ['label' => 'Phase', 'type' => 'string', 'value' => fn ($t) => $t->phase],
            'task' => ['label' => 'Task', 'type' => 'string', 'value' => fn ($t) => $t->title],
            'type' => ['label' => 'Task type', 'type' => 'string', 'value' => fn ($t) => $t->type],
            'document_type' => ['label' => 'Document', 'type' => 'string', 'value' => fn ($t) => $t->documentType?->name],
            'owner' => ['label' => 'Owner', 'type' => 'string', 'value' => fn ($t) => $t->owner?->name ?? $t->ownerRole?->name],
            'due_on' => ['label' => 'Due on', 'type' => 'date', 'value' => fn ($t) => $t->due_on],
            'is_mandatory' => ['label' => 'Mandatory', 'type' => 'boolean', 'value' => fn ($t) => (bool) $t->is_mandatory],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($t) => $t->status],
            'is_overdue' => ['label' => 'Overdue', 'type' => 'boolean', 'value' => fn ($t) => $t->status === 'pending' && $t->due_on !== null && $t->due_on->lt(today())],
            'completed_on' => ['label' => 'Completed on', 'type' => 'date', 'value' => fn ($t) => $t->completed_at],
            'count' => ['label' => 'Tasks (1 per row)', 'type' => 'number', 'value' => fn ($t) => 1],
        ];
    }

    public function query(): Builder
    {
        // Tasks are not access-scoped themselves; requiring a visible plan applies the viewer's scope.
        return OnboardingTask::query()->whereHas('plan')->with([...self::employeeWith('plan.employee'), 'owner', 'ownerRole', 'documentType'])->orderBy('due_on');
    }
}

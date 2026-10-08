<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Filament\Pages\TaskInbox;
use Illuminate\Support\Collection;

/** Phase 12: pending workflow approvals and tasks the user can act on (the Task Inbox), as experience tasks. */
final class WorkflowTaskSource implements ExperienceTaskSource
{
    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        if (! $user->hasPermission('task.view')) {
            return collect();
        }

        return WorkflowTask::query()->where('status', TaskStatus::Pending)->actionableBy($user)->orderBy('due_at')->limit(50)->get()
            ->map(fn (WorkflowTask $t) => new ExperienceTask('workflow', $t->type === 'approval' ? 'approval' : 'task', $t->title, 'Task #'.$t->id, $t->due_at, TaskInbox::getUrl()));
    }
}

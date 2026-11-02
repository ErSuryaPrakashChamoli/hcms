<?php

namespace App\Domain\Exit\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\ExitClearance;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne parity WO-11 (§23.11 #10–11, Inbox Insights "Clearance task" and "Final clearance"): "My tasks" items for the
 * separation desk, only with `exit.clearance.my_tasks` on (off by default: clearance stages are found on the exit case and
 * the Clearances register, as before).
 *
 * - Clearance stages to clear: open stages of exits in clearance that the person owns (owner user, owner role, or a P4
 *   delegation covering exit clearance), for holders of exit.clear or exit.manage; due on the stage's due date.
 * - Exits ready to complete: every stage cleared or not applicable, for HR (exit.manage); due on the last working day.
 *
 * Resignation withdrawals waiting for approval are Approval Center items (ExitApprovalSource), not tasks.
 */
final class ClearanceTaskSource implements ExperienceTaskSource
{
    private const LIMIT = 50;

    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        if (! (bool) app(SettingsRepository::class)->get('exit.clearance.my_tasks', false)) {
            return collect();
        }
        $tasks = collect();
        if ($user->hasPermission('exit.clear') || $user->hasPermission('exit.manage')) {
            $owners = ClearanceOwners::for($user);
            ExitClearance::query()->with(['exitCase.employee.person'])
                ->whereIn('status', ['pending', 'blocked'])
                ->whereHas('exitCase', fn (Builder $c) => $c->where('status', 'clearance')->when($employee !== null, fn (Builder $q) => $q->where('employee_id', '!=', $employee->id)))
                ->where(fn (Builder $q) => $q->whereIn('owner_user_id', $owners['users'])->when($owners['roles'] !== [], fn (Builder $r) => $r->orWhereIn('owner_role_id', $owners['roles'])))
                ->orderByRaw('due_on is null')->orderBy('due_on')->orderBy('id')->limit(self::LIMIT)->get()
                ->filter(fn (ExitClearance $stage) => $stage->exitCase !== null && $stage->isOwnedBy($user))
                ->each(fn (ExitClearance $stage) => $tasks->push(new ExperienceTask(
                    'exit', 'clearance',
                    $stage->name.' for '.($stage->exitCase->employee?->person?->full_name ?? $stage->exitCase->employee?->employee_code ?? 'a leaver').($stage->status === 'blocked' ? ' (blocked)' : ''),
                    $stage->exitCase->number,
                    $stage->due_on ?? $stage->exitCase->last_working_day,
                    $this->url($stage->exitCase),
                    ['employee_id' => $stage->exitCase->employee_id, 'stage' => $stage->stage],
                )));
        }
        if ($user->hasPermission('exit.manage')) {
            ExitCase::query()->with(['employee.person'])->whereIn('status', ['clearance', 'settlement'])
                ->whereHas('clearances')->whereDoesntHave('clearances', fn (Builder $c) => $c->whereIn('status', ['pending', 'blocked']))
                ->when($employee !== null, fn (Builder $q) => $q->where('employee_id', '!=', $employee->id))
                ->orderBy('last_working_day')->orderBy('id')->limit(self::LIMIT)->get()
                ->each(fn (ExitCase $case) => $tasks->push(new ExperienceTask(
                    'exit', 'final_clearance',
                    'Complete the exit of '.($case->employee?->person?->full_name ?? $case->employee?->employee_code ?? 'a leaver'),
                    $case->number,
                    $case->last_working_day,
                    $this->url($case),
                    ['employee_id' => $case->employee_id],
                )));
        }

        return $tasks;
    }

    private function url(ExitCase $case): ?string
    {
        try {
            return ExitCaseResource::getUrl('view', ['record' => $case]);
        } catch (Throwable) {
            return null;
        }
    }
}

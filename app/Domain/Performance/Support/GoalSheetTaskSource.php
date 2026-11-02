<?php

namespace App\Domain\Performance\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\GoalSheetRules;
use App\Domain\Performance\Services\GoalSheets;
use App\Domain\Performance\Services\PerformancePolicyRules;
use App\Domain\Performance\Services\PerformanceWindows;
use App\Filament\Resources\Goals\GoalResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * HROne parity GO-9 (matrix 27 §27.11 #8 and §27.2.1 #21, HROne "Create goal"; matrix 20 R.3.3 #4): a "My tasks" item while
 * a goal sheet still has to be created, finished or changed after it was sent back — the employee's sheet of a running
 * cycle at its goal-setting stage (or with goal setting extended for them, GO-2) whose status (GoalSheets) is not created,
 * draft or sent back. It goes to whoever fills the sheet in: the employee, or — when the goal sheet policy maps sheets by
 * the system and names the reporting manager as the one who fills them in first — the appraisal's manager, who must also
 * be allowed to see the employee's goals (the goal rules). It is shown only while that person may still change the goals
 * (Goals::assertGoalPeriodOpen), due when goal setting ends for the employee (the stage, or the performance policy's goal
 * window, as extended).
 */
final class GoalSheetTaskSource implements ExperienceTaskSource
{
    public function __construct(
        private readonly GoalSheets $sheets,
        private readonly GoalSheetRules $rules,
        private readonly PerformanceWindows $windows,
    ) {}

    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        if ($employee === null) {
            return collect();
        }
        $rows = $this->sheets->withSheet(Appraisal::query()->withoutGlobalScope(AccessScope::class))
            ->with(['cycle', 'employee' => fn ($e) => $e->withoutGlobalScope(AccessScope::class)->with('person')])
            ->whereNull('appraisals.locked_at')->whereNotIn('appraisals.status', ['finalized', 'acknowledged', 'withdrawn'])
            ->whereHas('cycle', fn ($c) => $c->where('status', 'active'))
            ->where(fn ($q) => $q->where('appraisals.employee_id', $employee->id)->orWhere('appraisals.manager_id', $employee->id))
            ->orderBy('appraisals.id')->limit(500)->get();

        $tasks = collect();
        foreach ($rows as $row) {
            try {
                $task = $this->taskFor($row, $user, $employee);
                if ($task !== null) {
                    $tasks->push($task);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $tasks;
    }

    private function taskFor(Appraisal $row, User $user, Employee $me): ?ExperienceTask
    {
        $cycle = $row->cycle;
        $owner = $row->employee;
        if ($cycle === null || $owner === null || ! $cycle->hasStage('goal_setting')) {
            return null;
        }
        $extended = $this->windows->extensionEnd($cycle, 'goal_setting', (int) $owner->id);
        $inGoalSetting = $cycle->current_stage === 'goal_setting' || ($cycle->isAtOrPast('goal_setting') && $extended !== null && $extended->gte(today()));
        $status = GoalSheets::statusFrom($row);
        if (! $inGoalSetting || ! in_array($status, ['not_created', 'draft', 'sent_back'], true)) {
            return null;
        }
        // Who fills the sheet in: the employee, or the reporting manager when the system maps it and the policy says so.
        $creation = $this->rules->creation($owner);
        $byManager = $creation['mode'] === 'system' && $creation['owner'] === 'manager';
        $own = (int) $owner->id === (int) $me->id;
        if ($byManager ? ($own || (int) $row->manager_id !== (int) $me->id || ! GoalSheets::mayViewGoalsOf($user, $owner)) : ! $own) {
            return null;
        }
        // Only while the person may still change the goals (the goal-setting period and the policy's goal window).
        try {
            app(Goals::class)->assertGoalPeriodOpen($cycle->id, $user, $owner->id);
        } catch (RuntimeException) {
            return null;
        }
        $name = $owner->person?->full_name ?? $owner->employee_code;
        $title = match ($status) {
            'not_created' => $own ? "Create your goals for {$cycle->name}" : "Create the goal sheet of {$name} ({$cycle->name})",
            'draft' => $own ? "Finish and submit your goal sheet for {$cycle->name}" : "Finish the goal sheet of {$name} ({$cycle->name})",
            default => $own ? "Your goal sheet for {$cycle->name} was sent back: change and submit it" : "The goal sheet of {$name} was sent back ({$cycle->name})",
        };

        return new ExperienceTask('performance', 'create_goal', $title, $cycle->name, $this->due($row, $owner)?->copy()->endOfDay(), $this->url(),
            ['employee_id' => (int) $owner->id, 'requested_at' => $row->created_at]);
    }

    /** When goal setting ends for the employee: the performance policy's goal window when it has one, else the stage; as extended. */
    private function due(Appraisal $row, Employee $owner): ?Carbon
    {
        $window = app(PerformancePolicyRules::class)->goalWindow($owner, $row->cycle);
        $end = $window !== null ? Carbon::parse($window[1])->startOfDay() : PerformanceWindows::baseEnd($row->cycle, 'goal_setting');
        $extended = $this->windows->extensionEnd($row->cycle, 'goal_setting', (int) $owner->id);
        if ($end === null || $extended === null) {
            return $end ?? $extended;
        }

        return $extended->gt($end) ? $extended : $end;
    }

    private function url(): ?string
    {
        try {
            return GoalResource::getUrl('index');
        } catch (Throwable) {
            return null;
        }
    }
}

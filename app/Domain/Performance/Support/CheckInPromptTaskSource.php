<?php

namespace App\Domain\Performance\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Services\PerformanceReminders;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Goals\GoalResource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne parity GO-9 (matrix 27 §27.11 #5–7, HROne "Goal checkin", "Goal checkin : company", "Goal checkin : team"): the
 * scheduled goal check-in prompts of HP-8b (Setup → Performance, performance.checkin.<scope>.interval / .day) as "My
 * tasks" items, to the same people the prompt notification goes to: employee — the person's own active goals; company /
 * team — the open company and business / department and team goals the person created, while they may still update them
 * (performance.manage, as the goal rules ask for organisation goals). A task stays from the latest prompt day until each of
 * those goals has a check-in recorded since that day, or the next prompt day starts a new period; it is due at the end of
 * the period. With the prompts off (the default) there is no task.
 */
final class CheckInPromptTaskSource implements ExperienceTaskSource
{
    private const KINDS = ['employee' => 'goal_check_in', 'company' => 'goal_check_in_company', 'team' => 'goal_check_in_team'];

    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        $tasks = collect();
        foreach (PerformanceReminders::CHECK_IN_SCOPES as $scope => $levels) {
            try {
                $day = self::promptDay($scope, today());
                if ($day === null || ($scope === 'employee' ? $employee === null : ! $user->hasPermission('performance.manage'))) {
                    continue;
                }
                $goals = Goal::query()->withoutGlobalScope(AccessScope::class)->whereIn('level', $levels)
                    ->when($scope === 'employee', fn (Builder $q) => $q->where('employee_id', $employee->id)->where('status', 'active'))
                    ->when($scope !== 'employee', fn (Builder $q) => $q->where('created_by', $user->id)->whereIn('status', ['draft', 'active']))
                    // Goals of a closed cycle take no more check-ins.
                    ->where(fn (Builder $q) => $q->whereNull('performance_cycle_id')->orWhereHas('cycle', fn (Builder $c) => $c->whereNotIn('status', ['closed', 'archived'])))
                    ->whereDoesntHave('checkIns', fn (Builder $c) => $c->where('created_at', '>=', $day->toDateString()))
                    ->count();
                if ($goals === 0) {
                    continue;
                }
                $title = match ($scope) {
                    'company' => "Check in on the company goals you own ({$goals})",
                    'team' => "Check in on the team goals you own ({$goals})",
                    default => "Check in on your goals ({$goals})",
                };
                $tasks->push(new ExperienceTask('performance', self::KINDS[$scope], $title, 'Check-in period from '.$day->format('d M Y'),
                    self::nextPromptDay($scope, $day)?->copy()->subDay()->endOfDay() ?? $day->copy()->endOfDay(), $this->url(), ['requested_at' => $day]));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $tasks;
    }

    /** The latest prompt day of the scope on or before $today (within a month), null when the prompts are off. */
    public static function promptDay(string $scope, CarbonInterface $today): ?Carbon
    {
        if (! in_array(app(SettingsRepository::class)->get("performance.checkin.{$scope}.interval", 'none'), ['weekly', 'monthly'], true)) {
            return null;
        }
        $day = Carbon::parse($today->toDateString())->startOfDay();
        for ($back = 0; $back <= 31; $back++) {
            if (PerformanceReminders::checkInDue($scope, $day)) {
                return $day;
            }
            $day = $day->copy()->subDay();
        }

        return null;
    }

    /** The next prompt day after $day (within a month and a few days), null when none comes. */
    public static function nextPromptDay(string $scope, CarbonInterface $day): ?Carbon
    {
        $next = Carbon::parse($day->toDateString())->startOfDay();
        for ($ahead = 1; $ahead <= 35; $ahead++) {
            $next = $next->copy()->addDay();
            if (PerformanceReminders::checkInDue($scope, $next)) {
                return $next;
            }
        }

        return null;
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

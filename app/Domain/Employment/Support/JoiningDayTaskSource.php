<?php

namespace App\Domain\Employment\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\UpcomingJoiners;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Employees\EmployeeResource;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne parity WO-4 (§23.11 #18, Inbox Insights "Upcoming join"): on the joining day, a "My tasks" item for HR — "X joins
 * today" — to confirm the joining ("Mark as joined"), change the joining date or mark them as not joining. Upcoming joiners
 * (pre-employee, pre-boarding) whose expected joining date is today, or has passed while they are still not marked as
 * joined, for people who move employees through the lifecycle (employee.lifecycle) or run onboarding (onboarding.manage),
 * inside their access scope. Off unless the organisation switches `employee.joining.day_task` on (Setup → Workforce).
 */
final class JoiningDayTaskSource implements ExperienceTaskSource
{
    private const LIMIT = 50;

    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        if (! (bool) app(SettingsRepository::class)->get('employee.joining.day_task', false)
            || ! ($user->hasPermission('employee.lifecycle') || $user->hasPermission('onboarding.manage'))
            || ! $user->hasPermission('employee.view')) {
            return collect();
        }
        $today = now()->startOfDay();
        $query = UpcomingJoiners::query()->withoutGlobalScope(AccessScope::class)->with('person')
            ->whereRaw(UpcomingJoiners::expectedDateSql().' <= ?', [$today->toDateString().' 23:59:59'])
            ->when($employee !== null, fn ($q) => $q->whereKeyNot($employee->id));
        app(AccessScopes::class)->constrainEmployees($query, $user);

        return $query->orderByRaw(UpcomingJoiners::expectedDateSql())->orderBy('employees.id')->limit(self::LIMIT)->get()
            ->map(function (Employee $joiner) use ($today) {
                $date = UpcomingJoiners::expectedDate($joiner) ?? $today;
                $name = $joiner->person?->display_name ?? $joiner->employee_code;

                return new ExperienceTask(
                    'employment', 'joining_day',
                    $date->equalTo($today) ? "{$name} joins today" : "{$name} was expected to join on ".$date->format('d M Y'),
                    $joiner->employee_code,
                    $date,
                    $this->url($joiner),
                    ['employee_id' => $joiner->id],
                );
            });
    }

    private function url(Employee $joiner): ?string
    {
        try {
            return EmployeeResource::getUrl('view', ['record' => $joiner]);
        } catch (Throwable) {
            return null;
        }
    }
}

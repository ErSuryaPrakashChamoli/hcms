<?php

namespace App\Domain\Performance\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;

/**
 * Phase 7: the one place that answers "whose performance does this manager manage?". It uses the
 * reporting-relationship architecture (primary, functional, dotted, matrix…) filtered to the types
 * configured in `peopleos.performance.manager_relationship_types`, effective today — never a
 * `manager_id = me` shortcut, and never mentors, buddies or project leads unless configured.
 */
final class PerformanceRelationships
{
    /** @return list<string> */
    public function managerTypes(): array
    {
        return config('peopleos.performance.manager_relationship_types', ['line']);
    }

    /** @return Collection<int, int> employee ids */
    public function reportIds(?Employee $manager): Collection
    {
        if ($manager === null) {
            return collect();
        }

        return ReportingRelationship::query()->where('manager_id', $manager->id)->whereIn('type', $this->managerTypes())
            ->currentlyEffective()->pluck('employee_id')->unique()->values();
    }

    public function manages(?Employee $manager, int|string|null $employeeId): bool
    {
        return $manager !== null && $employeeId !== null
            && ReportingRelationship::query()->where('manager_id', $manager->id)->where('employee_id', $employeeId)->whereIn('type', $this->managerTypes())->currentlyEffective()->exists();
    }

    public function forUser(User $user): ?Employee
    {
        return Employee::query()->where('user_id', $user->id)->first();
    }

    /** Report ids for a user holding `performance.team`; empty otherwise. */
    public function teamOf(User $user): Collection
    {
        return $user->hasPermission('performance.team') ? $this->reportIds($this->forUser($user)) : collect();
    }
}

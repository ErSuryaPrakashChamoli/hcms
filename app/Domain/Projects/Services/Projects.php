<?php

namespace App\Domain\Projects\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectMember;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Data import DI-1 (Project Management): the people on a project. The project screen's Members tab and the projects
 * upload both add members here, so an employee is on a project once at a time (a membership that has not ended), and
 * every change is audited on the member row. Projects themselves are saved as records (their checks live on the model).
 */
final class Projects
{
    /** Who maintains customers, projects and their members. */
    public const PERMISSION = 'project.manage';

    /** Decision D4: people in these states (left, or never joined) are not put on a project or made its manager. */
    public const GONE = [LifecycleState::Exited, LifecycleState::Alumni, LifecycleState::NotJoined];

    /** @return list<string> */
    public static function goneStates(): array
    {
        return array_map(fn (LifecycleState $s) => $s->value, self::GONE);
    }

    /** Whether the employee has left or never joined. */
    public static function isGone(int $employeeId): bool
    {
        return Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employeeId)->whereIn('lifecycle_state', self::goneStates())->exists();
    }

    /**
     * Put an employee on a project.
     *
     * @param  array{role?: ?string, allocation_percent?: float|string|null, from_date?: ?string, to_date?: ?string}  $data
     */
    public function addMember(Project $project, Employee $employee, array $data = [], ?string $reason = null): ProjectMember
    {
        if (self::isGone((int) $employee->getKey())) {
            throw new RuntimeException("{$employee->employee_code} has left or never joined: only current or joining employees are put on a project.");
        }
        if ($this->isMember($project, (int) $employee->getKey())) {
            throw new RuntimeException("{$employee->employee_code} is already on project {$project->code}.");
        }
        $member = new ProjectMember([
            'project_id' => $project->getKey(),
            'employee_id' => $employee->getKey(),
            'role' => filled($data['role'] ?? null) ? trim((string) $data['role']) : null,
            'allocation_percent' => filled($data['allocation_percent'] ?? null) ? (float) $data['allocation_percent'] : null,
            'from_date' => filled($data['from_date'] ?? null) ? Carbon::parse($data['from_date'])->toDateString() : null,
            'to_date' => filled($data['to_date'] ?? null) ? Carbon::parse($data['to_date'])->toDateString() : null,
        ]);
        $member->withAuditReason($reason)->save();

        return $member;
    }

    /** Take an employee off a project from a date (the membership ends that day; history is kept). */
    public function endMember(ProjectMember $member, string $date, ?string $reason = null): ProjectMember
    {
        $member->withAuditReason($reason)->update(['to_date' => Carbon::parse($date)->toDateString()]);

        return $member;
    }

    /** Whether the employee is on the project now or later (a membership that has not ended). */
    public function isMember(Project $project, int $employeeId): bool
    {
        return ProjectMember::query()->withoutGlobalScope(AccessScope::class)
            ->where('project_id', $project->getKey())->where('employee_id', $employeeId)
            ->where(fn ($q) => $q->whereNull('to_date')->orWhereDate('to_date', '>=', now()->toDateString()))
            ->exists();
    }
}

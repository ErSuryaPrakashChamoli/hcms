<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * "What will this change affect?" (§73). Cheap, deterministic counts from live data; each
 * subject class answers for itself, unknown classes answer "no direct employee impact".
 */
final class ImpactPreview
{
    public function __construct(private readonly PolicyResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{employees_affected: int, summary: string, details: array<string, mixed>}
     */
    public function for(Model $subject, array $payload = []): array
    {
        return match (true) {
            $subject instanceof PolicyAssignmentRule => $this->rule($subject, $payload),
            $subject instanceof Policy => $this->policy($subject),
            $subject instanceof PolicyVersion => $this->policy($subject->policy),
            $subject instanceof Role => $this->role($subject),
            $this->isPositionDimension($subject) => $this->dimension($subject),
            default => ['employees_affected' => 0, 'summary' => 'No direct employee impact.', 'details' => []],
        };
    }

    private function rule(PolicyAssignmentRule $rule, array $payload): array
    {
        $candidate = $rule->replicate()->forceFill(array_intersect_key($payload, array_flip(['conditions', 'match', 'policy_type'])));
        $employees = $this->resolver->employeesMatching($candidate);

        return [
            'employees_affected' => $employees->count(),
            'summary' => sprintf('%d employee(s) match these conditions.', $employees->count()),
            'details' => ['departments' => $this->departmentsOf($employees)],
        ];
    }

    private function policy(Policy $policy): array
    {
        $employees = $policy->assignmentRules()->where('status', 'active')->get()
            ->flatMap(fn (PolicyAssignmentRule $r) => $this->resolver->employeesMatching($r))
            ->unique('id')->values();

        return [
            'employees_affected' => $employees->count(),
            'summary' => sprintf('%d employee(s) are assigned this policy through %d rule(s).', $employees->count(), $policy->assignmentRules()->count()),
            'details' => ['departments' => $this->departmentsOf($employees)],
        ];
    }

    private function role(Role $role): array
    {
        $users = $role->users()->count();

        return ['employees_affected' => $users, 'summary' => "{$users} user(s) hold this role.", 'details' => []];
    }

    private function dimension(Model $subject): array
    {
        $column = array_search(class_basename($subject::class), array_map(fn (string $c) => class_basename($c), $this->dimensionModels()), true);
        $count = EmployeePosition::query()->effectiveOn()->where($column, $subject->getKey())->distinct('employee_id')->count('employee_id');

        return [
            'employees_affected' => $count,
            'summary' => "{$count} employee(s) currently sit in this ".strtolower(str_replace('_', ' ', class_basename($subject::class))).'.',
            'details' => [],
        ];
    }

    private function isPositionDimension(Model $subject): bool
    {
        return in_array($subject::class, $this->dimensionModels(), true);
    }

    /** @return array<string, class-string<Model>> column => model */
    private function dimensionModels(): array
    {
        $models = [];

        foreach (EmployeePosition::DIMENSIONS as $column => [$relation]) {
            $models[$column] = (new EmployeePosition)->{$relation}()->getRelated()::class;
        }

        return $models;
    }

    /** @return array<string, int> */
    private function departmentsOf(Collection $employees): array
    {
        return $employees
            ->map(fn (Employee $e) => $e->currentPosition?->department?->name ?? '—')
            ->countBy()
            ->sortDesc()
            ->all();
    }
}

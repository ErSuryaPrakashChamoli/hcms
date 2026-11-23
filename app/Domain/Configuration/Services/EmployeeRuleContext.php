<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Department;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Flattens an employee (as of a date) into the fields the rule engine understands. */
final class EmployeeRuleContext
{
    /** @return array<string, mixed> */
    public function build(Employee $employee, CarbonInterface|string|null $on = null): array
    {
        $date = Carbon::parse($on ?? now())->startOfDay();
        $position = $employee->positions()->effectiveOn($date)->first();

        $context = [
            'employee_id' => $employee->id,
            'lifecycle_state' => $employee->lifecycle_state->value,
            'gender' => $employee->person?->gender,
            'tenure_months' => $employee->joining_date ? (int) $employee->joining_date->diffInMonths($date) : null,
        ];

        foreach (array_keys(EmployeePosition::DIMENSIONS) as $column) {
            $context[$column] = $position?->getAttribute($column);
        }
        // HROne setup parity CA-2: policies can target the parent of the employee's department (sub-departments).
        $departmentId = $position?->getAttribute('department_id');
        $parent = $departmentId === null ? null : Department::query()->withoutGlobalScope(AccessScope::class)->whereKey($departmentId)->value('parent_department_id');
        $context['parent_department_id'] = $parent === null ? null : (int) $parent;

        return $context;
    }

    /**
     * build() for many employees at once: the same values, from one query for their positions and one for their
     * departments' parents (configuration cloning counts who a copy reaches in a whole company).
     *
     * @param  iterable<Employee>  $employees  with their person loaded
     * @return array<int, array<string, mixed>> employee id => context
     */
    public function buildMany(iterable $employees, CarbonInterface|string|null $on = null): array
    {
        $date = Carbon::parse($on ?? now())->startOfDay();
        $employees = collect($employees);
        $positions = EmployeePosition::query()->whereIn('employee_id', $employees->map(fn (Employee $e) => $e->getKey())->all())->effectiveOn($date)
            ->orderByDesc('effective_from')->orderByDesc('id')->get()->groupBy('employee_id')->map(fn ($rows) => $rows->first());
        $departments = $positions->pluck('department_id')->filter()->unique()->values()->all();
        $parents = $departments === [] ? collect() : Department::query()->withoutGlobalScope(AccessScope::class)->whereKey($departments)->pluck('parent_department_id', 'id');

        $contexts = [];
        foreach ($employees as $employee) {
            $position = $positions->get($employee->id);
            $context = [
                'employee_id' => $employee->id,
                'lifecycle_state' => $employee->lifecycle_state->value,
                'gender' => $employee->person?->gender,
                'tenure_months' => $employee->joining_date ? (int) $employee->joining_date->diffInMonths($date) : null,
            ];
            foreach (array_keys(EmployeePosition::DIMENSIONS) as $column) {
                $context[$column] = $position?->getAttribute($column);
            }
            $departmentId = $position?->getAttribute('department_id');
            $parent = $departmentId === null ? null : ($parents[$departmentId] ?? null);
            $context['parent_department_id'] = $parent === null ? null : (int) $parent;
            $contexts[$employee->id] = $context;
        }

        return $contexts;
    }
}

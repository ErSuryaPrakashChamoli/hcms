<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
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

        return $context;
    }
}

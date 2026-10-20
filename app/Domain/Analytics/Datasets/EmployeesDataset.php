<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

class EmployeesDataset extends Dataset
{
    /** @var array<int, float>|null annual CTC by employee, loaded once per dataset instance */
    private ?array $ctc = null;

    public function key(): string
    {
        return 'employees';
    }

    public function label(): string
    {
        return 'Employees (headcount, demographics, tenure, cost)';
    }

    public function permissions(): array
    {
        return ['employee.view'];
    }

    public function sensitivePermission(): ?string
    {
        return 'employee.sensitive.view';
    }

    public function fields(): array
    {
        $pos = fn ($e) => $e->currentPosition;

        return [
            'employee_code' => ['label' => 'Employee code', 'type' => 'string', 'value' => fn ($e) => $e->employee_code],
            'name' => ['label' => 'Name', 'type' => 'string', 'value' => fn ($e) => $e->person?->full_name],
            'gender' => ['label' => 'Gender', 'type' => 'string', 'value' => fn ($e) => $e->person?->gender],
            'age' => ['label' => 'Age', 'type' => 'number', 'value' => fn ($e) => $e->person?->date_of_birth ? (int) $e->person->date_of_birth->diffInYears(now()) : null],
            'lifecycle_state' => ['label' => 'Lifecycle state', 'type' => 'string', 'value' => fn ($e) => $e->lifecycle_state],
            'joining_date' => ['label' => 'Joining date', 'type' => 'date', 'value' => fn ($e) => $e->joining_date],
            'exit_date' => ['label' => 'Exit date', 'type' => 'date', 'value' => fn ($e) => $e->exit_date],
            'tenure_months' => ['label' => 'Tenure (months)', 'type' => 'number', 'value' => fn ($e) => $e->joining_date ? (int) $e->joining_date->diffInMonths($e->exit_date ?? now()) : null],
            'company' => ['label' => 'Company', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->company?->name],
            'location' => ['label' => 'Location', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->location?->name],
            'department' => ['label' => 'Department', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->department?->name],
            'designation' => ['label' => 'Designation', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->designation?->name],
            'level' => ['label' => 'Level', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->level?->name],
            'grade' => ['label' => 'Grade', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->grade?->name],
            'employment_type' => ['label' => 'Employment type', 'type' => 'string', 'value' => fn ($e) => $pos($e)?->employmentType?->name],
            'manager' => ['label' => 'Manager', 'type' => 'string', 'value' => fn ($e) => $e->currentManager?->manager?->person?->full_name],
            // Phase 11: approved compensation through the Compensation read contract (one query per report).
            'ctc_annual' => ['label' => 'Annual CTC', 'type' => 'number', 'sensitive' => true, 'permission' => 'compensation.view', 'value' => fn ($e) => ($this->ctc ??= app(CompensationOutput::class)->annualCtcOn())[$e->id] ?? null],
            'headcount' => ['label' => 'Headcount (1 per row)', 'type' => 'number', 'value' => fn ($e) => 1],
        ];
    }

    public function query(): Builder
    {
        return Employee::query()->with(['person', 'currentPosition.company', 'currentPosition.location', 'currentPosition.department', 'currentPosition.designation', 'currentPosition.level', 'currentPosition.grade', 'currentPosition.employmentType', 'currentManager.manager.person'])->orderBy('employee_code');
    }
}

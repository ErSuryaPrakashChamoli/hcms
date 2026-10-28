<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Employment\Models\EmployeePosition;
use Illuminate\Database\Eloquent\Builder;

/** Position history: every hire, transfer, promotion, demotion and reassignment with the position it replaced. */
class EmployeeMovementsDataset extends Dataset
{
    public function key(): string
    {
        return 'employee_movements';
    }

    public function label(): string
    {
        return 'Transfers, promotions and position history';
    }

    public function category(): string
    {
        return 'People';
    }

    public function permissions(): array
    {
        return ['employee.view'];
    }

    public function fields(): array
    {
        $previous = fn (EmployeePosition $p) => $p->employee?->positions
            ->filter(fn (EmployeePosition $other) => $other->id !== $p->id && $other->effective_from?->lt($p->effective_from))
            ->sortByDesc('effective_from')->first();

        return [
            'employee_code' => ['label' => 'Employee code', 'type' => 'string', 'value' => fn ($p) => $p->employee?->employee_code],
            'name' => ['label' => 'Name', 'type' => 'string', 'value' => fn ($p) => $p->employee?->person?->full_name],
            'employee_status' => ['label' => 'Employee status', 'type' => 'string', 'value' => fn ($p) => $p->employee?->lifecycle_state],
            'change_type' => ['label' => 'Change', 'type' => 'string', 'value' => fn ($p) => $p->change_type],
            'effective_from' => ['label' => 'Effective from', 'type' => 'date', 'value' => fn ($p) => $p->effective_from],
            'effective_to' => ['label' => 'Effective to', 'type' => 'date', 'value' => fn ($p) => $p->effective_to],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($p) => $p->effective_from?->format('Y-m')],
            'is_current' => ['label' => 'Current position', 'type' => 'boolean', 'value' => fn ($p) => $p->effective_from?->lte(today()) && ($p->effective_to === null || $p->effective_to->gte(today()))],
            'company' => ['label' => 'Company', 'type' => 'string', 'value' => fn ($p) => $p->company?->name],
            'location' => ['label' => 'Location', 'type' => 'string', 'value' => fn ($p) => $p->location?->name],
            'department' => ['label' => 'Department', 'type' => 'string', 'value' => fn ($p) => $p->department?->name],
            'designation' => ['label' => 'Designation', 'type' => 'string', 'value' => fn ($p) => $p->designation?->name],
            'grade' => ['label' => 'Grade', 'type' => 'string', 'value' => fn ($p) => $p->grade?->name],
            'level' => ['label' => 'Level', 'type' => 'string', 'value' => fn ($p) => $p->level?->name],
            'employment_type' => ['label' => 'Employment type', 'type' => 'string', 'value' => fn ($p) => $p->employmentType?->name],
            'previous_location' => ['label' => 'Previous location', 'type' => 'string', 'value' => fn ($p) => $previous($p)?->location?->name],
            'previous_department' => ['label' => 'Previous department', 'type' => 'string', 'value' => fn ($p) => $previous($p)?->department?->name],
            'previous_designation' => ['label' => 'Previous designation', 'type' => 'string', 'value' => fn ($p) => $previous($p)?->designation?->name],
            'previous_grade' => ['label' => 'Previous grade', 'type' => 'string', 'value' => fn ($p) => $previous($p)?->grade?->name],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn ($p) => $p->reason],
            'count' => ['label' => 'Changes (1 per row)', 'type' => 'number', 'value' => fn ($p) => 1],
        ];
    }

    public function query(): Builder
    {
        return EmployeePosition::query()->with([
            'employee.person', 'company', 'location', 'department', 'designation', 'grade', 'level', 'employmentType',
            'employee.positions.location', 'employee.positions.department', 'employee.positions.designation', 'employee.positions.grade',
        ])->orderByDesc('effective_from');
    }
}

<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Employment\Models\ReportingRelationship;
use Illuminate\Database\Eloquent\Builder;

class ManagerChangesDataset extends Dataset
{
    public function key(): string
    {
        return 'manager_changes';
    }

    public function label(): string
    {
        return 'Reporting manager history';
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
        return [
            ...$this->employeeFields(fn ($r) => $r->employee),
            'manager_code' => ['label' => 'Manager code', 'type' => 'string', 'value' => fn ($r) => $r->manager?->employee_code],
            'manager' => ['label' => 'Manager', 'type' => 'string', 'value' => fn ($r) => $r->manager?->person?->full_name],
            'type' => ['label' => 'Relationship', 'type' => 'string', 'value' => fn ($r) => $r->type],
            'is_primary' => ['label' => 'Primary', 'type' => 'boolean', 'value' => fn ($r) => (bool) $r->is_primary],
            'effective_from' => ['label' => 'From', 'type' => 'date', 'value' => fn ($r) => $r->effective_from],
            'effective_to' => ['label' => 'To', 'type' => 'date', 'value' => fn ($r) => $r->effective_to],
            'is_current' => ['label' => 'Current', 'type' => 'boolean', 'value' => fn ($r) => $r->effective_from?->lte(today()) && ($r->effective_to === null || $r->effective_to->gte(today()))],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn ($r) => $r->reason],
            'count' => ['label' => 'Rows (1 per row)', 'type' => 'number', 'value' => fn ($r) => 1],
        ];
    }

    public function query(): Builder
    {
        return ReportingRelationship::query()->with([...self::employeeWith('employee'), 'manager.person'])->orderByDesc('effective_from');
    }
}

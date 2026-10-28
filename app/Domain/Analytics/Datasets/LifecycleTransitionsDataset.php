<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Lifecycle\Models\EmployeeLifecycleTransition;
use Illuminate\Database\Eloquent\Builder;

class LifecycleTransitionsDataset extends Dataset
{
    public function key(): string
    {
        return 'lifecycle_transitions';
    }

    public function label(): string
    {
        return 'Lifecycle changes (joining, probation, confirmation, notice, exit)';
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
        $state = fn ($value) => $value instanceof \BackedEnum ? $value->value : $value;

        return [
            ...$this->employeeFields(fn ($t) => $t->employee),
            'from_state' => ['label' => 'From', 'type' => 'string', 'value' => fn ($t) => $state($t->from_state)],
            'to_state' => ['label' => 'To', 'type' => 'string', 'value' => fn ($t) => $state($t->to_state)],
            'effective_date' => ['label' => 'Effective date', 'type' => 'date', 'value' => fn ($t) => $t->effective_date],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($t) => $t->effective_date?->format('Y-m')],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn ($t) => $t->reason],
            'changed_by' => ['label' => 'Changed by', 'type' => 'string', 'value' => fn ($t) => $t->actor?->name ?? 'System'],
            'count' => ['label' => 'Changes (1 per row)', 'type' => 'number', 'value' => fn ($t) => 1],
        ];
    }

    public function query(): Builder
    {
        return EmployeeLifecycleTransition::query()->with([...self::employeeWith('employee'), 'actor'])->orderByDesc('effective_date')->orderByDesc('id');
    }
}

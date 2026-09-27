<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Exit\Models\ExitCase;
use Illuminate\Database\Eloquent\Builder;

class ExitsDataset extends Dataset
{
    public function key(): string
    {
        return 'exits';
    }

    public function label(): string
    {
        return 'Exits and attrition';
    }

    public function permissions(): array
    {
        return ['exit.view'];
    }

    public function fields(): array
    {
        return [
            'number' => ['label' => 'Case', 'type' => 'string', 'value' => fn ($c) => $c->number],
            'employee_code' => ['label' => 'Employee code', 'type' => 'string', 'value' => fn ($c) => $c->employee?->employee_code],
            'name' => ['label' => 'Name', 'type' => 'string', 'value' => fn ($c) => $c->employee?->person?->full_name],
            'department' => ['label' => 'Department', 'type' => 'string', 'value' => fn ($c) => $c->employee?->positions?->sortByDesc('effective_from')->first()?->department?->name],
            'type' => ['label' => 'Exit type', 'type' => 'string', 'value' => fn ($c) => $c->type],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($c) => $c->status],
            'initiated_on' => ['label' => 'Initiated on', 'type' => 'date', 'value' => fn ($c) => $c->initiated_on],
            'last_working_day' => ['label' => 'Last working day', 'type' => 'date', 'value' => fn ($c) => $c->last_working_day],
            'month' => ['label' => 'Exit month', 'type' => 'string', 'value' => fn ($c) => $c->last_working_day?->format('Y-m')],
            'tenure_months' => ['label' => 'Tenure at exit (months)', 'type' => 'number', 'value' => fn ($c) => $c->employee?->joining_date ? (int) $c->employee->joining_date->diffInMonths($c->last_working_day) : null],
            'reason_for_leaving' => ['label' => 'Reason (exit interview)', 'type' => 'string', 'value' => fn ($c) => $c->interview?->reason_for_leaving],
            'rehire_eligible' => ['label' => 'Rehire eligible', 'type' => 'boolean', 'value' => fn ($c) => (bool) $c->is_rehire_eligible],
            'net_settlement' => ['label' => 'Net F&F', 'type' => 'number', 'value' => fn ($c) => $c->settlement ? (float) $c->settlement->net_amount : null],
            'count' => ['label' => 'Exits (1 per row)', 'type' => 'number', 'value' => fn ($c) => 1],
        ];
    }

    public function query(): Builder
    {
        return ExitCase::query()->with(['employee.person', 'employee.positions.department', 'interview', 'settlement'])->orderByDesc('last_working_day');
    }
}

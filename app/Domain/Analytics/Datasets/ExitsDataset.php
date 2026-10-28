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
        return 'Exits and attrition (resignations, notice, last working day)';
    }

    public function category(): string
    {
        return 'Joining & exit';
    }

    public function permissions(): array
    {
        return ['exit.view'];
    }

    public function fields(): array
    {
        $position = fn ($c) => $c->employee?->positions?->sortByDesc('effective_from')->first();

        return [
            'number' => ['label' => 'Case', 'type' => 'string', 'value' => fn ($c) => $c->number],
            'employee_code' => ['label' => 'Employee code', 'type' => 'string', 'value' => fn ($c) => $c->employee?->employee_code],
            'name' => ['label' => 'Name', 'type' => 'string', 'value' => fn ($c) => $c->employee?->person?->full_name],
            'department' => ['label' => 'Department', 'type' => 'string', 'value' => fn ($c) => $position($c)?->department?->name],
            'designation' => ['label' => 'Designation', 'type' => 'string', 'value' => fn ($c) => $position($c)?->designation?->name],
            'location' => ['label' => 'Location', 'type' => 'string', 'value' => fn ($c) => $position($c)?->location?->name],
            'manager' => ['label' => 'Manager', 'type' => 'string', 'value' => fn ($c) => $c->manager?->person?->full_name],
            'joining_date' => ['label' => 'Joining date', 'type' => 'date', 'value' => fn ($c) => $c->employee?->joining_date],
            'type' => ['label' => 'Exit type', 'type' => 'string', 'value' => fn ($c) => $c->type],
            'is_voluntary' => ['label' => 'Voluntary (resignation)', 'type' => 'boolean', 'value' => fn ($c) => $c->type === 'resignation'],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($c) => $c->status],
            'initiated_on' => ['label' => 'Initiated on', 'type' => 'date', 'value' => fn ($c) => $c->initiated_on],
            'resignation_date' => ['label' => 'Resignation date', 'type' => 'date', 'value' => fn ($c) => $c->resignation_date],
            'notice_days' => ['label' => 'Notice period (days)', 'type' => 'number', 'value' => fn ($c) => $c->notice_days !== null ? (int) $c->notice_days : null],
            'notice_end_date' => ['label' => 'Notice ends', 'type' => 'date', 'value' => fn ($c) => $c->notice_end_date],
            'last_working_day' => ['label' => 'Last working day', 'type' => 'date', 'value' => fn ($c) => $c->last_working_day],
            'days_to_last_day' => ['label' => 'Days to last working day', 'type' => 'number', 'value' => fn ($c) => self::daysFromToday($c->last_working_day)],
            'month' => ['label' => 'Exit month', 'type' => 'string', 'value' => fn ($c) => $c->last_working_day?->format('Y-m')],
            'tenure_months' => ['label' => 'Tenure at exit (months)', 'type' => 'number', 'value' => fn ($c) => $c->employee?->joining_date ? (int) $c->employee->joining_date->diffInMonths($c->last_working_day) : null],
            'reason_for_leaving' => ['label' => 'Reason (exit interview)', 'type' => 'string', 'value' => fn ($c) => $c->interview?->reason_for_leaving],
            'rehire_eligible' => ['label' => 'Rehire eligible', 'type' => 'boolean', 'value' => fn ($c) => (bool) $c->is_rehire_eligible],
            'pending_clearances' => ['label' => 'Clearances pending', 'type' => 'number', 'value' => fn ($c) => $c->clearances->whereIn('status', ['pending', 'blocked'])->count()],
            'settlement_status' => ['label' => 'F&F status', 'type' => 'string', 'value' => fn ($c) => $c->settlement?->status],
            'net_settlement' => ['label' => 'Net F&F', 'type' => 'number', 'value' => fn ($c) => $c->settlement ? (float) $c->settlement->net_amount : null],
            'completed_at' => ['label' => 'Completed on', 'type' => 'date', 'value' => fn ($c) => $c->completed_at],
            'count' => ['label' => 'Exits (1 per row)', 'type' => 'number', 'value' => fn ($c) => 1],
        ];
    }

    public function query(): Builder
    {
        return ExitCase::query()->with(['employee.person', 'employee.positions.department', 'employee.positions.designation', 'employee.positions.location', 'manager.person', 'interview', 'settlement', 'clearances'])->orderByDesc('last_working_day');
    }
}

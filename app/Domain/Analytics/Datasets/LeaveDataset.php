<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Leave\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Builder;

class LeaveDataset extends Dataset
{
    public function key(): string
    {
        return 'leave';
    }

    public function label(): string
    {
        return 'Leave requests';
    }

    public function permissions(): array
    {
        return ['leave.view'];
    }

    public function fields(): array
    {
        return [
            'from_date' => ['label' => 'From', 'type' => 'date', 'value' => fn ($r) => $r->from_date],
            'to_date' => ['label' => 'To', 'type' => 'date', 'value' => fn ($r) => $r->to_date],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($r) => $r->from_date?->format('Y-m')],
            'employee_code' => ['label' => 'Employee code', 'type' => 'string', 'value' => fn ($r) => $r->employee?->employee_code],
            'name' => ['label' => 'Name', 'type' => 'string', 'value' => fn ($r) => $r->employee?->person?->full_name],
            'department' => ['label' => 'Department', 'type' => 'string', 'value' => fn ($r) => $r->employee?->currentPosition?->department?->name],
            'leave_type' => ['label' => 'Leave type', 'type' => 'string', 'value' => fn ($r) => $r->leaveType?->name],
            'days' => ['label' => 'Days', 'type' => 'number', 'value' => fn ($r) => (float) $r->days],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($r) => $r->status],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn ($r) => $r->reason],
        ];
    }

    public function query(): Builder
    {
        return LeaveRequest::query()->with(['employee.person', 'employee.currentPosition.department', 'leaveType'])->orderByDesc('from_date');
    }
}

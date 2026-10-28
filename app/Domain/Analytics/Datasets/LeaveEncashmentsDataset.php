<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Leave\Models\LeaveEncashment;
use Illuminate\Database\Eloquent\Builder;

class LeaveEncashmentsDataset extends Dataset
{
    public function key(): string
    {
        return 'leave_encashments';
    }

    public function label(): string
    {
        return 'Leave encashments';
    }

    public function category(): string
    {
        return 'Leave';
    }

    public function permissions(): array
    {
        return ['leave.view'];
    }

    public function fields(): array
    {
        return [
            'year' => ['label' => 'Leave year', 'type' => 'number', 'value' => fn ($e) => (int) $e->period_year],
            ...$this->employeeFields(fn ($e) => $e->employee),
            'leave_type' => ['label' => 'Leave type', 'type' => 'string', 'value' => fn ($e) => $e->leaveType?->name],
            'days' => ['label' => 'Days', 'type' => 'number', 'value' => fn ($e) => round((float) $e->days, 2)],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($e) => $e->status],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn ($e) => $e->reason],
            'requested_on' => ['label' => 'Requested on', 'type' => 'date', 'value' => fn ($e) => $e->created_at],
            'reviewed_by' => ['label' => 'Reviewed by', 'type' => 'string', 'value' => fn ($e) => $e->reviewer?->name],
            'reviewed_on' => ['label' => 'Reviewed on', 'type' => 'date', 'value' => fn ($e) => $e->reviewed_at],
        ];
    }

    public function query(): Builder
    {
        return LeaveEncashment::query()->with([...self::employeeWith('employee'), 'leaveType', 'reviewer'])->orderByDesc('id');
    }
}

<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Attendance\Models\TimeOfficeRequest;
use Illuminate\Database\Eloquent\Builder;

/** HROne parity HP-1: short leave, on duty, shift change, overtime and comp-off claims, restricted holidays. */
class TimeOfficeRequestsDataset extends Dataset
{
    public function key(): string
    {
        return 'time_office_requests';
    }

    public function label(): string
    {
        return 'Time-office requests (short leave, on duty, shift change, overtime, comp-off, restricted holiday)';
    }

    public function category(): string
    {
        return 'Attendance';
    }

    public function permissions(): array
    {
        return ['attendance.view'];
    }

    public function fields(): array
    {
        return [
            'from_date' => ['label' => 'From', 'type' => 'date', 'value' => fn ($r) => $r->from_date],
            'to_date' => ['label' => 'To', 'type' => 'date', 'value' => fn ($r) => $r->to_date],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($r) => $r->from_date?->format('Y-m')],
            ...$this->employeeFields(fn ($r) => $r->employee),
            'type' => ['label' => 'Request', 'type' => 'string', 'value' => fn ($r) => $r->typeLabel()],
            'part' => ['label' => 'Part of day', 'type' => 'string', 'value' => fn ($r) => $r->session],
            'start_time' => ['label' => 'From time', 'type' => 'string', 'value' => fn ($r) => $r->start_time ? substr((string) $r->start_time, 0, 5) : null],
            'end_time' => ['label' => 'To time', 'type' => 'string', 'value' => fn ($r) => $r->end_time ? substr((string) $r->end_time, 0, 5) : null],
            'minutes' => ['label' => 'Minutes', 'type' => 'number', 'value' => fn ($r) => $r->minutes],
            'days' => ['label' => 'Days', 'type' => 'number', 'value' => fn ($r) => $r->days !== null ? (float) $r->days : null],
            'shift' => ['label' => 'Shift asked for', 'type' => 'string', 'value' => fn ($r) => $r->shift?->name],
            'holiday' => ['label' => 'Optional holiday', 'type' => 'string', 'value' => fn ($r) => $r->holiday?->name],
            'expires_on' => ['label' => 'Comp-off expires', 'type' => 'date', 'value' => fn ($r) => $r->expires_on],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn ($r) => $r->reason],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($r) => TimeOfficeRequest::STATUSES[$r->status] ?? $r->status],
            'applied_on' => ['label' => 'Applied on', 'type' => 'date', 'value' => fn ($r) => $r->created_at],
            'reviewed_by' => ['label' => 'Decided by', 'type' => 'string', 'value' => fn ($r) => $r->reviewer?->name],
            'reviewed_on' => ['label' => 'Decided on', 'type' => 'date', 'value' => fn ($r) => $r->reviewed_at],
            'cancel_reason' => ['label' => 'Cancellation reason', 'type' => 'string', 'value' => fn ($r) => $r->cancel_reason],
            'pending_days' => ['label' => 'Pending for (days)', 'type' => 'number', 'value' => fn ($r) => $r->status === 'pending' && $r->created_at ? (int) $r->created_at->startOfDay()->diffInDays(now()->startOfDay()) : null],
            'count' => ['label' => 'Requests (1 per row)', 'type' => 'number', 'value' => fn ($r) => 1],
        ];
    }

    public function query(): Builder
    {
        return TimeOfficeRequest::query()->with([...self::employeeWith('employee'), 'reviewer', 'shift', 'holiday'])->orderByDesc('from_date');
    }
}

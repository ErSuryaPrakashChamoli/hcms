<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Attendance\Models\AttendanceRegularisation;
use Illuminate\Database\Eloquent\Builder;

class RegularisationsDataset extends Dataset
{
    public function key(): string
    {
        return 'attendance_regularisations';
    }

    public function label(): string
    {
        return 'Attendance regularisations (missed punch, WFH, on-duty requests)';
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
            'date' => ['label' => 'Attendance date', 'type' => 'date', 'value' => fn ($r) => $r->date],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($r) => $r->date?->format('Y-m')],
            ...$this->employeeFields(fn ($r) => $r->employee),
            'type' => ['label' => 'Type', 'type' => 'string', 'value' => fn ($r) => config('peopleos.attendance.regularisation_types.'.$r->type, $r->type)],
            'requested_in' => ['label' => 'Requested in', 'type' => 'string', 'value' => fn ($r) => $r->requested_in?->format('H:i')],
            'requested_out' => ['label' => 'Requested out', 'type' => 'string', 'value' => fn ($r) => $r->requested_out?->format('H:i')],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn ($r) => $r->reason],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($r) => $r->status],
            'applied_on' => ['label' => 'Applied on', 'type' => 'date', 'value' => fn ($r) => $r->created_at],
            'reviewed_by' => ['label' => 'Reviewed by', 'type' => 'string', 'value' => fn ($r) => $r->reviewer?->name],
            'reviewed_on' => ['label' => 'Reviewed on', 'type' => 'date', 'value' => fn ($r) => $r->reviewed_at],
            'review_note' => ['label' => 'Review note', 'type' => 'string', 'value' => fn ($r) => $r->review_note],
            'pending_days' => ['label' => 'Pending for (days)', 'type' => 'number', 'value' => fn ($r) => $r->status === 'pending' && $r->created_at ? (int) $r->created_at->startOfDay()->diffInDays(now()->startOfDay()) : null],
            'count' => ['label' => 'Requests (1 per row)', 'type' => 'number', 'value' => fn ($r) => 1],
        ];
    }

    public function query(): Builder
    {
        return AttendanceRegularisation::query()->with([...self::employeeWith('employee'), 'reviewer'])->orderByDesc('date');
    }
}

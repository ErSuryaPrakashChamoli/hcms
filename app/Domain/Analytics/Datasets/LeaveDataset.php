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
        return 'Leave requests (applied, approved, pending, cancelled)';
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
            'from_date' => ['label' => 'From', 'type' => 'date', 'value' => fn ($r) => $r->from_date],
            'to_date' => ['label' => 'To', 'type' => 'date', 'value' => fn ($r) => $r->to_date],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($r) => $r->from_date?->format('Y-m')],
            ...$this->employeeFields(fn ($r) => $r->employee),
            'leave_type' => ['label' => 'Leave type', 'type' => 'string', 'value' => fn ($r) => $r->leaveType?->name],
            'leave_code' => ['label' => 'Leave code', 'type' => 'string', 'value' => fn ($r) => $r->leaveType?->code],
            'is_paid' => ['label' => 'Paid leave', 'type' => 'boolean', 'value' => fn ($r) => (bool) $r->leaveType?->is_paid],
            'from_session' => ['label' => 'From session', 'type' => 'string', 'value' => fn ($r) => LeaveRequest::SESSIONS[$r->from_session] ?? $r->from_session],
            'to_session' => ['label' => 'To session', 'type' => 'string', 'value' => fn ($r) => LeaveRequest::SESSIONS[$r->to_session] ?? $r->to_session],
            'days' => ['label' => 'Days', 'type' => 'number', 'value' => fn ($r) => (float) $r->days],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($r) => $r->status],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn ($r) => $r->reason],
            'applied_on' => ['label' => 'Applied on', 'type' => 'date', 'value' => fn ($r) => $r->created_at],
            'reviewed_by' => ['label' => 'Reviewed by', 'type' => 'string', 'value' => fn ($r) => $r->reviewer?->name],
            'reviewed_on' => ['label' => 'Reviewed on', 'type' => 'date', 'value' => fn ($r) => $r->reviewed_at],
            'review_note' => ['label' => 'Review note', 'type' => 'string', 'value' => fn ($r) => $r->review_note],
            'pending_days' => ['label' => 'Pending for (days)', 'type' => 'number', 'value' => fn ($r) => $r->status === 'pending' && $r->created_at ? (int) $r->created_at->startOfDay()->diffInDays(now()->startOfDay()) : null],
            'on_leave_today' => ['label' => 'On leave today', 'type' => 'boolean', 'value' => fn ($r) => in_array($r->status, LeaveRequest::TAKEN, true) && $r->from_date?->lte(today()) && $r->to_date?->gte(today())],
            'is_backdated' => ['label' => 'Applied after the leave started', 'type' => 'boolean', 'value' => fn ($r) => $r->created_at && $r->from_date && $r->created_at->startOfDay()->gt($r->from_date)],
        ];
    }

    public function query(): Builder
    {
        return LeaveRequest::query()->with([...self::employeeWith('employee'), 'leaveType', 'reviewer'])->orderByDesc('from_date');
    }
}

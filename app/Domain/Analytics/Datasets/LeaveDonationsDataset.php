<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Leave\Models\LeaveDonation;
use Illuminate\Database\Eloquent\Builder;

/** HROne parity HP-1: leave donations (donor, recipient, type, days, decision). */
class LeaveDonationsDataset extends Dataset
{
    public function key(): string
    {
        return 'leave_donations';
    }

    public function label(): string
    {
        return 'Leave donations';
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
            ...$this->employeeFields(fn ($r) => $r->employee),
            'recipient' => ['label' => 'Given to', 'type' => 'string', 'value' => fn ($r) => $r->recipient?->display_name],
            'recipient_code' => ['label' => 'Recipient code', 'type' => 'string', 'value' => fn ($r) => $r->recipient?->employee_code],
            'leave_type' => ['label' => 'Leave type', 'type' => 'string', 'value' => fn ($r) => $r->leaveType?->name],
            'year' => ['label' => 'Leave year', 'type' => 'number', 'value' => fn ($r) => $r->period_year],
            'days' => ['label' => 'Days', 'type' => 'number', 'value' => fn ($r) => (float) $r->days],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn ($r) => $r->reason],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($r) => LeaveDonation::STATUSES[$r->status] ?? $r->status],
            'applied_on' => ['label' => 'Applied on', 'type' => 'date', 'value' => fn ($r) => $r->created_at],
            'reviewed_by' => ['label' => 'Decided by', 'type' => 'string', 'value' => fn ($r) => $r->reviewer?->name],
            'count' => ['label' => 'Donations (1 per row)', 'type' => 'number', 'value' => fn ($r) => 1],
        ];
    }

    public function query(): Builder
    {
        return LeaveDonation::query()->with([...self::employeeWith('employee'), 'recipient.person', 'leaveType', 'reviewer'])->orderByDesc('id');
    }
}

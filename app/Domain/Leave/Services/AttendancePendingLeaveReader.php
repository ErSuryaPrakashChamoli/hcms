<?php

namespace App\Domain\Leave\Services;

use App\Domain\Attendance\Contracts\PendingLeaveReader;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Leave\Models\LeaveRequest;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/** HROne parity TX-3: leave-side implementation of the attendance contract for leave still waiting for a decision. */
final class AttendancePendingLeaveReader implements PendingLeaveReader
{
    public function pendingBetween(array $employeeIds, CarbonInterface $from, CarbonInterface $to, bool $scoped = true): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return LeaveRequest::query()->with('leaveType:id,name')
            ->when(! $scoped, fn (Builder $q) => $q->withoutGlobalScope(AccessScope::class))
            ->whereIn('employee_id', $employeeIds)->whereIn('status', ['pending', 'cancel_requested'])
            ->whereDate('from_date', '<=', $to->toDateString())->whereDate('to_date', '>=', $from->toDateString())
            ->orderBy('from_date')->orderBy('id')->get()
            ->map(fn (LeaveRequest $r) => [
                'id' => (int) $r->id, 'employee_id' => (int) $r->employee_id, 'from' => $r->from_date->toDateString(), 'to' => $r->to_date->toDateString(),
                'label' => (string) ($r->leaveType?->name ?? 'Leave'), 'status' => $r->status === 'cancel_requested' ? 'Cancellation requested' : 'Pending',
            ])->values()->all();
    }
}

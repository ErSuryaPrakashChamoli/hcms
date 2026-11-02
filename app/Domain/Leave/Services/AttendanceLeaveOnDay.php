<?php

namespace App\Domain\Leave\Services;

use App\Domain\Attendance\Contracts\LeaveOnDay;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * HROne parity TX-7: leave-side implementation of the attendance day drawer's leave contract. Reads under the viewer's access
 * scope (LeaveRequest is scoped by employee) and the leave policy's `view`; cancels through Leaves::cancel after the policy's
 * `cancel` (HR with leave.manage in scope, or one's own leave) — the same path as "Cancel" on Leave requests.
 */
final class AttendanceLeaveOnDay implements LeaveOnDay
{
    public function onDay(Employee $employee, CarbonInterface $date, ?User $viewer = null): array
    {
        $viewer ??= auth()->user();
        if ($viewer === null) {
            return [];
        }
        $day = $date->toDateString();

        return LeaveRequest::query()->with('leaveType')
            ->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved', 'cancel_requested'])
            ->whereDate('from_date', '<=', $day)->whereDate('to_date', '>=', $day)
            ->orderBy('from_date')->orderBy('id')->get()
            ->filter(fn (LeaveRequest $r) => $viewer->can('view', $r))
            ->map(fn (LeaveRequest $r) => [
                'id' => (int) $r->id,
                'type' => (string) ($r->leaveType?->name ?? 'Leave'),
                'code' => $r->leaveType?->code,
                'from' => $r->from_date->toDateString(),
                'to' => $r->to_date->toDateString(),
                'days' => (float) $r->days,
                'session' => $r->sessionOn($day) ?? 'full',
                'status' => (string) $r->status,
                'status_label' => $r->status === 'cancel_requested' ? 'Cancellation requested' : (LeaveRequest::STATUSES[$r->status] ?? ucfirst((string) $r->status)),
                'paid' => (bool) ($r->leaveType?->is_paid ?? true),
                'reason' => $r->reason,
                'can_cancel' => $r->isOpen() && $viewer->can('cancel', $r),
            ])->values()->all();
    }

    public function cancel(int $leaveRequestId, string $reason, User $actor): string
    {
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') {
            throw new RuntimeException('Give the reason for cancelling the leave.');
        }
        $request = LeaveRequest::query()->find($leaveRequestId);
        if ($request === null || ! $actor->can('cancel', $request)) {
            throw new RuntimeException('You may not cancel this leave.');
        }
        if (! $request->isOpen()) {
            throw new RuntimeException('Only pending or approved leave can be cancelled.');
        }
        $result = app(Leaves::class)->cancel($request, $reason, $actor);

        return $result->status === 'cancel_requested' ? 'Cancellation requested: the leave stays until it is decided.' : 'Leave cancelled.';
    }
}

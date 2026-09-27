<?php

namespace App\Domain\Leave\Services;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Events\LeaveEvent;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\Timeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Leave requests: validate against policy and balance, approve/reject/cancel, post to ledger, reprocess attendance. */
final class Leaves
{
    public function __construct(
        private readonly LeaveEntitlements $entitlements,
        private readonly LeaveDayCounter $counter,
        private readonly LeaveBalances $balances,
        private readonly LeaveYear $years,
        private readonly AttendanceProcessor $attendance,
        private readonly AuditRecorder $audit,
        private readonly Timeline $timeline,
    ) {}

    public function request(Employee $employee, LeaveType $type, Carbon|string $from, Carbon|string $to, string $reason, string $fromSession = 'full', string $toSession = 'full', ?EmployeeDocument $document = null, ?User $requester = null): LeaveRequest
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($to->lt($from)) {
            throw new RuntimeException('The end date must not be before the start date.');
        }

        if ($type->status->value !== 'active') {
            throw new RuntimeException('This leave type is not available.');
        }

        if ($type->applicable_gender && $employee->person?->gender && $type->applicable_gender !== $employee->person->gender) {
            throw new RuntimeException("{$type->name} does not apply to this employee.");
        }

        $rule = $this->entitlements->forType($employee, $type, $from)
            ?? ($type->category === 'unpaid' ? LeaveEntitlements::DEFAULTS : throw new RuntimeException("No leave policy grants {$type->name} to this employee."));

        if (! $rule['probation_eligible'] && $employee->lifecycle_state === LifecycleState::Probation) {
            throw new RuntimeException("{$type->name} is not available during probation.");
        }

        if (($fromSession !== 'full' || $toSession !== 'full') && ! ($type->allow_half_day && $rule['half_day_allowed'])) {
            throw new RuntimeException('Half days are not allowed for this leave type.');
        }

        if ((int) $rule['min_notice_days'] > 0 && $from->lt(now()->startOfDay()->addDays((int) $rule['min_notice_days']))) {
            throw new RuntimeException("{$type->name} needs at least {$rule['min_notice_days']} day(s) notice.");
        }

        $dates = $this->counter->dates($employee, $from, $to, $fromSession, $toSession);
        $days = $this->counter->total($dates);

        if ($days <= 0) {
            throw new RuntimeException('The selected span contains no working days.');
        }

        if ((int) $rule['max_consecutive_days'] > 0 && $days > (int) $rule['max_consecutive_days']) {
            throw new RuntimeException("{$type->name} allows at most {$rule['max_consecutive_days']} consecutive day(s).");
        }

        if ((int) $rule['document_required_after_days'] > 0 && $days > (int) $rule['document_required_after_days'] && $document === null) {
            throw new RuntimeException("A supporting document is required for more than {$rule['document_required_after_days']} day(s).");
        }

        $this->assertNoOverlap($employee, $dates);
        $this->assertLockedDays($employee, $dates);

        if ($type->category !== 'unpaid') {
            $period = $this->years->periodFor($from);
            $available = $this->balances->balance($employee, $type, $period)->available();

            if ($available - $days < -(float) $rule['negative_balance_limit']) {
                throw new RuntimeException(sprintf('Insufficient %s balance: %.1f available, %.1f requested.', $type->name, $available, $days));
            }
        }

        return DB::transaction(function () use ($employee, $type, $from, $to, $fromSession, $toSession, $dates, $days, $reason, $document, $requester) {
            $request = new LeaveRequest([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'from_date' => $from,
                'to_date' => $to,
                'from_session' => $fromSession,
                'to_session' => $toSession,
                'days' => $days,
                'dates' => $dates,
                'reason' => $reason,
                'status' => 'pending',
                'document_id' => $document?->id,
                'requested_by' => ($requester ?? auth()->user())?->id,
            ]);
            $request->withAuditReason($reason)->save();

            foreach ($this->periods($request) as $period) {
                $this->balances->recompute($employee, $type, $period);
            }

            LeaveEvent::dispatch('leave.requested', $employee, $request, ['leave_type' => $type->code, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days, 'reason' => $reason]);

            return $request;
        });
    }

    public function approve(LeaveRequest $request, ?string $note = null, ?User $actor = null): LeaveRequest
    {
        $this->assertPending($request);
        $request->loadMissing(['employee', 'leaveType']);
        $actor ??= auth()->user();

        return DB::transaction(function () use ($request, $note, $actor) {
            $request->withAuditReason($note)->update(['status' => 'approved', 'reviewed_by' => $actor?->id, 'reviewed_at' => now(), 'review_note' => $note]);

            if ($request->leaveType->category !== 'unpaid') {
                foreach ($this->daysByPeriod($request) as $period => $days) {
                    $this->balances->post($request->employee, $request->leaveType, $period, 'usage', -$days, $request, $request->reason);
                }
            } else {
                foreach ($this->periods($request) as $period) {
                    $this->balances->recompute($request->employee, $request->leaveType, $period);
                }
            }

            $this->audit->record(AuditAction::Approved, 'leave', $request, reason: $note, actor: $actor);
            $this->timeline->record($request->employee, 'leave', "{$request->leaveType->name} approved: {$request->days} day(s)", $request->from_date, $request->reason, $request);
            $this->reprocessAttendance($request);

            LeaveEvent::dispatch('leave.approved', $request->employee, $request, ['leave_type' => $request->leaveType->code, 'from' => $request->from_date->toDateString(), 'to' => $request->to_date->toDateString(), 'days' => (float) $request->days, 'note' => $note]);

            return $request;
        });
    }

    public function reject(LeaveRequest $request, string $note, ?User $actor = null): LeaveRequest
    {
        $this->assertPending($request);
        $request->loadMissing(['employee', 'leaveType']);
        $actor ??= auth()->user();

        $request->withAuditReason($note)->update(['status' => 'rejected', 'reviewed_by' => $actor?->id, 'reviewed_at' => now(), 'review_note' => $note]);

        foreach ($this->periods($request) as $period) {
            $this->balances->recompute($request->employee, $request->leaveType, $period);
        }

        $this->audit->record(AuditAction::Rejected, 'leave', $request, reason: $note, actor: $actor);
        LeaveEvent::dispatch('leave.rejected', $request->employee, $request, ['leave_type' => $request->leaveType->code, 'note' => $note]);

        return $request;
    }

    /** Cancel a pending or approved request; approved usage is reversed and attendance recomputed. */
    public function cancel(LeaveRequest $request, string $reason, ?User $actor = null): LeaveRequest
    {
        if (! $request->isOpen()) {
            throw new RuntimeException('Only pending or approved requests can be cancelled.');
        }

        $request->loadMissing(['employee', 'leaveType']);
        $this->assertLockedDays($request->employee, $request->dates ?? []);
        $wasApproved = $request->status === 'approved';

        return DB::transaction(function () use ($request, $reason, $actor, $wasApproved) {
            $request->withAuditReason($reason)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'review_note' => $reason]);

            if ($wasApproved && $request->leaveType->category !== 'unpaid') {
                foreach ($this->daysByPeriod($request) as $period => $days) {
                    $this->balances->post($request->employee, $request->leaveType, $period, 'reversal', $days, $request, "Cancelled: {$reason}");
                }
            } else {
                foreach ($this->periods($request) as $period) {
                    $this->balances->recompute($request->employee, $request->leaveType, $period);
                }
            }

            $this->audit->record(AuditAction::Cancelled, 'leave', $request, reason: $reason, actor: $actor ?? auth()->user());

            if ($wasApproved) {
                $this->reprocessAttendance($request);
            }

            LeaveEvent::dispatch('leave.cancelled', $request->employee, $request, ['leave_type' => $request->leaveType->code, 'reason' => $reason, 'was_approved' => $wasApproved]);

            return $request;
        });
    }

    /** Approved leave covering a date, with its session. */
    public function approvedOn(Employee $employee, Carbon $date): ?LeaveRequest
    {
        return LeaveRequest::query()
            ->with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $date->toDateString())
            ->whereDate('to_date', '>=', $date->toDateString())
            ->get()
            ->first(fn (LeaveRequest $r) => $r->sessionOn($date->toDateString()) !== null);
    }

    /** @return array<int, float> period => days */
    private function daysByPeriod(LeaveRequest $request): array
    {
        $byPeriod = [];

        foreach ($request->dates ?? [] as $d) {
            $period = $this->years->periodFor($d['date']);
            $byPeriod[$period] = round(($byPeriod[$period] ?? 0) + (float) $d['days'], 2);
        }

        return $byPeriod;
    }

    /** @return list<int> */
    private function periods(LeaveRequest $request): array
    {
        return array_keys($this->daysByPeriod($request));
    }

    private function assertPending(LeaveRequest $request): void
    {
        if ($request->status !== 'pending') {
            throw new RuntimeException('This request has already been decided.');
        }
    }

    /** @param  list<array{date: string}>  $dates */
    private function assertNoOverlap(Employee $employee, array $dates): void
    {
        $wanted = array_column($dates, 'date');

        $clash = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('from_date', '<=', max($wanted))
            ->whereDate('to_date', '>=', min($wanted))
            ->get()
            ->first(fn (LeaveRequest $r) => array_intersect($wanted, array_column($r->dates ?? [], 'date')) !== []);

        if ($clash) {
            throw new RuntimeException('Leave already exists for '.implode(', ', array_intersect($wanted, array_column($clash->dates ?? [], 'date'))).'.');
        }
    }

    /** @param  list<array{date: string}>  $dates */
    private function assertLockedDays(Employee $employee, array $dates): void
    {
        $locked = AttendanceRecord::query()
            ->where('employee_id', $employee->id)->where('is_locked', true)
            ->whereIn('date', array_column($dates, 'date'))
            ->exists();

        if ($locked) {
            throw new RuntimeException('One or more of these days are locked for payroll.');
        }
    }

    private function reprocessAttendance(LeaveRequest $request): void
    {
        foreach ($request->dates ?? [] as $d) {
            if (Carbon::parse($d['date'])->lte(now())) {
                $this->attendance->process($request->employee, $d['date']);
            }
        }
    }
}

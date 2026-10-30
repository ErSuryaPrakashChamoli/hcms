<?php

namespace App\Domain\Leave\Services;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Support\RequestRules;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Experience\Services\SelfServiceLock;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Events\LeaveEvent;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Database\Eloquent\Collection;
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
        private readonly LeaveEligibility $eligibility,
    ) {}

    public function request(Employee $employee, LeaveType $type, Carbon|string $from, Carbon|string $to, string $reason, string $fromSession = 'full', string $toSession = 'full', ?EmployeeDocument $document = null, ?User $requester = null, ?string $idempotencyKey = null, ?string $contact = null): LeaveRequest
    {
        // SaaS.3: shadow entitlement observation (never blocks; see Entitlements).
        app(Entitlements::class)->observe(Capability::Leave, 'leave.request');
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        // A retried API call with the same key returns the original request instead of a second one.
        if ($idempotencyKey !== null && ($existing = LeaveRequest::query()->where('employee_id', $employee->id)->where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }

        if ($to->lt($from)) {
            throw new RuntimeException('The end date must not be before the start date.');
        }
        // HROne setup parity P4: the employee request lock (overdue tasks / lock period) refuses the employee's own request.
        app(SelfServiceLock::class)->assertOpen($employee, 'leave', $requester);
        // HROne setup parity (TO-4): the attendance policy's request lock during attendance and payroll processing.
        app(RequestRules::class)->assertNotLocked($employee, $from, 'Leave');
        app(RequestRules::class)->assertShiftAllows($employee, $from, $to, 'leave', 'Leave');

        if ($type->unit === 'hours') {
            throw new RuntimeException("{$type->name} is configured in hours; hourly leave requests are not available yet.");
        }

        $eligibility = $this->eligibility->check($employee, $type, $from);
        if (! $eligibility->eligible) {
            throw new RuntimeException($eligibility->reason);
        }
        $rule = $eligibility->rule;

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
        if ($type->min_request_units !== null && $days < $type->min_request_units) {
            throw new RuntimeException("{$type->name} must be requested for at least {$type->min_request_units} day(s).");
        }
        if ($type->max_request_units !== null && $days > $type->max_request_units) {
            throw new RuntimeException("{$type->name} can be requested for at most {$type->max_request_units} day(s) at a time.");
        }
        if ((int) $rule['max_consecutive_days'] > 0 && $days > (int) $rule['max_consecutive_days']) {
            throw new RuntimeException("{$type->name} allows at most {$rule['max_consecutive_days']} consecutive day(s).");
        }

        $needsDocument = $type->requires_document || ((int) $rule['document_required_after_days'] > 0 && $days > (int) $rule['document_required_after_days']);
        if ($needsDocument && $document === null) {
            throw new RuntimeException($type->requires_document ? "{$type->name} requires a supporting document." : "A supporting document is required for more than {$rule['document_required_after_days']} day(s).");
        }

        $this->assertLockedDays($employee, $dates);
        $this->assertNotPresent($employee, $dates);
        $this->assertTypeRules($employee, $type, $rule, $from, $to, $days, $requester ?? auth()->user());

        return DB::transaction(function () use ($employee, $type, $from, $to, $fromSession, $toSession, $dates, $days, $reason, $document, $requester, $rule, $idempotencyKey, $contact) {
            // Serialise concurrent requests for this employee, then re-check overlap and balance under the lock.
            $this->balances->lockEmployee($employee);
            $this->assertNoOverlap($employee, $dates);
            $this->assertBalance($employee, $type, $dates, $rule, $days);

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
                'contact_details' => $contact,
                'status' => 'pending',
                'document_id' => $document?->id,
                'requested_by' => ($requester ?? auth()->user())?->id,
                'idempotency_key' => $idempotencyKey,
            ]);
            $request->withAuditReason($reason)->save();

            foreach ($this->periods($request) as $period) {
                $this->balances->recompute($employee, $type, $period);
            }

            $this->audit->record(AuditAction::LeaveRequested, 'leave', $request, reason: $reason, metadata: ['leave_type' => $type->code, 'days' => $days, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);
            LeaveEvent::dispatch('leave.requested', $employee, $request, ['leave_type' => $type->code, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days, 'reason' => $reason]);

            if (! $type->requires_approval) {
                return $this->approve($request, 'Auto-approved: this leave type needs no approval', $requester ?? auth()->user());
            }

            return $request;
        });
    }

    public function approve(LeaveRequest $request, ?string $note = null, ?User $actor = null): LeaveRequest
    {
        $request->loadMissing(['employee', 'leaveType']);
        $actor ??= auth()->user();

        return DB::transaction(function () use ($request, $note, $actor) {
            $this->balances->lockEmployee($request->employee);
            $this->assertPending($request->refresh());

            // Another approval may have used the balance since the request was made.
            if ($request->leaveType->category !== 'unpaid') {
                $rule = $this->eligibility->check($request->employee, $request->leaveType, $request->from_date)->rule ?? LeaveEntitlements::DEFAULTS;
                $this->assertBalance($request->employee, $request->leaveType, $request->dates ?? [], $rule, (float) $request->days, $request);
            }

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

            $this->audit->record(AuditAction::LeaveApproved, 'leave', $request, reason: $note, actor: $actor, metadata: ['days' => (float) $request->days]);
            $this->timeline->record($request->employee, 'leave', "{$request->leaveType->name} approved: {$request->days} day(s)", $request->from_date, null, $request);
            $this->reprocessAttendance($request);

            LeaveEvent::dispatch('leave.approved', $request->employee, $request, ['leave_type' => $request->leaveType->code, 'from' => $request->from_date->toDateString(), 'to' => $request->to_date->toDateString(), 'days' => (float) $request->days, 'note' => $note]);

            return $request;
        });
    }

    public function reject(LeaveRequest $request, string $note, ?User $actor = null): LeaveRequest
    {
        $request->loadMissing(['employee', 'leaveType']);
        $actor ??= auth()->user();

        return DB::transaction(function () use ($request, $note, $actor) {
            $this->balances->lockEmployee($request->employee);
            $this->assertPending($request->refresh());

            $request->withAuditReason($note)->update(['status' => 'rejected', 'reviewed_by' => $actor?->id, 'reviewed_at' => now(), 'review_note' => $note]);

            foreach ($this->periods($request) as $period) {
                $this->balances->recompute($request->employee, $request->leaveType, $period);
            }

            $this->audit->record(AuditAction::LeaveRejected, 'leave', $request, reason: $note, actor: $actor);
            LeaveEvent::dispatch('leave.rejected', $request->employee, $request, ['leave_type' => $request->leaveType->code, 'note' => $note]);

            return $request;
        });
    }

    /**
     * Cancel a request. Pending requests are withdrawn. Approved leave is reversed with compensating
     * ledger entries — unless the leave type's cancellation policy requires approval (then it moves
     * to cancel_requested and stays effective) or forbids self-cancellation (only leave.manage may).
     */
    public function cancel(LeaveRequest $request, string $reason, ?User $actor = null): LeaveRequest
    {
        $request->loadMissing(['employee', 'leaveType']);
        $actor ??= auth()->user();

        if (in_array($request->status, ['approved', 'cancel_requested'], true) && ! ($actor?->can('leave.manage') ?? false)) {
            $policy = $request->leaveType->cancellation_policy ?? 'self';

            if ($policy === 'not_allowed') {
                throw new RuntimeException('Approved '.$request->leaveType->name.' can only be cancelled by HR.');
            }
            if ($policy === 'approval') {
                return $this->requestCancellation($request, $reason, $actor);
            }
        }

        return $this->reverse($request, $reason, $actor);
    }

    /** Employee asks to cancel approved leave; the leave stays effective until the cancellation is decided. */
    public function requestCancellation(LeaveRequest $request, string $reason, ?User $actor = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $reason, $actor) {
            $this->balances->lockEmployee($request->employee);
            if ($request->refresh()->status !== 'approved') {
                throw new RuntimeException('Only approved leave can have a cancellation requested.');
            }
            $this->assertLockedDays($request->employee, $request->dates ?? []);

            $request->withAuditReason($reason)->update(['status' => 'cancel_requested', 'cancel_requested_at' => now(), 'cancel_reason' => $reason]);
            $this->audit->record(AuditAction::LeaveCancelRequested, 'leave', $request, reason: $reason, actor: $actor);
            LeaveEvent::dispatch('leave.cancel_requested', $request->employee, $request, ['leave_type' => $request->leaveType->code, 'reason' => $reason]);

            return $request;
        });
    }

    public function approveCancellation(LeaveRequest $request, ?string $note = null, ?User $actor = null): LeaveRequest
    {
        $request->loadMissing(['employee', 'leaveType']);
        if ($request->status !== 'cancel_requested') {
            throw new RuntimeException('There is no cancellation request to approve.');
        }
        $actor ??= auth()->user();
        $request->forceFill(['cancellation_reviewed_by' => $actor?->id, 'cancellation_reviewed_at' => now()]);

        return $this->reverse($request, $request->cancel_reason ?? $note ?? 'Cancellation approved', $actor, $note);
    }

    public function rejectCancellation(LeaveRequest $request, string $note, ?User $actor = null): LeaveRequest
    {
        $request->loadMissing(['employee', 'leaveType']);

        return DB::transaction(function () use ($request, $note, $actor) {
            $this->balances->lockEmployee($request->employee);
            if ($request->refresh()->status !== 'cancel_requested') {
                throw new RuntimeException('There is no cancellation request to reject.');
            }
            $actor ??= auth()->user();
            $request->withAuditReason($note)->update(['status' => 'approved', 'cancellation_reviewed_by' => $actor?->id, 'cancellation_reviewed_at' => now()]);
            $this->audit->record(AuditAction::Rejected, 'leave', $request, reason: $note, actor: $actor, metadata: ['decision' => 'cancellation rejected']);
            LeaveEvent::dispatch('leave.cancellation_rejected', $request->employee, $request, ['leave_type' => $request->leaveType->code, 'note' => $note]);

            return $request;
        });
    }

    private function reverse(LeaveRequest $request, string $reason, ?User $actor, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $reason, $actor, $note) {
            $this->balances->lockEmployee($request->employee);
            $reviewer = ['cancellation_reviewed_by' => $request->cancellation_reviewed_by, 'cancellation_reviewed_at' => $request->cancellation_reviewed_at];
            $request->refresh();

            if (! $request->isOpen()) {
                throw new RuntimeException('Only pending or approved requests can be cancelled.');
            }
            $this->assertLockedDays($request->employee, $request->dates ?? []);
            $wasApproved = in_array($request->status, LeaveRequest::TAKEN, true);

            $request->withAuditReason($reason)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'review_note' => $note ?? $reason, 'cancel_reason' => $request->cancel_reason ?? $reason] + array_filter($reviewer));

            if ($wasApproved && $request->leaveType->category !== 'unpaid') {
                foreach ($this->daysByPeriod($request) as $period => $days) {
                    $this->balances->post($request->employee, $request->leaveType, $period, 'reversal', $days, $request, "Cancelled: {$reason}");
                }
            } else {
                foreach ($this->periods($request) as $period) {
                    $this->balances->recompute($request->employee, $request->leaveType, $period);
                }
            }

            $this->audit->record(AuditAction::LeaveCancelled, 'leave', $request, reason: $reason, actor: $actor ?? auth()->user(), metadata: ['was_approved' => $wasApproved]);

            if ($wasApproved) {
                $this->reprocessAttendance($request);
            }

            LeaveEvent::dispatch('leave.cancelled', $request->employee, $request, ['leave_type' => $request->leaveType->code, 'reason' => $reason, 'was_approved' => $wasApproved]);

            return $request;
        });
    }

    /** Approved leave covering a date, with its session. */
    /**
     * HROne setup parity (TO-7): the leave type's request rules in the policy — how often and how much (per month, year
     * and tenure), backdated days per role, future dates, a pending resignation, the birthday window and leave types
     * it cannot be taken next to. Each rule applies only when the policy sets it.
     *
     * @param  array<string, mixed>  $rule
     */
    private function assertTypeRules(Employee $employee, LeaveType $type, array $rule, Carbon $from, Carbon $to, float $days, ?User $requester): void
    {
        $role = RequestRules::role($employee, $requester);
        $back = $rule["{$role}_backdated_days"] ?? null;
        $back = is_numeric($back) && $role !== 'employee' ? (int) $back : (is_numeric($rule['backdated_days'] ?? null) ? (int) $rule['backdated_days'] : null);
        if ($back !== null && $from->lt(now()->startOfDay()->subDays($back))) {
            throw new RuntimeException($back > 0 ? "{$type->name} can be applied at most {$back} day(s) back." : "{$type->name} cannot be applied for past days.");
        }
        if (($rule['future_allowed'] ?? 'yes') === 'no' && $to->gt(now()->startOfDay())) {
            throw new RuntimeException("{$type->name} cannot be applied for future days.");
        }
        if (! empty($rule['not_while_resigning']) && ExitCase::query()->where('employee_id', $employee->id)->where('type', 'resignation')->whereIn('status', ['initiated', 'notice'])->exists()) {
            throw new RuntimeException("{$type->name} cannot be applied while a resignation is pending.");
        }
        if (is_numeric($rule['birthday_window_before'] ?? null) || is_numeric($rule['birthday_window_after'] ?? null)) {
            $born = $employee->person?->date_of_birth ?? throw new RuntimeException('Your date of birth is not on record.');
            $birthday = Carbon::parse($born)->setYear($from->year)->startOfDay();
            $windowFrom = $birthday->copy()->subDays((int) ($rule['birthday_window_before'] ?? 0));
            $windowTo = $birthday->copy()->addDays((int) ($rule['birthday_window_after'] ?? 0));
            if ($from->lt($windowFrom) || $to->gt($windowTo)) {
                throw new RuntimeException("{$type->name} can be taken from {$windowFrom->format('d M')} to {$windowTo->format('d M')}.");
            }
        }
        $active = fn () => LeaveRequest::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->whereIn('status', ['pending', 'approved', 'cancel_requested']);
        foreach (['year' => [$from->copy()->startOfYear(), $from->copy()->endOfYear()], 'month' => [$from->copy()->startOfMonth(), $from->copy()->endOfMonth()]] as $span => [$start, $end]) {
            $inSpan = $active()->whereDate('from_date', '>=', $start->toDateString())->whereDate('from_date', '<=', $end->toDateString());
            if (($max = (int) ($rule["max_requests_per_{$span}"] ?? 0)) > 0 && (clone $inSpan)->count() >= $max) {
                throw new RuntimeException("{$type->name} can be requested at most {$max} time(s) a {$span}.");
            }
            if (($maxDays = (float) ($rule["max_days_per_{$span}"] ?? 0)) > 0 && (float) (clone $inSpan)->sum('days') + $days > $maxDays) {
                throw new RuntimeException("{$type->name} allows at most {$maxDays} day(s) a {$span}.");
            }
        }
        if (($tenure = (int) ($rule['max_requests_in_tenure'] ?? 0)) > 0 && $active()->count() >= $tenure) {
            throw new RuntimeException("{$type->name} can be requested at most {$tenure} time(s) in all.");
        }
        $codes = array_values(array_filter((array) ($rule['not_clubbed_with'] ?? [])));
        if ($codes !== []) {
            $next = LeaveRequest::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved', 'cancel_requested'])
                ->whereHas('leaveType', fn ($q) => $q->whereIn('code', $codes))
                ->where(fn ($q) => $q->whereDate('to_date', $from->copy()->subDay()->toDateString())->orWhereDate('from_date', $to->copy()->addDay()->toDateString()))->first();
            if ($next !== null) {
                throw new RuntimeException("{$type->name} cannot be taken next to {$next->leaveType?->name}.");
            }
        }
    }

    public function approvedOn(Employee $employee, Carbon $date): ?LeaveRequest
    {
        return LeaveRequest::query()
            ->with('leaveType')
            ->where('employee_id', $employee->id)
            ->whereIn('status', LeaveRequest::TAKEN)
            ->whereDate('from_date', '<=', $date->toDateString())
            ->whereDate('to_date', '>=', $date->toDateString())
            ->get()
            ->first(fn (LeaveRequest $r) => $r->sessionOn($date->toDateString()) !== null);
    }

    /** @return Collection<int, LeaveRequest> requests taken (approved) that overlap the range, oldest first */
    public function approvedBetween(Employee $employee, Carbon $from, Carbon $to): Collection
    {
        return LeaveRequest::query()
            ->with('leaveType')
            ->where('employee_id', $employee->id)
            ->whereIn('status', LeaveRequest::TAKEN)
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->orderBy('id')
            ->get();
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

    /**
     * Balance check under the employee lock (see inline note on reservation vs approval).
     *
     * @param  list<array{date: string, days: float}>  $dates
     */
    private function assertBalance(Employee $employee, LeaveType $type, array $dates, array $rule, float $days, ?LeaveRequest $self = null): void
    {
        if ($type->category === 'unpaid') {
            return;
        }

        $byPeriod = [];
        foreach ($dates as $d) {
            $period = $this->years->periodFor($d['date']);
            $byPeriod[$period] = ($byPeriod[$period] ?? 0) + (float) $d['days'];
        }

        foreach ($byPeriod as $period => $wanted) {
            $balance = $this->balances->recompute($employee, $type, $period);
            // New requests are checked against closing minus everything pending (reservation); at approval
            // the request is checked against the committed closing balance only, so an earlier request is
            // not blocked by later pending ones.
            $available = $self !== null ? (float) $balance->closing : $balance->available();

            if ($available - $wanted < -(float) $rule['negative_balance_limit'] - 0.0001) {
                throw new RuntimeException(sprintf('Insufficient %s balance: %.1f available, %.1f requested.', $type->name, $available, $wanted));
            }
        }
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
            ->whereIn('status', LeaveRequest::ACTIVE)
            ->whereDate('from_date', '<=', max($wanted))
            ->whereDate('to_date', '>=', min($wanted))
            ->get()
            ->first(fn (LeaveRequest $r) => array_intersect($wanted, array_column($r->dates ?? [], 'date')) !== []);

        if ($clash) {
            throw new RuntimeException('Leave already exists for '.implode(', ', array_intersect($wanted, array_column($clash->dates ?? [], 'date'))).'.');
        }
    }

    /** @param  list<array{date: string}>  $dates */
    /** HP-1b: under leave.block_on_present_day, a full day of leave cannot fall on a day attendance shows as present. */
    private function assertNotPresent(Employee $employee, array $dates): void
    {
        if (! (bool) app(SettingsRepository::class)->get('leave.block_on_present_day', false)) {
            return;
        }
        $full = array_column(array_filter($dates, fn (array $d) => (float) $d['days'] >= 1.0), 'date');
        if ($full === []) {
            return;
        }
        $present = AttendanceRecord::query()->where('employee_id', $employee->id)->where('status', 'present')
            ->where(function ($q) use ($full) {
                foreach ($full as $day) {
                    $q->orWhereDate('date', $day);
                }
            })->value('date');
        if ($present !== null) {
            throw new RuntimeException('You were present on '.Carbon::parse($present)->format('d M Y').'; leave cannot be taken for that day.');
        }
    }

    private function assertLockedDays(Employee $employee, array $dates): void
    {
        // HP-1: with corrections after lock allowed, the locked day stays as paid and the change becomes an attendance arrear.
        if ((bool) app(SettingsRepository::class)->get('attendance.allow_corrections_after_lock', false)) {
            return;
        }

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

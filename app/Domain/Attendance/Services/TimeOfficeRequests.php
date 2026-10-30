<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftOverride;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Support\RequestRules;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\SelfServiceLock;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Platform\Services\SettingsRepository;
use BackedEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Time-office requests (HROne parity HP-1): short leave, on duty, shift change, overtime and comp-off
 * claims and restricted holidays. Each type is checked against its policy (`short_leave`, `time_office`)
 * when raised; approval applies its effect (a shift override, or nothing beyond reprocessing: the engine
 * reads approved requests itself) and recalculates the affected days. A comp-off credit is posted by the
 * leave domain when it hears `attendance.timeoffice_approved`. Cancelling an approved request reverses the
 * effect, after approval when the policy asks for it.
 */
final class TimeOfficeRequests
{
    /** Types a person may hold only one live request of for any one day. */
    private const ONE_PER_DAY = ['on_duty', 'shift_change', 'overtime', 'comp_off', 'restricted_holiday', 'week_off_change'];

    public function __construct(
        private readonly AttendanceProcessor $processor,
        private readonly PolicyResolver $policies,
        private readonly SettingsRepository $settings,
        private readonly HolidayResolver $holidays,
        private readonly AuditRecorder $audit,
        private readonly RequestRules $rules,
        private readonly ShiftResolver $shifts,
    ) {}

    /**
     * HROne parity RQ-1 (request launcher): whether the employee's policies let them raise this type at all today — the
     * same switches, read with the same defaults, that request() refuses on (short leave policy enabled; on duty, shift
     * change, overtime and comp-off allowed unless switched off; week-off change only when switched on; a restricted
     * holiday only when the employee's calendar asks them to choose optional holidays and has some this year). Read only:
     * request() still checks everything else (dates, windows, limits, quotas).
     */
    public function offers(Employee $employee, string $type): bool
    {
        $today = now()->startOfDay();
        $policy = fn () => $this->policies->resolve('time_office', $employee, $today);

        return match ($type) {
            'short_leave' => ($p = $this->policies->resolve('short_leave', $employee, $today)) === null || $p->setting('enabled', true) !== false,
            'on_duty' => ($p = $policy()) === null || $p->setting('on_duty_allowed', true) !== false,
            'shift_change' => ($p = $policy()) === null || $p->setting('shift_change_allowed', true) !== false,
            'week_off_change' => RequestRules::isYes($policy()?->setting('week_off_change_allowed')),
            'overtime' => ($p = $policy()) === null || $p->setting('overtime_claims_allowed', true) !== false,
            'comp_off' => ($p = $policy()) === null || $p->setting('comp_off_allowed', true) !== false,
            'restricted_holiday' => ($calendar = $this->holidays->calendarFor($employee)) !== null && (bool) $calendar->optional_requires_selection
                && Holiday::query()->where('holiday_calendar_id', $calendar->id)->where('type', 'optional')->whereDate('date', '>=', $today->copy()->startOfYear()->toDateString())->exists(),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $data  from_date, to_date, session (full|first_half|second_half|hours), start_time, end_time,
     *                                      minutes, shift_id, holiday_id
     */
    public function request(Employee $employee, string $type, array $data, string $reason, ?User $requester = null): TimeOfficeRequest
    {
        if (! array_key_exists($type, TimeOfficeRequest::TYPES)) {
            throw new RuntimeException("Unknown time-office request type [{$type}].");
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for the request.');
        }
        $requester ??= auth()->user();
        // HROne setup parity P4: the employee request lock refuses the employee's own request while it applies.
        app(SelfServiceLock::class)->assertOpen($employee, 'time_office', $requester);

        if ($type === 'restricted_holiday' && ! empty($data['holiday_id'])) {
            $data['from_date'] ??= Holiday::query()->whereKey($data['holiday_id'])->value('date');
        }
        $from = Carbon::parse($data['from_date'] ?? $data['date'] ?? throw new RuntimeException('Choose a date.'))->startOfDay();
        $to = Carbon::parse($data['to_date'] ?? $from)->startOfDay();
        if ($to->lt($from)) {
            throw new RuntimeException('The end date must not be before the start date.');
        }

        $attributes = match ($type) {
            'short_leave' => $this->shortLeave($employee, $from, $to, $data, $requester),
            'on_duty' => $this->onDuty($employee, $from, $to, $data, $requester),
            'shift_change' => $this->shiftChange($employee, $from, $to, $data),
            'week_off_change' => $this->weekOffChange($employee, $from, $data),
            'overtime' => $this->overtimeClaim($employee, $from, $to, $data),
            'comp_off' => $this->compOff($employee, $from, $to),
            'restricted_holiday' => $this->restrictedHoliday($employee, $data),
        };
        $attributes += ['from_date' => $from, 'to_date' => $to];
        if (in_array($type, ['short_leave', 'on_duty', 'shift_change', 'week_off_change'], true)) {
            $this->rules->assertNotLocked($employee, $from, TimeOfficeRequest::TYPES[$type]);
        }
        if (in_array($type, ['short_leave', 'on_duty'], true)) {
            $this->rules->assertShiftAllows($employee, $from, $to, $type, TimeOfficeRequest::TYPES[$type]);
        }
        $attachments = array_values(array_filter((array) ($data['attachments'] ?? [])));
        if ($attachments !== []) {
            $attributes['attachments'] = $attachments;
        }

        $request = DB::transaction(function () use ($employee, $type, $attributes, $reason, $requester) {
            // Serialise this employee's requests, then re-check overlap, limits and locks under the lock.
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->getKey())->lockForUpdate()->value('id');
            $from = Carbon::parse($attributes['from_date']);
            $to = Carbon::parse($attributes['to_date']);
            $this->assertNoOverlap($employee, $type, $from, $to);
            if ($type === 'short_leave') {
                $attributes['effects'] = $this->assertShortLeaveLimits($employee, $from, (int) $attributes['minutes']);
            } else {
                $this->assertMonthlyLimit($employee, $type, $from, $requester);
            }
            if ($type === 'restricted_holiday') {
                $this->assertRestrictedHolidayQuota($employee, Holiday::query()->findOrFail($attributes['holiday_id']));
            }
            if ($type !== 'comp_off') {
                $this->assertUnlocked($employee, $from, $to);
            }

            $request = new TimeOfficeRequest(['employee_id' => $employee->id, 'type' => $type, 'status' => 'pending', 'reason' => $reason, 'requested_by' => $requester?->id] + $attributes);
            $request->withAuditReason($reason)->save();
            $this->audit->record(AuditAction::Submitted, 'attendance', $request, reason: $reason, metadata: ['type' => $type, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);
            AttendanceEvent::dispatch('attendance.timeoffice_requested', $employee, $request, ['type' => $type, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'reason' => $reason]);

            return $request;
        });

        if (! $this->needsApproval($employee, $request)) {
            return $this->approve($request, 'Approved automatically: the policy needs no approval', $requester, auto: true);
        }

        return $request;
    }

    /**
     * A request decided outside the portal (an upload of approved overtime): recorded as approved in the actor's
     * name without the employee-facing windows and limits, but with the type's own checks, the payroll lock and
     * the one-per-day rule. Only overtime is accepted this way.
     */
    public function recordApproved(Employee $employee, string $type, array $data, string $reason, User $actor): TimeOfficeRequest
    {
        if ($type !== 'overtime') {
            throw new RuntimeException('Only overtime can be recorded as approved from an upload.');
        }
        $date = Carbon::parse($data['from_date'])->startOfDay();
        $minutes = (int) ($data['minutes'] ?? 0);
        if ($date->gt(now()->startOfDay()) || $minutes <= 0 || $minutes > 960) {
            throw new RuntimeException('Overtime is recorded for a day already worked, between 1 minute and 16 hours.');
        }

        $request = DB::transaction(function () use ($employee, $date, $minutes, $reason, $actor) {
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->getKey())->lockForUpdate()->value('id');
            $this->assertNoOverlap($employee, 'overtime', $date, $date);
            $this->assertUnlocked($employee, $date, $date);

            $request = new TimeOfficeRequest(['employee_id' => $employee->id, 'type' => 'overtime', 'status' => 'approved', 'from_date' => $date, 'to_date' => $date, 'session' => 'hours', 'minutes' => $minutes,
                'reason' => $reason, 'requested_by' => $actor->id, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => 'Recorded as approved from an upload']);
            $request->withAuditReason($reason)->save();
            $this->audit->record(AuditAction::Approved, 'attendance', $request, reason: $reason, actor: $actor, metadata: ['type' => 'overtime', 'recorded' => true]);
            AttendanceEvent::dispatch('attendance.timeoffice_approved', $employee, $request, ['type' => 'overtime', 'from' => $date->toDateString(), 'to' => $date->toDateString(), 'recorded' => true]);

            return $request;
        });
        $request->setRelation('employee', $employee);
        $this->reprocess($request);

        return $request->refresh();
    }

    public function approve(TimeOfficeRequest $request, ?string $note = null, ?User $actor = null, bool $auto = false): TimeOfficeRequest
    {
        $actor ??= auth()->user();

        $request = DB::transaction(function () use ($request, $note, $actor, $auto) {
            $locked = $this->lock($request);
            if ($locked->status !== 'pending') {
                throw new RuntimeException('This request has already been decided.');
            }
            if (! $auto) {
                $this->assertNotOwn($locked, $actor);
            }

            $locked->withAuditReason($note)->update(['status' => 'approved', 'reviewed_by' => $actor?->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $locked->withoutAuditing()->update(['effects' => $this->applyEffects($locked, $actor)]);
            $this->audit->record(AuditAction::Approved, 'attendance', $locked, reason: $note, actor: $actor, metadata: ['type' => $locked->type, 'automatic' => $auto ?: null]);
            AttendanceEvent::dispatch('attendance.timeoffice_approved', $locked->employee, $locked, ['type' => $locked->type, 'from' => $locked->from_date->toDateString(), 'to' => $locked->to_date->toDateString(), 'note' => $note]);

            return $locked;
        });

        $this->reprocess($request);

        return $request->refresh();
    }

    public function reject(TimeOfficeRequest $request, string $note, ?User $actor = null): TimeOfficeRequest
    {
        $actor ??= auth()->user();
        if (trim($note) === '') {
            throw new RuntimeException('Give the reason for rejecting.');
        }

        return DB::transaction(function () use ($request, $note, $actor) {
            $locked = $this->lock($request);
            if ($locked->status !== 'pending') {
                throw new RuntimeException('This request has already been decided.');
            }
            $this->assertNotOwn($locked, $actor);

            $locked->withAuditReason($note)->update(['status' => 'rejected', 'reviewed_by' => $actor?->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $this->audit->record(AuditAction::Rejected, 'attendance', $locked, reason: $note, actor: $actor, metadata: ['type' => $locked->type]);
            AttendanceEvent::dispatch('attendance.timeoffice_rejected', $locked->employee, $locked, ['type' => $locked->type, 'note' => $note]);

            return $locked;
        });
    }

    /**
     * Withdraw a pending request, or cancel an approved one. Under a policy that asks for it, cancelling an
     * approved request (by anyone without attendance.manage) only requests the cancellation; the effect stays
     * until an approver decides.
     */
    public function cancel(TimeOfficeRequest $request, string $reason, ?User $actor = null): TimeOfficeRequest
    {
        $actor ??= auth()->user();
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for cancelling.');
        }

        $result = DB::transaction(function () use ($request, $reason, $actor) {
            $locked = $this->lock($request);

            if ($locked->status === 'pending') {
                $locked->withAuditReason($reason)->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_at' => now(), 'cancelled_by' => $actor?->id]);
                $this->audit->record(AuditAction::Cancelled, 'attendance', $locked, reason: $reason, actor: $actor, metadata: ['type' => $locked->type, 'was' => 'pending']);
                AttendanceEvent::dispatch('attendance.timeoffice_cancelled', $locked->employee, $locked, ['type' => $locked->type, 'was_approved' => false]);

                return [$locked, false];
            }

            if ($locked->status !== 'approved') {
                throw new RuntimeException('Only pending or approved requests can be cancelled.');
            }
            // HROne setup parity (TO-6): a restricted holiday may not be cancelled by these roles once attendance is processed.
            if ($locked->type === 'restricted_holiday' && $locked->holiday_id) {
                $roles = (array) (Holiday::query()->with('calendar')->find($locked->holiday_id)?->calendar?->rh_cancel_locked_for ?? []);
                $processed = AttendanceRecord::query()->where('employee_id', $locked->employee_id)->whereDate('date', $locked->from_date->toDateString())->where('is_locked', true)->exists();
                if ($processed && in_array(RequestRules::role($locked->employee, $actor), $roles, true)) {
                    throw new RuntimeException('This restricted holiday cannot be cancelled once attendance is processed for payroll.');
                }
            }

            $policy = $this->policies->resolve('time_office', $locked->employee, $locked->from_date);
            $needsApproval = (bool) ($policy?->setting('cancellation_needs_approval') ?? true) && ! ($actor?->hasPermission('attendance.manage') ?? false);

            if ($needsApproval) {
                $locked->withAuditReason($reason)->update(['status' => 'cancel_requested', 'cancel_reason' => $reason, 'cancel_requested_at' => now()]);
                $this->audit->record(AuditAction::Submitted, 'attendance', $locked, reason: $reason, actor: $actor, metadata: ['type' => $locked->type, 'cancellation' => true]);
                AttendanceEvent::dispatch('attendance.timeoffice_cancel_requested', $locked->employee, $locked, ['type' => $locked->type, 'reason' => $reason]);

                return [$locked, false];
            }

            $this->revoke($locked, $reason, $actor);

            return [$locked, true];
        });

        [$request, $reprocess] = $result;
        if ($reprocess) {
            $this->reprocess($request);
        }

        return $request->refresh();
    }

    public function approveCancellation(TimeOfficeRequest $request, ?string $note = null, ?User $actor = null): TimeOfficeRequest
    {
        $actor ??= auth()->user();

        $request = DB::transaction(function () use ($request, $note, $actor) {
            $locked = $this->lock($request);
            if ($locked->status !== 'cancel_requested') {
                throw new RuntimeException('No cancellation is waiting on this request.');
            }
            $this->assertNotOwn($locked, $actor);
            $this->revoke($locked, $note ?? (string) $locked->cancel_reason, $actor);

            return $locked;
        });

        $this->reprocess($request);

        return $request->refresh();
    }

    public function rejectCancellation(TimeOfficeRequest $request, string $note, ?User $actor = null): TimeOfficeRequest
    {
        $actor ??= auth()->user();

        return DB::transaction(function () use ($request, $note, $actor) {
            $locked = $this->lock($request);
            if ($locked->status !== 'cancel_requested') {
                throw new RuntimeException('No cancellation is waiting on this request.');
            }
            $this->assertNotOwn($locked, $actor);

            $locked->withAuditReason($note)->update(['status' => 'approved', 'cancel_requested_at' => null]);
            $this->audit->record(AuditAction::Rejected, 'attendance', $locked, reason: $note, actor: $actor, metadata: ['type' => $locked->type, 'cancellation' => true]);
            AttendanceEvent::dispatch('attendance.timeoffice_approved', $locked->employee, $locked, ['type' => $locked->type, 'cancellation_rejected' => true, 'note' => $note]);

            return $locked;
        });
    }

    /** Days of the request that have already happened and should be recalculated now. @return list<string> */
    public function datesToReprocess(TimeOfficeRequest $request): array
    {
        $dates = [];
        $end = $request->to_date->copy()->min(now()->startOfDay());
        for ($day = $request->from_date->copy(); $day->lte($end); $day->addDay()) {
            $dates[] = $day->toDateString();
        }
        if ($request->type === 'week_off_change' && $request->swap_date !== null && $request->swap_date->lte(now()->startOfDay())) {
            $dates[] = $request->swap_date->toDateString();
        }

        return $dates;
    }

    private function reprocess(TimeOfficeRequest $request): void
    {
        if ($request->type === 'comp_off') {
            return; // a comp-off claim changes the leave balance, not the day worked
        }
        $request->loadMissing('employee');
        foreach ($this->datesToReprocess($request) as $date) {
            $this->processor->process($request->employee, $date);
        }
    }

    /** @return array<string, mixed> */
    private function applyEffects(TimeOfficeRequest $request, ?User $actor): array
    {
        if (! in_array($request->type, ['shift_change', 'week_off_change'], true)) {
            return $request->effects ?? [];
        }

        // Shift change: the shift on every day of the range. Week-off change: work the weekly off, take the other day off.
        $days = [];
        for ($day = $request->from_date->copy(); $day->lte($request->to_date); $day->addDay()) {
            $days[$day->toDateString()] = $request->shift_id;
        }
        if ($request->type === 'week_off_change' && $request->swap_date !== null) {
            $days[$request->swap_date->toDateString()] = null;
        }
        $label = $request->type === 'week_off_change' ? 'Week-off change' : 'Shift change';
        $overrides = [];
        foreach ($days as $date => $shiftId) {
            $existing = ShiftOverride::query()->where('employee_id', $request->employee_id)->whereDate('date', $date)->first();
            $overrides[$date] = $existing ? ['shift_id' => $existing->shift_id, 'source' => $existing->source, 'reason' => $existing->reason] : null;
            $override = $existing ?? new ShiftOverride(['employee_id' => $request->employee_id, 'date' => Carbon::parse($date)]);
            $override->fill(['shift_id' => $shiftId, 'source' => 'request', 'time_office_request_id' => $request->id, 'reason' => "{$label} request #{$request->id}", 'created_by' => $actor?->id])
                ->withAuditReason("Approved {$label} request #{$request->id}")->save();
        }

        return ['previous_overrides' => $overrides];
    }

    private function revoke(TimeOfficeRequest $request, string $reason, ?User $actor): void
    {
        $this->assertUnlocked($request->employee, $request->from_date, $request->to_date, $request->type === 'comp_off');

        if (in_array($request->type, ['shift_change', 'week_off_change'], true)) {
            foreach ($request->effects['previous_overrides'] ?? [] as $date => $previous) {
                $override = ShiftOverride::query()->where('employee_id', $request->employee_id)->whereDate('date', $date)->where('time_office_request_id', $request->id)->first();
                if ($override === null) {
                    continue; // replaced since by the roster: leave the newer decision alone
                }
                $previous === null
                    ? $override->withAuditReason('Shift change #'.$request->id.' cancelled')->delete()
                    : $override->fill(['shift_id' => $previous['shift_id'], 'source' => $previous['source'], 'reason' => $previous['reason'], 'time_office_request_id' => null])->withAuditReason('Shift change #'.$request->id.' cancelled')->save();
            }
        }

        $request->withAuditReason($reason)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor?->id, 'cancel_reason' => $request->cancel_reason ?? $reason]);
        $this->audit->record(AuditAction::Cancelled, 'attendance', $request, reason: $reason, actor: $actor, metadata: ['type' => $request->type, 'was' => 'approved']);
        // The leave domain reverses a comp-off credit here, and refuses (rolling back) when it has been used.
        AttendanceEvent::dispatch('attendance.timeoffice_cancelled', $request->employee, $request, ['type' => $request->type, 'was_approved' => true, 'reason' => $reason]);
    }

    private function needsApproval(Employee $employee, TimeOfficeRequest $request): bool
    {
        if ($request->type === 'short_leave') {
            return (bool) ($this->policies->resolve('short_leave', $employee, $request->from_date)?->setting('approval_required') ?? true);
        }

        return true;
    }

    // ---- per-type checks -------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function shortLeave(Employee $employee, Carbon $from, Carbon $to, array $data, ?User $requester = null): array
    {
        if (! $from->equalTo($to)) {
            throw new RuntimeException('Short leave is for one day.');
        }
        $policy = $this->policies->resolve('short_leave', $employee, $from);
        if ($policy !== null && $policy->setting('enabled', true) === false) {
            throw new RuntimeException('Short leave is not allowed under your short leave policy.');
        }

        [$start, $end, $minutes] = $this->span($data);
        $min = (int) ($policy?->setting('min_minutes') ?? 0);
        $max = (int) ($policy?->setting('max_minutes') ?? 120);
        if ($min > 0 && $minutes < $min) {
            throw new RuntimeException("Short leave must be at least {$min} minutes.");
        }
        if ($max > 0 && $minutes > $max) {
            throw new RuntimeException("Short leave can be at most {$max} minutes.");
        }
        // HROne parity CF-02: managers and HR raising it on behalf may have longer windows.
        $onBehalf = $requester !== null && $employee->user_id !== $requester->id;
        $window = (int) ($policy?->setting('window_days') ?? 7);
        if ($onBehalf && $requester->hasPermission('attendance.manage') && $policy?->setting('admin_window_days') !== null) {
            $window = (int) $policy->setting('admin_window_days');
        } elseif ($onBehalf && $policy?->setting('manager_window_days') !== null) {
            $window = (int) $policy->setting('manager_window_days');
        }
        $this->assertWithinWindow($from, $window, 'Short leave');
        // HROne setup parity (TO-3).
        $this->rules->assertFuture($policy, 'short_leave', $from, 'Short leave');
        $this->rules->assertNotProcessed($policy, 'short_leave', $employee, $from, $from, 'Short leave');
        $this->rules->assertNoBenefitsYet($employee, $from, $from, 'Short leave');

        if (! (bool) ($policy?->setting('multiple_per_day') ?? false) && TimeOfficeRequest::query()->where('employee_id', $employee->id)->where('type', 'short_leave')
            ->whereIn('status', ['pending', ...TimeOfficeRequest::EFFECTIVE])->whereDate('from_date', $from->toDateString())->exists()) {
            throw new RuntimeException('There is already a short leave on that day.');
        }

        return ['session' => 'hours', 'start_time' => $start, 'end_time' => $end, 'minutes' => $minutes];
    }

    /** @return array<string, mixed>|null effects to keep on the request: leave to take off the balance beyond the limit, under the policy */
    private function assertShortLeaveLimits(Employee $employee, Carbon $date, int $minutes): ?array
    {
        $policy = $this->policies->resolve('short_leave', $employee, $date);
        $month = TimeOfficeRequest::query()->where('employee_id', $employee->id)->where('type', 'short_leave')
            ->whereIn('status', ['pending', ...TimeOfficeRequest::EFFECTIVE])
            ->whereBetween('from_date', [$date->copy()->startOfMonth()->toDateString(), $date->copy()->endOfMonth()->toDateString()])
            ->get(['id', 'minutes']);

        $maxCount = (int) ($policy?->setting('max_per_month') ?? 2);
        $maxMinutes = (int) ($policy?->setting('max_minutes_per_month') ?? 0);
        $over = ($maxCount > 0 && $month->count() >= $maxCount) || ($maxMinutes > 0 && (int) $month->sum('minutes') + $minutes > $maxMinutes);
        if (! $over) {
            return null;
        }
        // HROne parity CF-02: beyond the limit the policy may allow the short leave and deduct leave instead.
        if (($policy?->setting('over_limit') ?? 'refuse') === 'deduct_leave' && filled($policy?->setting('deduct_leave_code'))) {
            return ['deduct' => ['leave_code' => (string) $policy->setting('deduct_leave_code'), 'days' => (float) ($policy->setting('deduct_days') ?? 0.5)]];
        }
        if ($maxCount > 0 && $month->count() >= $maxCount) {
            throw new RuntimeException("You can take at most {$maxCount} short leave(s) a month.");
        }
        throw new RuntimeException("Short leave is limited to {$maxMinutes} minutes a month.");
    }

    /** HROne parity CF-03: requests of a type per calendar month, under the time-office policy (0 = no limit). */
    private function assertMonthlyLimit(Employee $employee, string $type, Carbon $date, ?User $requester = null): void
    {
        $policy = $this->policies->resolve('time_office', $employee, $date);
        // HROne setup parity (TO-3): on-duty limits may leave managers or HR applying on behalf free.
        $role = RequestRules::role($employee, $requester);
        if ($type === 'on_duty' && $role !== 'employee' && RequestRules::isNo($policy?->setting("on_duty_limits_bind_{$role}s") ?? $policy?->setting("on_duty_limits_bind_{$role}"))) {
            return;
        }
        $count = fn (Carbon $from, Carbon $to) => TimeOfficeRequest::query()->where('employee_id', $employee->id)->where('type', $type)->whereIn('status', ['pending', ...TimeOfficeRequest::EFFECTIVE])
            ->whereBetween('from_date', [$from->toDateString(), $to->toDateString()])->count();
        foreach (['week' => [$date->copy()->startOfWeek(), $date->copy()->endOfWeek()], 'month' => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()]] as $period => [$start, $end]) {
            $max = (int) ($policy?->setting("{$type}_max_per_{$period}") ?? 0);
            if ($max > 0 && $count($start, $end) >= $max) {
                throw new RuntimeException('You can raise at most '.$max.' '.strtolower(TimeOfficeRequest::TYPES[$type]).' request(s) a '.$period.'.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function onDuty(Employee $employee, Carbon $from, Carbon $to, array $data, ?User $requester = null): array
    {
        $policy = $this->policies->resolve('time_office', $employee, $from);
        if ($policy !== null && $policy->setting('on_duty_allowed', true) === false) {
            throw new RuntimeException('On-duty requests are not allowed under your policy.');
        }
        // HROne setup parity (TO-3): on-duty types, future dates, week-offs / holidays, processed days, attachment.
        $types = array_values(array_filter((array) ($policy?->setting('on_duty_types') ?? [])));
        $category = trim((string) ($data['category'] ?? ''));
        if ($types !== [] && ! in_array($category, $types, true)) {
            throw new RuntimeException('Choose the on-duty type: '.implode(', ', $types).'.');
        }
        $this->rules->assertFuture($policy, 'on_duty', $to, 'On duty');
        $this->rules->assertDayTypes($policy, 'on_duty', $employee, $from, $to, 'On duty');
        $this->rules->assertNotProcessed($policy, 'on_duty', $employee, $from, $to, 'On duty');
        $this->rules->assertAttachment($policy, 'on_duty', (array) ($data['attachments'] ?? []), 'on-duty');
        $this->rules->assertNoBenefitsYet($employee, $from, $to, 'On duty');
        $session = (string) ($data['session'] ?? 'full');
        if (! in_array($session, ['full', 'first_half', 'second_half', 'hours'], true)) {
            throw new RuntimeException('Choose a full day, a half or hours.');
        }
        $days = $from->diffInDays($to) + 1;
        $maxDays = (int) ($policy?->setting('on_duty_max_days') ?? 0);
        if ($maxDays > 0 && $days > $maxDays) {
            throw new RuntimeException("An on-duty request can cover at most {$maxDays} day(s).");
        }
        $this->assertWithinWindow($from, (int) $this->rules->backdatedDays($policy, 'on_duty', RequestRules::role($employee, $requester), (int) ($policy?->setting('claim_window_days') ?? 30)), 'On duty');

        if ($session === 'hours') {
            if ($policy !== null && $policy->setting('on_duty_hours_allowed', true) === false) {
                throw new RuntimeException('Hourly on duty is not allowed under your policy.');
            }
            if ($days > 1) {
                throw new RuntimeException('Hourly on duty is for one day.');
            }
            [$start, $end, $minutes] = $this->span($data);

            return ['session' => 'hours', 'start_time' => $start, 'end_time' => $end, 'minutes' => $minutes, 'days' => null, 'category' => $category ?: null];
        }
        if ($session !== 'full' && $days > 1) {
            throw new RuntimeException('A half day on duty is for one day.');
        }

        return ['session' => $session, 'days' => $session === 'full' ? $days : 0.5, 'category' => $category ?: null];
    }

    /** @return array<string, mixed> */
    private function shiftChange(Employee $employee, Carbon $from, Carbon $to, array $data): array
    {
        $policy = $this->policies->resolve('time_office', $employee, $from);
        if ($policy !== null && $policy->setting('shift_change_allowed', true) === false) {
            throw new RuntimeException('Shift change requests are not allowed under your policy.');
        }
        $shift = Shift::query()->find($data['shift_id'] ?? null) ?? throw new RuntimeException('Choose the shift to work.');
        $status = $shift->status instanceof BackedEnum ? $shift->status->value : $shift->status;
        if ($status !== null && $status !== 'active') {
            throw new RuntimeException("The {$shift->name} shift is not active.");
        }
        // HROne setup parity (TO-3): the shifts employees may pick, backdated and forward-dated days.
        if ($shift->requestable === false) {
            throw new RuntimeException("The {$shift->name} shift cannot be requested.");
        }
        $allowedShifts = array_values(array_filter((array) ($policy?->setting('shift_change_shifts') ?? [])));
        if ($allowedShifts !== [] && ! in_array($shift->code, $allowedShifts, true)) {
            throw new RuntimeException("The {$shift->name} shift cannot be requested under your policy.");
        }
        $notice = (int) ($policy?->setting('shift_change_notice_days') ?? 0);
        $backdated = (int) ($policy?->setting('shift_change_backdated_days') ?? 0);
        $past = $from->lt(now()->startOfDay());
        if ($past && $backdated > 0) {
            $this->assertWithinWindow($from, $backdated, 'A shift change');
        } elseif ($from->lt(now()->startOfDay()->addDays($notice))) {
            throw new RuntimeException($notice > 0 ? "Shift changes need {$notice} day(s) notice." : 'A shift change cannot start in the past.');
        }
        $ahead = (int) ($policy?->setting('shift_change_future_days') ?? 0);
        if ($ahead > 0 && $to->gt(now()->startOfDay()->addDays($ahead))) {
            throw new RuntimeException("A shift change can be requested at most {$ahead} day(s) ahead.");
        }
        if ($from->diffInDays($to) > 62) {
            throw new RuntimeException('A shift change can cover at most two months; ask HR to change the roster instead.');
        }

        return ['shift_id' => $shift->id, 'days' => $from->diffInDays($to) + 1];
    }

    /**
     * HROne setup parity (TO-3): work a weekly off (`from_date`) and take a working day off instead (`swap_date`),
     * within the policy's backdated / forward-dated days. The day worked uses the shift given, else the shift of the
     * day given up.
     *
     * @return array<string, mixed>
     */
    private function weekOffChange(Employee $employee, Carbon $from, array $data): array
    {
        $policy = $this->policies->resolve('time_office', $employee, $from);
        if (! RequestRules::isYes($policy?->setting('week_off_change_allowed'))) {
            throw new RuntimeException('Week-off change requests are not allowed under your policy.');
        }
        $swap = Carbon::parse($data['swap_date'] ?? throw new RuntimeException('Choose the day to take off instead.'))->startOfDay();
        if ($swap->equalTo($from)) {
            throw new RuntimeException('Choose a different day to take off.');
        }
        $back = (int) ($policy->setting('week_off_change_backdated_days') ?? 0);
        $ahead = (int) ($policy->setting('week_off_change_future_days') ?? 0);
        foreach ([$from, $swap] as $day) {
            if ($day->lt(now()->startOfDay()) && ($back <= 0 || $day->lt(now()->startOfDay()->subDays($back)))) {
                throw new RuntimeException($back > 0 ? "A week-off change can go back at most {$back} day(s)." : 'A week-off change cannot be for a past day.');
            }
            if ($ahead > 0 && $day->gt(now()->startOfDay()->addDays($ahead))) {
                throw new RuntimeException("A week-off change can be requested at most {$ahead} day(s) ahead.");
            }
        }
        $worked = $this->shifts->resolve($employee, $from->copy());
        $given = $this->shifts->resolve($employee, $swap->copy());
        if (! $worked['weekly_off']) {
            throw new RuntimeException($from->format('d M Y').' is not one of your weekly offs.');
        }
        if ($given['weekly_off'] || $this->holidays->holidayOn($employee, $swap->copy()) !== null) {
            throw new RuntimeException($swap->format('d M Y').' is already a day off.');
        }
        $shift = isset($data['shift_id']) && $data['shift_id'] ? Shift::query()->find($data['shift_id']) : $given['shift'];
        if ($shift === null) {
            throw new RuntimeException('Choose the shift to work on the weekly off.');
        }

        return ['swap_date' => $swap, 'shift_id' => $shift->id, 'days' => 1];
    }

    /** @return array<string, mixed> */
    private function overtimeClaim(Employee $employee, Carbon $from, Carbon $to, array $data): array
    {
        if (! $from->equalTo($to)) {
            throw new RuntimeException('Claim overtime one day at a time.');
        }
        if ($from->gt(now()->startOfDay())) {
            throw new RuntimeException('Overtime can only be claimed for a day already worked.');
        }
        $policy = $this->policies->resolve('time_office', $employee, $from);
        if ($policy !== null && $policy->setting('overtime_claims_allowed', true) === false) {
            throw new RuntimeException('Overtime claims are not allowed under your policy.');
        }
        $minutes = (int) ($data['minutes'] ?? 0);
        if ($minutes <= 0 || $minutes > 960) {
            throw new RuntimeException('Claim between 1 minute and 16 hours of overtime.');
        }
        $this->assertWithinWindow($from, (int) ($policy?->setting('claim_window_days') ?? 30), 'Overtime');

        return ['session' => 'hours', 'minutes' => $minutes];
    }

    /** @return array<string, mixed> */
    private function compOff(Employee $employee, Carbon $from, Carbon $to): array
    {
        if (! $from->equalTo($to)) {
            throw new RuntimeException('Claim comp-off one worked day at a time.');
        }
        $policy = $this->policies->resolve('time_office', $employee, $from);
        if ($policy !== null && $policy->setting('comp_off_allowed', true) === false) {
            throw new RuntimeException('Comp-off claims are not allowed under your policy.');
        }
        $this->assertWithinWindow($from, (int) ($policy?->setting('claim_window_days') ?? 30), 'Comp-off');

        $record = AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', $from->toDateString())->first();
        if ($record === null || ! in_array('holiday_work', $record->exceptions ?? [], true)) {
            throw new RuntimeException('Comp-off can be claimed only for work on a holiday or weekly off.');
        }
        if ((int) $record->overtime_approved_minutes > 0) {
            throw new RuntimeException('Overtime was already approved for this day; claim one or the other.');
        }

        $fullDayBasis = (int) ($record->calculation_basis['full_day_minutes'] ?? 480);
        $full = (int) ($policy?->setting('comp_off_full_day_minutes') ?? $fullDayBasis);
        $half = (int) ($policy?->setting('comp_off_half_day_minutes') ?? intdiv($full, 2));
        $worked = (int) $record->worked_minutes;
        $days = $worked >= $full ? 1.0 : ($worked >= $half ? 0.5 : 0.0);
        if ($days <= 0) {
            throw new RuntimeException("You worked {$worked} minutes; a comp-off needs at least {$half}.");
        }
        $validity = (int) ($policy?->setting('comp_off_validity_days') ?? 90);

        return ['session' => $days >= 1 ? 'full' : 'first_half', 'days' => $days, 'minutes' => $worked, 'expires_on' => $validity > 0 ? $from->copy()->addDays($validity) : null];
    }

    /** @return array<string, mixed> */
    private function restrictedHoliday(Employee $employee, array $data): array
    {
        $holiday = Holiday::query()->find($data['holiday_id'] ?? null) ?? throw new RuntimeException('Choose the optional holiday.');
        if ($holiday->type !== 'optional') {
            throw new RuntimeException("{$holiday->name} is not an optional holiday.");
        }
        $calendar = $this->holidays->calendarFor($employee, $holiday->date);
        if ($calendar === null || $calendar->id !== $holiday->holiday_calendar_id) {
            throw new RuntimeException("{$holiday->name} is not on your holiday calendar.");
        }
        if (! $calendar->optional_requires_selection) {
            throw new RuntimeException('Optional holidays on your calendar are holidays for everyone; nothing to choose.');
        }
        // HROne setup parity (TO-6): a calendar may require a shift on the day.
        if ($calendar->rh_without_shift === false && ($this->shifts->resolve($employee, $holiday->date->copy())['shift'] ?? null) === null) {
            throw new RuntimeException("You have no shift on {$holiday->date->format('d M Y')}, so it cannot be taken as a restricted holiday.");
        }

        return ['holiday_id' => $holiday->id, 'from_date' => $holiday->date->copy(), 'to_date' => $holiday->date->copy(), 'days' => $holiday->is_half_day ? 0.5 : 1];
    }

    private function assertRestrictedHolidayQuota(Employee $employee, Holiday $holiday): void
    {
        $usage = $this->restrictedHolidayQuota($employee, $holiday);
        if ($usage !== null && $usage['taken'] >= $usage['quota']) {
            throw new RuntimeException("You have already chosen {$usage['taken']} of {$usage['quota']} restricted holiday(s) for {$usage['year']}.");
        }
    }

    /**
     * The restricted-holiday quota of the holiday's year for this employee and how many are chosen (pending or
     * approved), or null when the calendar sets no quota. Read by the request check and (HROne parity TX-1) by the
     * request's detail view ("chosen N of M").
     *
     * @return array{taken: int, quota: int, year: string}|null
     */
    public function restrictedHolidayQuota(Employee $employee, Holiday $holiday): ?array
    {
        $calendar = $holiday->calendar;
        $quota = $calendar?->restricted_holiday_quota;
        if ($quota === null) {
            return null;
        }
        // HROne setup parity (TO-6): the year is the calendar or the financial year, and a joiner's quota is prorated.
        [$start, $end, $label] = $this->holidayYear($holiday->date);
        $joined = $employee->joining_date;
        if ($joined !== null && $joined->between($start, $end)) {
            foreach ((array) ($calendar->rh_prorate_rules ?? []) as $rule) {
                $month = (int) $joined->month;
                [$from, $to] = [(int) ($rule['from_month'] ?? 0), (int) ($rule['to_month'] ?? 0)];
                if ($from > 0 && $to > 0 && ($from <= $to ? ($month >= $from && $month <= $to) : ($month >= $from || $month <= $to))) {
                    $quota = (int) ($rule['quota'] ?? $quota);
                    break;
                }
            }
        }
        $taken = TimeOfficeRequest::query()->where('employee_id', $employee->id)->where('type', 'restricted_holiday')
            ->whereIn('status', ['pending', ...TimeOfficeRequest::EFFECTIVE])
            ->whereBetween('from_date', [$start->toDateString(), $end->toDateString()])->count();

        return ['taken' => $taken, 'quota' => (int) $quota, 'year' => $label];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} the holiday year containing the date (organisation setting) */
    private function holidayYear(Carbon $date): array
    {
        if ($this->settings->get('attendance.holiday_year_basis', 'calendar') !== 'financial') {
            return [$date->copy()->startOfYear(), $date->copy()->endOfYear(), (string) $date->year];
        }
        $month = (int) config('peopleos.compliance.financial_year_start_month', 4);
        $start = Carbon::create($date->month >= $month ? $date->year : $date->year - 1, $month, 1)->startOfDay();

        return [$start, $start->copy()->addYear()->subDay(), $start->year.'-'.substr((string) ($start->year + 1), -2)];
    }

    // ---- shared guards ---------------------------------------------------------------------------------

    /** @return array{0: string, 1: string, 2: int} start, end, minutes */
    private function span(array $data): array
    {
        $start = $data['start_time'] ?? null;
        $end = $data['end_time'] ?? null;
        if (blank($start) || blank($end)) {
            throw new RuntimeException('Give the start and end time.');
        }
        $startAt = Carbon::parse((string) $start);
        $endAt = Carbon::parse((string) $end);
        $minutes = (int) $startAt->diffInMinutes($endAt, false);
        if ($minutes <= 0) {
            throw new RuntimeException('The end time must be after the start time.');
        }

        return [$startAt->format('H:i:s'), $endAt->format('H:i:s'), $minutes];
    }

    private function assertWithinWindow(Carbon $from, int $days, string $label): void
    {
        if ($days > 0 && $from->lt(now()->startOfDay()->subDays($days))) {
            throw new RuntimeException("{$label} can be requested up to {$days} day(s) back.");
        }
    }

    private function assertNoOverlap(Employee $employee, string $type, Carbon $from, Carbon $to): void
    {
        if (! in_array($type, self::ONE_PER_DAY, true)) {
            return;
        }
        $clash = TimeOfficeRequest::query()->where('employee_id', $employee->id)->where('type', $type)
            ->whereIn('status', ['pending', ...TimeOfficeRequest::EFFECTIVE])
            ->whereDate('from_date', '<=', $to->toDateString())->whereDate('to_date', '>=', $from->toDateString())
            ->first();
        if ($clash !== null) {
            throw new RuntimeException("A {$clash->typeLabel()} request already covers {$clash->from_date->format('d M Y')}.");
        }
    }

    private function assertUnlocked(Employee $employee, Carbon $from, Carbon $to, bool $balanceOnly = false): void
    {
        if ($balanceOnly || (bool) $this->settings->get('attendance.allow_corrections_after_lock', false)) {
            return;
        }
        $locked = AttendanceRecord::query()->where('employee_id', $employee->id)->where('is_locked', true)
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->exists();
        if ($locked) {
            throw new RuntimeException('Part of this period is locked for payroll.');
        }
    }

    private function assertNotOwn(TimeOfficeRequest $request, ?User $actor): void
    {
        if ($actor === null) {
            throw new RuntimeException('A time-office request is decided by a person.');
        }
        if (Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($request->employee_id)->value('user_id') === $actor->id) {
            throw new RuntimeException('You cannot decide your own request.');
        }
    }

    private function lock(TimeOfficeRequest $request): TimeOfficeRequest
    {
        $locked = TimeOfficeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
        $locked->load('employee');

        return $locked;
    }
}

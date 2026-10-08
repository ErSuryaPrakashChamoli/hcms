<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Regularisation and overtime review (Phase 2 §24–§26). Requests never touch the raw punches; an
 * approval records what the day looked like before and after recalculation. Reviews take a row
 * lock so two approvers cannot both decide the same request.
 */
final class Regularisations
{
    public function __construct(
        private readonly AttendanceProcessor $processor,
        private readonly PolicyResolver $policies,
        private readonly SettingsRepository $settings,
        private readonly AuditRecorder $audit,
    ) {}

    public function request(Employee $employee, Carbon|string $date, string $type, string $reason, Carbon|string|null $in = null, Carbon|string|null $out = null, ?User $requester = null): AttendanceRegularisation
    {
        $date = Carbon::parse($date)->startOfDay();
        $policy = $this->policies->resolve('attendance', $employee, $date);

        if ($policy && $policy->setting('regularisation_allowed', true) === false) {
            throw new RuntimeException('Regularisation is not allowed under the applicable attendance policy.');
        }

        $window = (int) ($policy?->setting('regularisation_window_days') ?? $this->settings->get('attendance.regularisation.window_days', 7));

        if ($date->lt(now()->startOfDay()->subDays($window))) {
            throw new RuntimeException("Regularisation is only allowed within {$window} days.");
        }

        if (AttendanceRegularisation::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->where('status', 'pending')->exists()) {
            throw new RuntimeException('A request for this date is already pending.');
        }

        if (AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->where('is_locked', true)->exists()) {
            throw new RuntimeException('This day is locked for payroll.');
        }

        $regularisation = new AttendanceRegularisation([
            'employee_id' => $employee->id,
            'date' => $date,
            'type' => $type,
            'requested_in' => $in ? Carbon::parse($in) : null,
            'requested_out' => $out ? Carbon::parse($out) : null,
            'reason' => $reason,
            'status' => 'pending',
            'requested_by' => ($requester ?? auth()->user())?->id,
        ]);
        $regularisation->withAuditReason($reason)->save();
        $this->audit->record(AuditAction::RegularisationRequested, 'attendance', $regularisation, reason: $reason, metadata: ['date' => $date->toDateString(), 'type' => $type]);
        AttendanceEvent::dispatch('attendance.regularisation_requested', $employee, $regularisation, ['date' => $date->toDateString(), 'type' => $type, 'reason' => $reason]);

        return $regularisation;
    }

    public function approve(AttendanceRegularisation $regularisation, ?string $note = null, ?User $actor = null): AttendanceRegularisation
    {
        $this->review($regularisation, 'approved', $note, $actor);
        $record = $this->processor->process($regularisation->employee, $regularisation->date);
        $regularisation->update(['resulting_snapshot' => $this->snapshot($record)]);
        AttendanceEvent::dispatch('attendance.regularisation_approved', $regularisation->employee, $regularisation, ['date' => $regularisation->date->toDateString(), 'note' => $note]);

        return $regularisation->refresh();
    }

    public function reject(AttendanceRegularisation $regularisation, string $note, ?User $actor = null): AttendanceRegularisation
    {
        $this->review($regularisation, 'rejected', $note, $actor);
        AttendanceEvent::dispatch('attendance.regularisation_rejected', $regularisation->employee, $regularisation, ['date' => $regularisation->date->toDateString(), 'note' => $note]);

        return $regularisation->refresh();
    }

    /** The requester withdraws a pending request. */
    public function cancel(AttendanceRegularisation $regularisation, ?string $reason = null): AttendanceRegularisation
    {
        return DB::transaction(function () use ($regularisation, $reason) {
            $locked = AttendanceRegularisation::query()->whereKey($regularisation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new RuntimeException('Only pending requests can be cancelled.');
            }

            $locked->withAuditReason($reason)->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $locked->loadMissing('employee');
            AttendanceEvent::dispatch('attendance.regularisation_cancelled', $locked->employee, $locked, ['date' => $locked->date->toDateString()]);

            return $locked;
        });
    }

    public function approveOvertime(AttendanceRecord $record, int $minutes, ?string $note = null): AttendanceRecord
    {
        return DB::transaction(function () use ($record, $minutes, $note) {
            $record = AttendanceRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($record->is_locked) {
                throw new RuntimeException('This day is locked for payroll.');
            }

            if ($record->overtime_minutes <= 0) {
                throw new RuntimeException('There is no calculated overtime on this day.');
            }

            $before = (int) $record->overtime_approved_minutes;
            $minutes = max(0, min($minutes, $record->overtime_minutes));
            $exceptions = array_values(array_diff($record->exceptions ?? [], ['overtime']));

            $record->withAuditReason($note)->update([
                'overtime_approved_minutes' => $minutes,
                'overtime_status' => 'approved',
                'overtime_review_note' => $note,
                'overtime_reviewed_by' => auth()->id(),
                'overtime_reviewed_at' => now(),
                'exceptions' => $exceptions === [] ? null : $exceptions,
            ]);
            $this->audit->record(AuditAction::OvertimeApproved, 'attendance', $record, changes: [['field' => 'overtime_approved_minutes', 'before' => $before, 'after' => $minutes]], reason: $note);
            $record->loadMissing('employee');
            AttendanceEvent::dispatch('attendance.overtime_approved', $record->employee, $record, ['date' => $record->date->toDateString(), 'minutes' => $minutes]);

            return $record;
        });
    }

    public function rejectOvertime(AttendanceRecord $record, string $reason): AttendanceRecord
    {
        return DB::transaction(function () use ($record, $reason) {
            $record = AttendanceRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($record->is_locked) {
                throw new RuntimeException('This day is locked for payroll.');
            }

            $before = (int) $record->overtime_approved_minutes;
            $exceptions = array_values(array_diff($record->exceptions ?? [], ['overtime']));

            $record->withAuditReason($reason)->update([
                'overtime_approved_minutes' => 0,
                'overtime_status' => 'rejected',
                'overtime_review_note' => $reason,
                'overtime_reviewed_by' => auth()->id(),
                'overtime_reviewed_at' => now(),
                'exceptions' => $exceptions === [] ? null : $exceptions,
            ]);
            $this->audit->record(AuditAction::OvertimeRejected, 'attendance', $record, changes: [['field' => 'overtime_approved_minutes', 'before' => $before, 'after' => 0]], reason: $reason);
            $record->loadMissing('employee');
            AttendanceEvent::dispatch('attendance.overtime_rejected', $record->employee, $record, ['date' => $record->date->toDateString(), 'reason' => $reason]);

            return $record;
        });
    }

    /**
     * Phase 12: the review records its actor (explicit, else the signed-in user) and the employee
     * concerned never reviews their own regularisation.
     */
    private function review(AttendanceRegularisation $regularisation, string $status, ?string $note, ?User $actor = null): void
    {
        $actor ??= auth()->user();
        if ($actor === null) {
            throw new RuntimeException('A regularisation is reviewed by a person.');
        }
        DB::transaction(function () use ($regularisation, $status, $note, $actor) {
            $locked = AttendanceRegularisation::query()->whereKey($regularisation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new RuntimeException('This request has already been reviewed.');
            }
            if (Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($locked->employee_id)->value('user_id') === $actor->id) {
                throw new RuntimeException('You cannot review your own regularisation.');
            }

            $regularisation->loadMissing('employee');
            $original = AttendanceRecord::query()->where('employee_id', $regularisation->employee_id)->whereDate('date', $regularisation->date->toDateString())->first();

            $regularisation->withAuditReason($note)->update([
                'status' => $status,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $note,
                'original_snapshot' => $status === 'approved' ? $this->snapshot($original) : $regularisation->original_snapshot,
            ]);
            $this->audit->record($status === 'approved' ? AuditAction::RegularisationApproved : AuditAction::RegularisationRejected, 'attendance', $regularisation, reason: $note, actor: $actor, metadata: ['date' => $regularisation->date->toDateString(), 'type' => $regularisation->type]);
        });
    }

    /** @return array<string, mixed>|null */
    private function snapshot(?AttendanceRecord $record): ?array
    {
        if ($record === null) {
            return null;
        }

        return ['status' => $record->status, 'first_in' => $record->first_in?->toIso8601String(), 'last_out' => $record->last_out?->toIso8601String(), 'worked_minutes' => $record->worked_minutes, 'late_minutes' => $record->late_minutes, 'early_leave_minutes' => $record->early_leave_minutes, 'overtime_minutes' => $record->overtime_minutes, 'exceptions' => $record->exceptions, 'calculation_version' => $record->calculation_version];
    }
}

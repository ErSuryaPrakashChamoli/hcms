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
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use RuntimeException;

/** Regularisation requests (§23, §27) and overtime approval (§28). */
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

        AttendanceEvent::dispatch('attendance.regularisation_requested', $employee, $regularisation, ['date' => $date->toDateString(), 'type' => $type, 'reason' => $reason]);

        return $regularisation;
    }

    public function approve(AttendanceRegularisation $regularisation, ?string $note = null): AttendanceRegularisation
    {
        $this->review($regularisation, 'approved', $note);

        $this->processor->process($regularisation->employee, $regularisation->date);
        AttendanceEvent::dispatch('attendance.regularisation_approved', $regularisation->employee, $regularisation, ['date' => $regularisation->date->toDateString(), 'note' => $note]);

        return $regularisation;
    }

    public function reject(AttendanceRegularisation $regularisation, string $note): AttendanceRegularisation
    {
        $this->review($regularisation, 'rejected', $note);
        AttendanceEvent::dispatch('attendance.regularisation_rejected', $regularisation->employee, $regularisation, ['date' => $regularisation->date->toDateString(), 'note' => $note]);

        return $regularisation;
    }

    public function approveOvertime(AttendanceRecord $record, int $minutes, ?string $note = null): AttendanceRecord
    {
        if ($record->is_locked) {
            throw new RuntimeException('This day is locked for payroll.');
        }

        $minutes = max(0, min($minutes, $record->overtime_minutes));
        $exceptions = array_values(array_diff($record->exceptions ?? [], ['overtime']));

        $record->withAuditReason($note)->update(['overtime_approved_minutes' => $minutes, 'exceptions' => $exceptions === [] ? null : $exceptions]);
        $this->audit->record(AuditAction::Approved, 'attendance', $record, changes: [['field' => 'overtime_approved_minutes', 'before' => $record->getOriginal('overtime_approved_minutes'), 'after' => $minutes]], reason: $note);

        return $record;
    }

    private function review(AttendanceRegularisation $regularisation, string $status, ?string $note): void
    {
        if ($regularisation->status !== 'pending') {
            throw new RuntimeException('This request has already been reviewed.');
        }

        $regularisation->loadMissing('employee');
        $regularisation->withAuditReason($note)->update([
            'status' => $status,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $this->audit->record($status === 'approved' ? AuditAction::Approved : AuditAction::Rejected, 'attendance', $regularisation, reason: $note);
    }
}

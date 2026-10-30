<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\AttendanceOverride;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HR's word on a day (HROne parity HP-1 "change attendance status" and "override working hours"). The
 * override is an input of the engine, audited with its reason; the day is recalculated at once.
 */
final class AttendanceOverrides
{
    public function __construct(
        private readonly AttendanceProcessor $processor,
        private readonly SettingsRepository $settings,
    ) {}

    public function set(Employee $employee, Carbon|string $date, ?string $status, ?int $workedMinutes, string $reason, ?User $actor = null): AttendanceRecord
    {
        $date = Carbon::parse($date)->startOfDay();
        if ($status === null && $workedMinutes === null) {
            throw new RuntimeException('Set a status, worked minutes, or both.');
        }
        if ($status !== null && ! in_array($status, AttendanceOverride::STATUSES, true)) {
            throw new RuntimeException("Unknown attendance status [{$status}].");
        }
        if ($workedMinutes !== null && ($workedMinutes < 0 || $workedMinutes > 1440)) {
            throw new RuntimeException('Worked minutes must be between 0 and 1440.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for the override.');
        }
        if ($date->gt(now()->startOfDay())) {
            throw new RuntimeException('A day can be overridden once it has started.');
        }
        $this->assertUnlocked($employee, $date);
        $actor ??= auth()->user();

        DB::transaction(function () use ($employee, $date, $status, $workedMinutes, $reason, $actor) {
            $override = AttendanceOverride::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->lockForUpdate()->first()
                ?? new AttendanceOverride(['employee_id' => $employee->id, 'date' => $date->copy()]);
            $override->fill(['status' => $status, 'worked_minutes' => $workedMinutes, 'reason' => $reason, 'created_by' => $actor?->id])->withAuditReason($reason)->save();
            AttendanceEvent::dispatch('attendance.override_set', $employee, $override, ['date' => $date->toDateString(), 'status' => $status, 'worked_minutes' => $workedMinutes, 'reason' => $reason]);
        });

        return $this->processor->process($employee, $date);
    }

    public function clear(AttendanceOverride $override, string $reason): AttendanceRecord
    {
        $override->loadMissing('employee');
        $this->assertUnlocked($override->employee, $override->date);
        $employee = $override->employee;
        $date = $override->date->copy();
        $override->withAuditReason($reason)->delete();

        return $this->processor->process($employee, $date);
    }

    private function assertUnlocked(Employee $employee, Carbon $date): void
    {
        if ((bool) $this->settings->get('attendance.allow_corrections_after_lock', false)) {
            return;
        }
        if (AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->where('is_locked', true)->exists()) {
            throw new RuntimeException('This day is locked for payroll.');
        }
    }
}

<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\AttendanceRecord;

/**
 * Payroll-ready view of one attendance day (Phase 2 §41). Quantities only — no money.
 *
 * @property-read array<string, mixed> $adjustments
 */
final class AttendanceDay
{
    public function __construct(
        public readonly int $employeeId,
        public readonly string $date,
        public readonly string $status,
        public readonly int $scheduledMinutes,
        public readonly int $workedMinutes,
        public readonly int $breakMinutes,
        public readonly int $lateMinutes,
        public readonly int $earlyMinutes,
        public readonly int $overtimeMinutes,
        public readonly int $approvedOvertimeMinutes,
        public readonly string $overtimeStatus,
        public readonly bool $isPaidDay,
        public readonly bool $isHalfDay,
        public readonly bool $isRegularised,
        public readonly bool $isLocked,
        public readonly ?string $calculationVersion,
        public readonly array $adjustments,
    ) {}

    public static function fromRecord(AttendanceRecord $record): self
    {
        $status = (string) $record->status;
        $paidStatuses = ['present', 'half_day', 'weekly_off', 'holiday', 'leave', 'wfh', 'on_duty', 'field_duty'];

        return new self(
            employeeId: (int) $record->employee_id,
            date: $record->date->toDateString(),
            status: $status,
            scheduledMinutes: (int) $record->scheduled_minutes,
            workedMinutes: (int) $record->worked_minutes,
            breakMinutes: (int) $record->break_minutes,
            lateMinutes: (int) $record->late_minutes,
            earlyMinutes: (int) $record->early_leave_minutes,
            overtimeMinutes: (int) $record->overtime_minutes,
            approvedOvertimeMinutes: (int) $record->overtime_approved_minutes,
            overtimeStatus: (string) ($record->overtime_status ?? 'none'),
            isPaidDay: in_array($status, $paidStatuses, true),
            isHalfDay: $status === 'half_day' || (bool) $record->is_half_day_leave,
            isRegularised: (bool) $record->is_regularised,
            isLocked: (bool) $record->is_locked,
            calculationVersion: $record->calculation_version,
            adjustments: array_filter(['leave_request_id' => $record->leave_request_id, 'half_day_leave' => $record->is_half_day_leave ?: null, 'regularised' => $record->is_regularised ?: null, 'holiday' => $record->holiday_name]),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId, 'date' => $this->date, 'status' => $this->status,
            'scheduled_minutes' => $this->scheduledMinutes, 'worked_minutes' => $this->workedMinutes, 'break_minutes' => $this->breakMinutes,
            'late_minutes' => $this->lateMinutes, 'early_minutes' => $this->earlyMinutes,
            'overtime_minutes' => $this->overtimeMinutes, 'approved_overtime_minutes' => $this->approvedOvertimeMinutes, 'overtime_status' => $this->overtimeStatus,
            'is_paid_day' => $this->isPaidDay, 'is_half_day' => $this->isHalfDay, 'is_regularised' => $this->isRegularised, 'is_locked' => $this->isLocked,
            'calculation_version' => $this->calculationVersion, 'adjustments' => $this->adjustments,
        ];
    }
}

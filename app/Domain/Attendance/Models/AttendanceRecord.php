<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Leave\Models\LeaveRequest;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The computed day (§23). */
#[Fillable(['tenant_id', 'employee_id', 'date', 'shift_id', 'scheduled_start', 'scheduled_end', 'scheduled_minutes', 'status', 'first_in', 'last_out', 'worked_minutes', 'break_minutes', 'late_minutes', 'early_leave_minutes', 'overtime_minutes', 'overtime_approved_minutes', 'overtime_status', 'overtime_review_note', 'overtime_reviewed_by', 'overtime_reviewed_at', 'is_regularised', 'is_locked', 'finalized_at', 'leave_request_id', 'is_half_day_leave', 'exceptions', 'holiday_name', 'timezone', 'calculation_version', 'calculation_basis', 'processed_at', 'note'])]
class AttendanceRecord extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'first_in' => 'datetime',
            'last_out' => 'datetime',
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'overtime_approved_minutes' => 'integer',
            'is_regularised' => 'boolean',
            'is_locked' => 'boolean',
            'is_half_day_leave' => 'boolean',
            'exceptions' => 'array',
            'processed_at' => 'datetime',
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'scheduled_minutes' => 'integer',
            'break_minutes' => 'integer',
            'overtime_reviewed_at' => 'datetime',
            'finalized_at' => 'datetime',
            'calculation_basis' => 'array',
        ];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return 'Attendance '.$this->date?->toDateString();
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'processed_at', 'calculation_basis'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function hasExceptions(): bool
    {
        return ! empty($this->exceptions);
    }

    public function statusLabel(): string
    {
        return config("peopleos.attendance.statuses.{$this->status}", $this->status);
    }
}

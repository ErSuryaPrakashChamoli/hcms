<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveRequest;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The computed day (§23). */
#[Fillable(['tenant_id', 'employee_id', 'date', 'shift_id', 'status', 'first_in', 'last_out', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'overtime_minutes', 'overtime_approved_minutes', 'is_regularised', 'is_locked', 'leave_request_id', 'is_half_day_leave', 'exceptions', 'holiday_name', 'processed_at', 'note'])]
class AttendanceRecord extends Model
{
    use Auditable, BelongsToTenant;

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
        return [...config('peopleos.audit.ignored_attributes', []), 'processed_at'];
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

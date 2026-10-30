<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A late-mark penalty (HP-1): every Nth late mark in a month (attendance policy `late_marks_threshold`) applies
 * the policy's `late_mark_action` on the day that crossed the threshold. `days` is the loss-of-pay quantity the
 * attendance output reports for that day (0 for a warning); payroll prices it, attendance never does.
 */
#[Fillable(['tenant_id', 'employee_id', 'date', 'month', 'rule', 'action', 'days', 'late_count', 'record_ids', 'status', 'waived_by', 'waive_reason'])]
class AttendancePenalty extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const ACTIONS = ['warn' => 'Warning', 'half_day' => 'Half day', 'lop' => 'Full day'];

    protected $attributes = ['status' => 'applied', 'rule' => 'late_marks'];

    protected function casts(): array
    {
        return ['date' => 'date', 'days' => 'decimal:2', 'late_count' => 'integer', 'record_ids' => 'array'];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return 'Late-mark penalty '.$this->date?->toDateString();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }
}

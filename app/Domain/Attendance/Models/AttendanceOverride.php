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
 * An HR decision about one day (HP-1 "change attendance status" / "override working hours"): the status and/or
 * worked minutes the engine must use. Approved full-day leave still wins; everything else yields to it.
 */
#[Fillable(['tenant_id', 'employee_id', 'date', 'status', 'worked_minutes', 'reason', 'created_by'])]
class AttendanceOverride extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['present', 'absent', 'half_day', 'weekly_off', 'holiday', 'wfh', 'on_duty', 'field_duty'];

    protected function casts(): array
    {
        return ['date' => 'date', 'worked_minutes' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return 'Attendance override '.$this->date?->toDateString();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

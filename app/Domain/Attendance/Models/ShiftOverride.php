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
 * The shift an employee works on one date, overriding the schedule (HP-1 day-level roster). Written by the
 * roster, by an approved shift-change request or by an import. A null shift makes the day a weekly off.
 */
#[Fillable(['tenant_id', 'employee_id', 'date', 'shift_id', 'source', 'time_office_request_id', 'reason', 'created_by'])]
class ShiftOverride extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $table = 'attendance_shift_overrides';

    public const SOURCES = ['roster' => 'Roster', 'request' => 'Shift change request', 'import' => 'Import'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return 'Shift on '.$this->date?->toDateString();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TimeOfficeRequest::class, 'time_office_request_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
